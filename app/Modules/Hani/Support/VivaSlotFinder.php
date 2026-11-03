<?php

namespace App\Modules\Hani\Support;

use App\Modules\Core\Models\Application;
use App\Modules\Hani\Models\ExaminerNomination;
use App\Modules\Hani\Models\VivaDetail;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Scheduling help for the Set Date form: what is wrong with a proposed slot,
 * and the next free ones. Warnings only; the AE can schedule anyway.
 *
 * A clash is another scheduled viva starting within config('hani.scheduling
 * .clash_hours') that shares a person or a room:
 *   - an examiner (either main examiner, on either panel)
 *   - the chairman (by email)
 *   - the supervisor
 *   - the venue (same text, ignoring case and spacing)
 * Plus weekends and Malaysian public holidays (config, provisional).
 */
class VivaSlotFinder
{
    /** @var Collection<int, array>|null the other scheduled vivas, read once */
    protected ?Collection $others = null;

    public function __construct(protected Application $notice) {}

    /**
     * Everything to warn about for this slot, as sentences.
     *
     * @return array<int, string>
     */
    public function warnings(CarbonInterface $start, ?string $venue, ?string $chairEmail): array
    {
        $warnings = [];

        if ($start->isWeekend()) {
            $warnings[] = $start->format('l, j M Y').' is a weekend.';
        }

        if ($holiday = $this->holiday($start)) {
            $warnings[] = $start->format('j M Y')." is a public holiday ({$holiday}).";
        }

        foreach ($this->clashes($start, $venue, $chairEmail) as $clash) {
            $warnings[] = $clash;
        }

        return $warnings;
    }

    /**
     * The next free weekday slots from $from, skipping holidays and clashes.
     *
     * @return array<int, CarbonInterface>
     */
    public function suggestions(CarbonInterface $from, ?string $venue, ?string $chairEmail): array
    {
        $want = (int) config('hani.scheduling.suggestions', 3);
        $times = (array) config('hani.scheduling.slot_times', ['09:00', '14:00']);
        $day = Carbon::parse($from)->startOfDay();
        $earliest = now()->addHour();
        $out = [];

        for ($i = 0; $i < (int) config('hani.scheduling.lookahead_days', 60) && count($out) < $want; $i++, $day->addDay()) {
            if ($day->isWeekend() || $this->holiday($day)) {
                continue;
            }

            foreach ($times as $time) {
                $slot = $day->copy()->setTimeFromTimeString($time);

                if ($slot->gt($earliest) && $this->clashes($slot, $venue, $chairEmail) === []) {
                    $out[] = $slot;
                }

                if (count($out) >= $want) {
                    break;
                }
            }
        }

        return $out;
    }

    public function holiday(CarbonInterface $day): ?string
    {
        return config('hani.scheduling.public_holidays')[$day->toDateString()] ?? null;
    }

    /** @return array<int, string> */
    protected function clashes(CarbonInterface $start, ?string $venue, ?string $chairEmail): array
    {
        $hours = (float) config('hani.scheduling.clash_hours', 2);
        $mine = $this->people($this->notice);
        $out = [];

        foreach ($this->others() as $other) {
            if (abs($other['at']->diffInMinutes($start, false)) >= $hours * 60) {
                continue;
            }

            $when = $other['at']->format('j M Y, g:i a');
            $who = $other['student'];

            foreach (array_intersect_key($other['examiners'], $mine['examiners']) as $name) {
                $out[] = "Examiner {$name} is already at {$who}'s viva at {$when}.";
            }
            if ($chairEmail && $other['chair'] && strtolower($chairEmail) === $other['chair']) {
                $out[] = "The chairman ({$chairEmail}) is already chairing {$who}'s viva at {$when}.";
            }
            if ($mine['supervisor'] && $mine['supervisor'] === $other['supervisor']) {
                $out[] = "The supervisor is already at {$who}'s viva at {$when}.";
            }
            if ($venue && self::sameVenue($venue, $other['venue'])) {
                $out[] = "{$other['venue']} is already booked for {$who}'s viva at {$when}.";
            }
        }

        return $out;
    }

    /** Other scheduled vivas, upcoming or recent, with the people on them. */
    protected function others(): Collection
    {
        return $this->others ??= VivaDetail::with('application.student')
            ->whereNotNull('viva_at')
            ->where('viva_at', '>=', now()->subDay())
            ->where('application_id', '!=', $this->notice->id)
            ->whereHas('application', fn ($q) => $q->where('module_type', VivaProgress::NOTICE)->where('status', Application::STATUS_APPROVED))
            ->get()
            ->map(fn (VivaDetail $d) => [
                'at' => $d->viva_at,
                'venue' => (string) $d->venue,
                'chair' => $d->chair_email ? strtolower($d->chair_email) : null,
                'student' => (string) $d->application->student?->name,
            ] + $this->people($d->application));
    }

    /** @return array{examiners: array<int, string>, supervisor: ?int} examiner id => name */
    protected function people(Application $notice): array
    {
        $panel = ExaminerNomination::with('internalMain', 'externalMain')
            ->whereHas('application', fn ($q) => $q->where('module_type', VivaProgress::NOMINATION)
                ->where('status', Application::STATUS_APPROVED)
                ->where('student_id', $notice->student_id))
            ->latest('id')
            ->first();

        $examiners = [];
        foreach ([$panel?->internalMain, $panel?->externalMain] as $examiner) {
            if ($examiner) {
                $examiners[$examiner->id] = $examiner->name;
            }
        }

        return ['examiners' => $examiners, 'supervisor' => $notice->student?->supervisor_id];
    }

    protected static function sameVenue(string $a, string $b): bool
    {
        $norm = fn ($v) => preg_replace('/\s+/', ' ', mb_strtolower(trim($v)));

        return $norm($a) !== '' && $norm($a) === $norm($b);
    }
}
