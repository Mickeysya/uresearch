<?php

namespace App\Modules\Hani\Support;

use App\Modules\Core\Contracts\SuppliesAppointmentDispatch;
use App\Modules\Core\Models\Application;
use App\Modules\Hani\Models\ExaminerMerge;
use App\Modules\Hani\Models\ExaminerNomination;
use App\Modules\Hani\Models\ExaminerSeatHistory;
use App\Modules\Hani\Models\VivaExaminerProgress;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Which examiners on a released panel already have an appointment letter, so
 * the Examiner Report and its export can stop a second one being sent.
 *
 * Where the date comes from: both Jason's SuppliesAppointmentDispatch, when it
 * is bound, and the date on the examiner progress page count as sent, because
 * a letter sent by hand is still a letter. Where both exist Jason's wins, as it
 * records the mail actually being accepted. The status names its source.
 *
 * A panel's result:
 *   seats     seat column => "Not sent" | "Sent 01 Nov 2026 (letter module)"
 *             | "Sent 01 Nov 2026 (entered by AE)" | "Replaced 05 Nov 2026" | null
 *   needed    main seat column => examiner name, the mains still "Not sent"
 *   replaced  [{seat, name, status}] everyone who had a letter and left the panel
 *   sent      how many of the two main seats show "Sent"
 *
 * A seat someone holds reads for THAT person, so a replacement starts at
 * "Not sent". The person they replaced is listed under `replaced`. A seat's
 * own cell reads "Replaced" only while nobody has taken it yet.
 *
 * A merged record counts as one person: a letter sent under the record that
 * was merged away is still a letter this examiner has received.
 *
 * Only released panels have an answer. Before release, no letter is due.
 */
class AppointmentLetters
{
    public const MAIN_SEATS = 2;

    public const FROM_MODULE = 'letter module';

    public const FROM_AE = 'entered by AE';

    protected const DISPATCH = SuppliesAppointmentDispatch::class;

    /** Seat column => the name the export's headings use. */
    public const SEATS = [
        'internal_main_id' => 'Internal Main',
        'internal_backup_id' => 'Internal Backup',
        'external_main_id' => 'External Main',
        'external_backup_id' => 'External Backup',
    ];

    /** @var array<int, int> merged-away examiner id => the record it moved to */
    protected array $mergedInto = [];

    protected ?object $dispatch = null;

    /** @var Collection<int, Collection<int, VivaExaminerProgress>> */
    protected Collection $entered;

    /**
     * @param  Collection<int, ExaminerNomination>  $nominations  with application and seat relations loaded
     * @return array<int, array> nomination id => the result above, released panels only
     */
    public function forPanels(Collection $nominations): array
    {
        $released = $nominations
            ->filter(fn (ExaminerNomination $n) => $n->application?->status === Application::STATUS_APPROVED)
            ->values();

        if ($released->isEmpty()) {
            return [];
        }

        $ids = $released->pluck('id');

        $this->mergedInto = ExaminerMerge::query()->pluck('kept_id', 'duplicate_id')->all();
        $this->dispatch = app()->bound(self::DISPATCH) ? app(self::DISPATCH) : null;
        $this->entered = VivaExaminerProgress::query()
            ->whereIn('nomination_id', $ids)
            ->whereNotNull('letter_sent_at')
            ->get(['nomination_id', 'examiner_id', 'letter_sent_at', 'letter_source'])
            ->groupBy('nomination_id');

        $history = ExaminerSeatHistory::with('examiner')
            ->whereIn('nomination_id', $ids)
            ->orderBy('id')
            ->get()
            ->groupBy('nomination_id');

        return $released
            ->mapWithKeys(fn (ExaminerNomination $n) => [$n->id => $this->panel($n, $history->get($n->id, collect()))])
            ->all();
    }

    /**
     * Released panels with a main seat still "Not sent". A panel already
     * examined on, or freed without a viva, is left out: no letter is owed.
     *
     * @param  Collection<int, ExaminerNomination>  $nominations
     * @param  array<int, array>  $statuses  from forPanels()
     * @return Collection<int, ExaminerNomination>
     */
    public function awaiting(Collection $nominations, array $statuses): Collection
    {
        $candidates = array_keys(array_filter($statuses, fn (array $panel) => $panel['needed'] !== []));

        $open = ExaminerNomination::query()
            ->whereIn('id', $candidates)
            ->evaluationOpen()
            ->pluck('id')
            ->all();

        return $nominations->filter(fn (ExaminerNomination $n) => in_array($n->id, $open, true))->values();
    }

