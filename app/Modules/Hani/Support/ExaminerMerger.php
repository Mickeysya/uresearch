<?php

namespace App\Modules\Hani\Support;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Hani\Models\Examiner;
use App\Modules\Hani\Models\ExaminerEmailProposal;
use App\Modules\Hani\Models\ExaminerMerge;
use App\Modules\Hani\Models\ExaminerNomination;
use App\Modules\Hani\Models\ExaminerReviewItem;
use App\Modules\Hani\Models\ExaminerSubstitutionRequest;
use App\Modules\Hani\Models\ReVivaEvaluation;
use App\Modules\Hani\Models\ReVivaStaffDocument;
use App\Modules\Hani\Models\VivaExaminerProgress;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Same person": two pool records become one. Nothing is deleted. The other
 * record goes Unavailable ("Duplicate of ..."), and everything current moves
 * to the record kept:
 *
 *   - seats on pending and released panels, logged in the seat history. If the
 *     panel already had the kept record too, the person keeps the main seat
 *     and the backup seat empties, since nobody may hold two seats;
 *   - viva progress, re-viva letters and evaluations, which are matched to
 *     seats by examiner id and would otherwise stop counting;
 *   - the tie-up and the gap (the later date wins: it is one person's time);
 *   - expertise, publications and any blank details on the kept record, plus
 *     the email if the kept record has no working one.
 *
 * Rejected lists and superseded rows stay as they were: they are history.
 *
 * A merge never bypasses a rule. If the kept record could not sit on one of
 * the panels (another department's internal, a blocking conflict of interest),
 * it refuses and names the panel. Being Assigned or on gap is no bar here,
 * because this is the same person who already holds the seat.
 */
class ExaminerMerger
{
    public function __construct(
        protected PanelSeats $seats,
        protected ConflictOfInterest $coi,
        protected Departments $departments,
        protected ExaminerContact $contact,
    ) {}

    public function merge(Examiner $keep, Examiner $duplicate, User $actor): ExaminerMerge
    {
        if ($keep->id === $duplicate->id) {
            throw ValidationException::withMessages(['keep_id' => 'Choose two different records.']);
        }

        return DB::transaction(function () use ($keep, $duplicate, $actor) {
            $locked = Examiner::whereIn('id', [$keep->id, $duplicate->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $keep = $locked[$keep->id];
            $duplicate = $locked[$duplicate->id];

            $this->assertMergeable($keep, $duplicate);

            $nominations = $this->panelsHolding($duplicate);
            $this->assertKeptCanSit($keep, $nominations);

            $moved = [];

            foreach ($nominations as $nomination) {
                $moved = array_merge($moved, $this->moveSeats($nomination, $keep, $duplicate, $actor));
            }

            $this->moveLinkedRows($nominations, $keep, $duplicate);
            $this->reconcileRequests($nominations);

            $summary = [
                'seats' => $moved,
                'keywords' => $this->mergeKeywords($keep, $duplicate),
                'publications' => $this->mergePublications($keep, $duplicate),
                'details' => $this->fillBlanks($keep, $duplicate),
                'email_moved' => false,
                'previous_unavailable_reason' => $duplicate->unavailable_reason,
                'letters_under_old_record' => $this->lettersUnderOldRecord($nominations, $duplicate),
            ];

            $duplicate->update([
                'is_active' => false,
                'unavailable_reason' => "Duplicate of {$keep->name} (#{$keep->id})",
                'assigned_until' => null,
            ]);

            $summary['email_moved'] = $this->moveEmail($keep, $duplicate, $actor);

            $merge = ExaminerMerge::create([
                'kept_id' => $keep->id,
                'duplicate_id' => $duplicate->id,
                'merged_by_id' => $actor->id,
                'summary' => $summary,
            ]);

            activity('examiner')->causedBy($actor)->performedOn($keep)
                ->withProperties(['action' => 'merged', 'duplicate_id' => $duplicate->id] + $summary)
                ->log("Merged {$duplicate->name} (#{$duplicate->id}) into {$keep->name} (#{$keep->id})");

            return $merge;
        });
    }

    protected function assertMergeable(Examiner $keep, Examiner $duplicate): void
    {
        if ($keep->type !== $duplicate->type) {
            throw ValidationException::withMessages(['keep_id' => 'An internal and an external record cannot be merged.']);
        }

        if (ExaminerMerge::whereIn('duplicate_id', [$keep->id, $duplicate->id])->exists()) {
            throw ValidationException::withMessages(['keep_id' => 'One of these records has already been merged.']);
        }

        if (! $keep->is_active) {
            throw ValidationException::withMessages(['keep_id' => "{$keep->name} is marked Unavailable, so seats cannot move to that record. Keep the other one, or reactivate it first."]);
        }
    }

    /** @return Collection<int, ExaminerNomination> pending and released panels naming this record */
    protected function panelsHolding(Examiner $duplicate): Collection
    {
        return ExaminerNomination::with('application.student')
            ->whereHas('application', fn ($q) => $q->where('module_type', 'examiner_nomination')
                ->whereIn('status', [Application::STATUS_PENDING, Application::STATUS_APPROVED]))
            ->where(function ($q) use ($duplicate) {
                foreach (array_keys(ExaminerNomination::SLOTS) as $seat) {
                    $q->orWhere($seat, $duplicate->id);
                }
            })
            ->lockForUpdate()
            ->get();
    }

    protected function assertKeptCanSit(Examiner $keep, Collection $nominations): void
    {
        foreach ($nominations as $nomination) {
            $student = $nomination->application?->student;

            if (! $student) {
                continue;
            }

            if ($keep->type === Examiner::TYPE_INTERNAL && ! $this->departments->same($keep->department, $student->department)) {
                throw ValidationException::withMessages(['keep_id' => "{$keep->name} is in {$keep->department}, but application #{$nomination->application_id} needs an internal examiner from {$student->department}. Keep the other record, or fix the department first."]);
            }

            if ($this->coi->blocks($flags = $this->coi->flagsFor($keep, $student))) {
                throw ValidationException::withMessages(['keep_id' => 'Application #'.$nomination->application_id.': '.collect($flags)->firstWhere('level', ConflictOfInterest::BLOCK)['message']]);
            }
        }
    }

    /** @return array<int, array{application_id: int, seat: string}> */
    protected function moveSeats(ExaminerNomination $nomination, Examiner $keep, Examiner $duplicate, User $actor): array
    {
        $reason = "Same person as {$duplicate->name} (#{$duplicate->id}); record merged.";
        $moved = [];

        foreach (array_keys(ExaminerNomination::SLOTS) as $seat) {
            if ($nomination->{$seat} !== $duplicate->id) {
                continue;
            }

            $keptSeat = collect(array_keys(ExaminerNomination::SLOTS))->first(fn ($s) => $nomination->{$s} === $keep->id);

            if ($keptSeat === null) {
                $this->seats->reassign($nomination, $seat, $keep, $reason, $actor);
            } elseif (array_key_exists($seat, PanelSeats::MAINS)) {
                // The duplicate held the main seat: the person keeps it, and
                // the backup they were also holding empties.
                $this->seats->reassign($nomination, $seat, $keep, $reason, $actor);
                $this->seats->reassign($nomination, $keptSeat, null, "{$keep->name} now holds the main seat; one person cannot hold two.", $actor);
            } else {
                $this->seats->reassign($nomination, $seat, null, "{$duplicate->name} is {$keep->name}, who already holds a seat on this panel.", $actor);
            }

            $moved[] = ['application_id' => $nomination->application_id, 'seat' => $seat];
        }

        return $moved;
    }

    /** Rows matched to a seat by examiner id, plus the work still open on the record. */
    protected function moveLinkedRows(Collection $nominations, Examiner $keep, Examiner $duplicate): void
    {
        $progress = VivaExaminerProgress::whereIn('nomination_id', $nominations->pluck('id'))
            ->where('examiner_id', $duplicate->id)
            ->whereNull('superseded_at')
            ->get();

        foreach ($progress as $row) {
            // Unique per nomination, seat and examiner. A row already there
            // means the kept record sat here before; sync() then supersedes this one.
            $taken = VivaExaminerProgress::where('nomination_id', $row->nomination_id)
                ->where('seat', $row->seat)->where('examiner_id', $keep->id)->exists();

            if (! $taken) {
                $row->update(['examiner_id' => $keep->id]);
            }
        }

        ReVivaEvaluation::where('examiner_id', $duplicate->id)->update(['examiner_id' => $keep->id]);
        ReVivaStaffDocument::where('examiner_id', $duplicate->id)->update(['examiner_id' => $keep->id]);
        ExaminerEmailProposal::pending()->where('examiner_id', $duplicate->id)->update(['examiner_id' => $keep->id]);
        ExaminerReviewItem::open()->where('examiner_id', $duplicate->id)->update(['examiner_id' => $keep->id]);
    }

    /** Open requests follow the seat; a seat the merge emptied has nothing left to replace. */
    protected function reconcileRequests(Collection $nominations): void
    {
        foreach ($nominations as $nomination) {
            ExaminerSubstitutionRequest::open()->where('nomination_id', $nomination->id)->get()
                ->each(function (ExaminerSubstitutionRequest $request) use ($nomination) {
                    $holder = $nomination->{$request->seat};

                    $request->update($holder
                        ? ['examiner_id' => $holder]
                        : ['status' => ExaminerSubstitutionRequest::CLOSED, 'resolved_at' => now()]);
                });
        }
    }

    protected function mergeKeywords(Examiner $keep, Examiner $duplicate): int
    {
        $have = $keep->expertiseKeywords()->pluck('keyword')->all();
        $new = array_diff($duplicate->expertiseKeywords()->pluck('keyword')->all(), $have);

        foreach ($new as $keyword) {
            $keep->expertiseKeywords()->create(['keyword' => $keyword]);
        }

        return count($new);
    }

    protected function mergePublications(Examiner $keep, Examiner $duplicate): int
    {
        $key = fn ($p) => mb_strtolower(trim($p->title)).'|'.$p->year;
        $have = $keep->publications()->get()->map($key)->all();
        $added = 0;

        foreach ($duplicate->publications()->get() as $publication) {
            if (! in_array($key($publication), $have, true)) {
                $keep->publications()->create($publication->only(['title', 'year', 'coauthors']));
                $added++;
            }
        }

        return $added;
    }

    /**
     * One person's record: blanks are filled, what is already there stays, and
     * the tie-up and gap take the later date. The conflict-of-interest fields
     * are among these, so a merge cannot lose a warning.
     *
     * @return array<int, string> the blank fields that were filled
     */
    protected function fillBlanks(Examiner $keep, Examiner $duplicate): array
    {
        $changes = [];

        foreach (array_merge(['expertise', 'faculty', 'first_examination_date'], Examiner::EXTERNAL_FIELDS) as $field) {
            if (blank($keep->getRawOriginal($field)) && filled($duplicate->getRawOriginal($field))) {
                $changes[$field] = $duplicate->getRawOriginal($field);
            }
        }

        $filled = array_keys($changes);

        if (filled($keep->expertise) && filled($duplicate->expertise)
            && ! str_contains(mb_strtolower($keep->expertise), mb_strtolower($duplicate->expertise))) {
            $changes['expertise'] = $keep->expertise.'; '.$duplicate->expertise;
        }

        foreach (['assigned_until', 'last_examination_date'] as $date) {
            if ($duplicate->{$date} && (! $keep->{$date} || $duplicate->{$date}->gt($keep->{$date}))) {
                $changes[$date] = $duplicate->{$date};
            }
        }

        if ($duplicate->first_examination_date && $keep->first_examination_date
            && $duplicate->first_examination_date->lt($keep->first_examination_date)) {
            $changes['first_examination_date'] = $duplicate->first_examination_date;
        }

        if ($changes !== []) {
            $keep->update($changes);
        }

        return $filled;
    }

    /** Seats moved to a record nobody can write to would strand the letters. */
    protected function moveEmail(Examiner $keep, Examiner $duplicate, User $actor): bool
    {
        $email = $duplicate->usableEmail();

        if ($email === null || $keep->usableEmail() !== null) {
            return false;
        }

        // Unique, so it has to leave the old record first. Both lines are logged.
        $this->contact->changeEmail($duplicate, null, $actor, 'merge', "Moved to {$keep->name} (#{$keep->id}).");
        $this->contact->changeEmail($keep, $email, $actor, 'merge', "From merged record {$duplicate->name} (#{$duplicate->id}).");

        return true;
    }

    /**
     * Jason's module keys sent letters by examiner id and is not ours to
     * change, so a letter sent to the old record looks unsent for the new one.
     *
     * @return array<int, int> application ids, for the warning
     */
    protected function lettersUnderOldRecord(Collection $nominations, Examiner $duplicate): array
    {
        $contract = 'App\\Modules\\Core\\Contracts\\SuppliesAppointmentDispatch';

        if (! interface_exists($contract) || ! app()->bound($contract)) {
            return [];
        }

        $dispatch = app($contract);

        return $nominations
            ->filter(fn (ExaminerNomination $n) => $n->application?->status === Application::STATUS_APPROVED
                && $dispatch->packSentAt($n->application_id, $duplicate->id))
            ->pluck('application_id')
            ->values()
            ->all();
    }
}
