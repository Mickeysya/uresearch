<?php

namespace App\Modules\Hani\Support;

use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Hani\Models\Examiner;
use App\Modules\Hani\Models\ExaminerNomination;
use App\Modules\Hani\Models\ExaminerSeatHistory;
use App\Modules\Hani\Notifications\PanelSeatChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Everything that changes who sits on a released panel, and the only code
 * that writes an examiner's tie-up and gap for one.
 *
 *   release()   the chain finished: tie up the two MAINS (letters go to them
 *               only, confirmed by CGS); backups stay Available on standby
 *   decline()   a main examiner declined: free them at once (no gap), then
 *               promote the matching backup if they are still eligible, or
 *               leave the seat empty for a replacement
 *   replace()   put an eligible examiner into an empty or declined seat
 *   complete()  the evaluation happened: whoever examined starts the 90-day
 *               gap; anyone tied up who did not examine is freed without one
 *
 * The seat columns on examiner_nominations are updated in place so they
 * always show the CURRENT panel -- Jason's appointment-letter module reads
 * them -- and every change is written to examiner_seat_history. Non-Executive
 * CGS is told whenever a letter needs sending to someone new.
 */
class PanelSeats
{
    public const MAINS = ['internal_main_id' => 'internal_backup_id', 'external_main_id' => 'external_backup_id'];

    public function __construct(
        protected ConflictOfInterest $coi,
        protected Departments $departments,
    ) {}

    /** The seats a release ties up: the mains, or all four if letters go to backups too. */
    public function tiedSeats(): array
    {
        return config('hani.appointments.letters_to_backups', false)
            ? array_keys(ExaminerNomination::SLOTS)
            : array_keys(self::MAINS);
    }

    public function release(ExaminerNomination $nomination, ?User $actor = null): void
    {
        $until = now()->addDays((int) config('hani.appointments.assignment_days', Examiner::DEFAULT_ASSIGNMENT_DAYS));
        $tied = $this->tiedSeats();

        DB::transaction(function () use ($nomination, $actor, $until, $tied) {
            foreach (ExaminerNomination::SLOTS as $seat => $label) {
                $examiner = $nomination->{$seat} ? Examiner::find($nomination->{$seat}) : null;

                if (! $examiner) {
                    continue;
                }

                if (in_array($seat, $tied, true)) {
                    $examiner->update(['assigned_until' => $until]);
                }

                $this->log($nomination, $seat, $examiner->id, in_array($seat, $tied, true) ? 'released' : 'standby', null, $actor);
            }
        });
    }

    /**
     * @return array{promoted: ?Examiner, declined: Examiner} who now holds the seat, if anyone
     */
    public function decline(ExaminerNomination $nomination, string $seat, string $reason, User $actor): array
    {
        if (! array_key_exists($seat, self::MAINS)) {
            throw ValidationException::withMessages(['seat' => 'Only a main examiner can decline; a backup is not appointed.']);
        }

        $result = DB::transaction(function () use ($nomination, $seat, $reason, $actor) {
            /** @var ExaminerNomination $locked */
            $locked = ExaminerNomination::whereKey($nomination->id)->lockForUpdate()->firstOrFail();
            $declined = $locked->{$seat} ? Examiner::find($locked->{$seat}) : null;

            if (! $declined) {
                throw ValidationException::withMessages(['seat' => 'That seat is already empty.']);
            }

            // Freed at once and WITHOUT a gap: they never examined.
            $declined->update(['assigned_until' => null]);
            $this->log($locked, $seat, $declined->id, 'declined', $reason, $actor);

            $backupSeat = self::MAINS[$seat];
            $backup = $locked->{$backupSeat} ? Examiner::find($locked->{$backupSeat}) : null;
            $promoted = null;

            if ($backup && $this->canTakeSeat($backup, $locked)) {
                $locked->forceFill([$seat => $backup->id, $backupSeat => null])->save();
                $backup->update(['assigned_until' => now()->addDays((int) config('hani.appointments.assignment_days', Examiner::DEFAULT_ASSIGNMENT_DAYS))]);
                $this->log($locked, $seat, $backup->id, 'promoted', "Replaces {$declined->name}.", $actor);
                $promoted = $backup;
            } else {
                $locked->forceFill([$seat => null])->save();
                $this->log($locked, $seat, null, 'vacant', $backup
                    ? "Backup {$backup->name} is not eligible now: ".$backup->ineligibilityReason()
                    : 'No backup on this side.', $actor);
            }

            return ['promoted' => $promoted, 'declined' => $declined, 'nomination' => $locked];
        });

        if ($result['promoted']) {
            $this->tellOperations($result['nomination'], $result['promoted'], 'promoted', $result['declined']);
        }

        $nomination->refresh();

        return ['promoted' => $result['promoted'], 'declined' => $result['declined']];
    }