    /** @param  Collection<int, ExaminerSeatHistory>  $history  this panel's, oldest first */
    protected function panel(ExaminerNomination $nomination, Collection $history): array
    {
        $seats = [];
        $needed = [];
        $sent = 0;

        foreach (array_keys(self::SEATS) as $seat) {
            $examinerId = $nomination->{$seat};

            if (! $examinerId) {
                $seats[$seat] = null;

                continue;
            }

            $letter = $this->letter($nomination, $examinerId);
            $seats[$seat] = $letter ? 'Sent '.$letter['at']->format('d M Y').' ('.$letter['source'].')' : 'Not sent';

            if (array_key_exists($seat, PanelSeats::MAINS)) {
                if ($letter) {
                    $sent++;
                } else {
                    $needed[$seat] = $this->holder($nomination, $seat)?->name;
                }
            }
        }

        $replaced = $this->replaced($nomination, $history);

        foreach ($replaced as $former) {
            if (! $nomination->{$former['seat']}) {
                $seats[$former['seat']] = $former['status'];
            }
        }

        return ['seats' => $seats, 'needed' => $needed, 'replaced' => $replaced, 'sent' => $sent];
    }

    /**
     * Everyone who has left this panel after being sent a letter.
     *
     * @return array<int, array{seat: string, name: ?string, status: string}>
     */
    protected function replaced(ExaminerNomination $nomination, Collection $history): array
    {
        $seated = collect(array_keys(self::SEATS))
            ->map(fn (string $seat) => $nomination->{$seat})
            ->filter()
            ->map(fn (int $id) => $this->record($id))
            ->all();

        return $history
            ->whereNotNull('examiner_id')
            ->groupBy(fn (ExaminerSeatHistory $row) => $this->record($row->examiner_id))
            ->reject(fn ($rows, int $record) => in_array($record, $seated, true))
            ->map(function ($rows) use ($nomination, $history) {
                $last = $rows->last();

                if (! $this->letter($nomination, $last->examiner_id)) {
                    return null;
                }

                // Their decline, or else whatever next happened to the seat.
                $left = $last->event === 'declined'
                    ? $last
                    : ($history->first(fn ($row) => $row->id > $last->id && $row->seat === $last->seat) ?? $last);

                return [
                    'seat' => $last->seat,
                    'name' => $last->examiner?->name,
                    'status' => 'Replaced '.$left->created_at->format('d M Y'),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * The latest letter this person has had for this nomination, under any of
     * their records. A progress row Jason's date was copied onto
     * (ExaminerProgress::sync()) is his record, not something the AE entered.
     *
     * @return array{at: Carbon, source: string}|null
     */
    protected function letter(ExaminerNomination $nomination, int $examinerId): ?array
    {
        $records = $this->recordsOf($examinerId);

        $sent = $this->dispatch
            ? collect($records)
                ->map(fn (int $id) => $this->dispatch->packSentAt($nomination->application_id, $id))
                ->filter()
                ->map(fn ($at) => Carbon::parse($at))
                ->max()
            : null;

        if ($sent) {
            return ['at' => $sent, 'source' => self::FROM_MODULE];
        }

        $row = $this->entered->get($nomination->id, collect())
            ->whereIn('examiner_id', $records)
            ->sortBy('letter_sent_at')
            ->last();

        return $row
            ? ['at' => $row->letter_sent_at, 'source' => $row->letter_source === 'dispatch' ? self::FROM_MODULE : self::FROM_AE]
            : null;
    }

    /** The record this examiner lives on now. */
    protected function record(int $id): int
    {
        while (isset($this->mergedInto[$id])) {
            $id = $this->mergedInto[$id];
        }

        return $id;
    }

    /** @return array<int, int> this person's record and every record merged into it */
    protected function recordsOf(int $id): array
    {
        $record = $this->record($id);

        return collect(array_keys($this->mergedInto))
            ->filter(fn (int $duplicate) => $this->record($duplicate) === $record)
            ->push($record)
            ->all();
    }

    protected function holder(ExaminerNomination $nomination, string $seat): mixed
    {
        return $nomination->{Str::camel(Str::beforeLast($seat, '_id'))};
    }
}
