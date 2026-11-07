<?php

namespace App\Modules\Norhanis\Support;

use App\Modules\Core\Contracts\SuppliesTeachingAssistantStatus;
use App\Modules\Core\Models\User;
use App\Modules\Norhanis\Models\TaHourLog;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Whether a student owes a TA plan on the Travel form, and the TA hours
 * they have already done this semester.
 *
 * Whether they are a GA/GRA is asked of whichever module owns the
 * appointments, through Core's SuppliesTeachingAssistantStatus (Haziq's).
 * When nothing answers, the form falls back to asking the student, as it
 * did before. The hours come from this module's own TA Hours log.
 */
class TaStatus
{
    /**
     * @return array{known: bool, type: 'GA'|'GRA'|null, hoursCompleted: float|null, from: CarbonInterface|null}
     *         known false: nothing to ask, so the student says; type null: not a GA/GRA today
     */
    public function for(User $student): array
    {
        if (! app()->bound(SuppliesTeachingAssistantStatus::class)) {
            return ['known' => false, 'type' => null, 'hoursCompleted' => null, 'from' => null];
        }

        $source = app(SuppliesTeachingAssistantStatus::class);
        $appointment = $source->currentAppointmentFor($student);

        if (! $appointment) {
            return ['known' => true, 'type' => null, 'hoursCompleted' => null, 'from' => null];
        }

        $from = self::semesterStart();
        if ($appointment['since']->greaterThan($from)) {
            $from = Carbon::instance($appointment['since'])->startOfDay();
        }

        return [
            'known' => true,
            'type' => $appointment['type'],
            'hoursCompleted' => round(self::hoursBetween($student, $from, Carbon::today()), 2),
            'from' => $from,
        ];
    }

    /** TA hours logged for the student with an activity date from $from to $to, inclusive. */
    public static function hoursBetween(User $student, CarbonInterface $from, CarbonInterface $to): float
    {
        return (float) TaHourLog::where('student_id', $student->id)
            ->whereDate('activity_date', '>=', $from)
            ->whereDate('activity_date', '<=', $to)
            ->sum('hours');
    }

    /** The first day of the semester today falls in. */
    public static function semesterStart(?CarbonInterface $today = null): Carbon
    {
        $today = Carbon::instance($today ?? Carbon::today());
        $months = config('norhanis.ta.semester_start_months', [1, 5, 9]);
        $started = array_filter($months, fn (int $month) => $month <= $today->month);

        return $started
            ? Carbon::create($today->year, max($started), 1)
            : Carbon::create($today->year - 1, max($months), 1);
    }
}