    public function replace(ExaminerNomination $nomination, string $seat, Examiner $examiner, User $actor): void
    {
        if (! array_key_exists($seat, ExaminerNomination::SLOTS)) {
            throw ValidationException::withMessages(['seat' => 'Unknown seat.']);
        }

        $locked = DB::transaction(function () use ($nomination, $seat, $examiner, $actor) {
            $locked = ExaminerNomination::with('application.student')->whereKey($nomination->id)->lockForUpdate()->firstOrFail();

            if ($locked->{$seat}) {
                throw ValidationException::withMessages(['examiner_id' => 'That seat is filled. Record the decline first.']);
            }

            $this->assertCanTake($examiner, $locked, $seat);

            $locked->forceFill([$seat => $examiner->id])->save();

            if (in_array($seat, $this->tiedSeats(), true)) {
                $examiner->update(['assigned_until' => now()->addDays((int) config('hani.appointments.assignment_days', Examiner::DEFAULT_ASSIGNMENT_DAYS))]);
            }

            $this->log($locked, $seat, $examiner->id, 'replaced', null, $actor);

            return $locked;
        });

        if (array_key_exists($seat, self::MAINS)) {
            $this->tellOperations($locked, $examiner, 'replaced');
        }

        $nomination->refresh();
    }

    /**
     * The evaluation is done. The examiners in the main seats examined, so
     * their 90-day gap starts today; anyone else this panel had tied up (a
     * backup, when letters went to backups too) is freed with no gap.
     */
    public function complete(ExaminerNomination $nomination, ?User $actor = null): void
    {
        DB::transaction(function () use ($nomination, $actor) {
            foreach (ExaminerNomination::SLOTS as $seat => $label) {
                $examiner = $nomination->{$seat} ? Examiner::find($nomination->{$seat}) : null;

                if (! $examiner) {
                    continue;
                }

                if (array_key_exists($seat, self::MAINS)) {
                    $examiner->update(['assigned_until' => null, 'last_examination_date' => now()]);
                    $this->log($nomination, $seat, $examiner->id, 'examined', null, $actor);
                } elseif ($examiner->assigned_until !== null) {
                    $examiner->update(['assigned_until' => null]);
                    $this->log($nomination, $seat, $examiner->id, 'freed', 'Backup not used.', $actor);
                }
            }
        });
    }

    /** May this person be promoted into a seat on this panel right now? */
    protected function canTakeSeat(Examiner $examiner, ExaminerNomination $nomination): bool
    {
        try {
            $this->assertCanTake($examiner, $nomination->loadMissing('application.student'), null);
        } catch (ValidationException) {
            return false;
        }

        return true;
    }

    /**
     * The same rules as nominating: eligible now (a backup tied up by THIS
     * panel counts), the right type and department, and no blocking conflict
     * of interest.
     */
    protected function assertCanTake(Examiner $examiner, ExaminerNomination $nomination, ?string $seat): void
    {
        $student = $nomination->application?->student;
        $heldByThisPanel = in_array($examiner->id, [$nomination->internal_backup_id, $nomination->external_backup_id], true)
            && $examiner->assigned_until !== null && $examiner->is_active;

        if (! $examiner->isEligible() && ! $heldByThisPanel) {
            throw ValidationException::withMessages(['examiner_id' => "{$examiner->name} is not eligible. ".$examiner->ineligibilityReason()]);
        }

        if ($seat !== null) {
            $expected = str_starts_with($seat, 'internal') ? Examiner::TYPE_INTERNAL : Examiner::TYPE_EXTERNAL;

            if ($examiner->type !== $expected) {
                throw ValidationException::withMessages(['examiner_id' => "This seat needs an {$expected} examiner."]);
            }

            if (in_array($examiner->id, array_filter(array_map(fn ($s) => $nomination->{$s}, array_keys(ExaminerNomination::SLOTS))), true)) {
                throw ValidationException::withMessages(['examiner_id' => "{$examiner->name} is already on this panel."]);
            }
        }

        if ($student && $examiner->type === Examiner::TYPE_INTERNAL && ! $this->departments->same($examiner->department, $student->department)) {
            throw ValidationException::withMessages(['examiner_id' => "An internal examiner must come from {$student->department}."]);
        }

        if ($student && $this->coi->blocks($flags = $this->coi->flagsFor($examiner, $student))) {
            throw ValidationException::withMessages(['examiner_id' => collect($flags)->firstWhere('level', ConflictOfInterest::BLOCK)['message']]);
        }
    }

    protected function log(ExaminerNomination $nomination, string $seat, ?int $examinerId, string $event, ?string $reason, ?User $actor): void
    {
        ExaminerSeatHistory::create([
            'nomination_id' => $nomination->id,
            'seat' => $seat,
            'examiner_id' => $examinerId,
            'event' => $event,
            'reason' => $reason,
            'actor_id' => $actor?->id,
        ]);
    }

    /** Non-Executive CGS sends the letters (Jason's module): tell them who needs one now. */
    protected function tellOperations(ExaminerNomination $nomination, Examiner $examiner, string $why, ?Examiner $replacing = null): void
    {
        try {
            User::where('role', Role::NON_EXEC_CGS)->get()
                ->each(fn (User $user) => $user->notify(new PanelSeatChanged($nomination, $examiner, $why, $replacing)));
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
