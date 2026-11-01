<?php

namespace App\Modules\Nureen\Database\Seeders;

use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Nureen\Models\AttendanceRecord;
use App\Modules\Nureen\Models\AttendanceRiskState;
use App\Modules\Nureen\Support\AttendanceEarlyWarning;
use App\Modules\Nureen\Support\AttendanceProvider;
use App\Modules\Nureen\Support\AttendanceRiskEvaluator;
use Illuminate\Database\Seeder;

/**
 * The three attendance-risk demo cases for the viva, on three existing
 * seeded students:
 *
 *   22093001  projection only  10, 10, 9, 9 -- 95% so far, comfortably above
 *                              85%, so the original rule does not flag them;
 *                              but the decline projects 75% by the end of the
 *                              semester. Reason "projected". Their dashboard
 *                              card reads "Attend 74 of the next 100 sessions".
 *   22001011  current rule     3, 3, 2, 3  -- 27.5%, well under 80%, and too
 *                              far under for 80% to be reachable this
 *                              semester (101 of the 100 sessions left would be
 *                              needed). Reason "both". Their card reads
 *                              "Justification needed".
 *   22001012  steady           9, 9, 9, 9  -- 90% throughout; not flagged.
 *                              Their card reads "On track".
 *
 * Weekly periods of the demo semester (NureenAttendanceDemoSeeder::WEEKS, 10
 * sessions each). Each student's whole attendance history is replaced, so
 * running it twice gives the same rows, never duplicates. It then runs the
 * early warning for these three only -- the projection-only student gets
 * their one email the first time, and none on a re-run, because nothing has
 * changed -- and prints each student's figures.
 *
 *     php artisan db:seed --class="App\Modules\Nureen\Database\Seeders\AttendanceRiskDemoSeeder"
 *
 * Not called from Core's DatabaseSeeder: that file belongs to the whole
 * team, and this data is only useful for demoing this module.
 */
class AttendanceRiskDemoSeeder extends Seeder
{
    public const PROJECTION_ONLY = '22093001';

    public const CURRENT_RULE = '22001011';

    public const STEADY = '22001012';

    /** @var array<string, array<int, int>> sessions attended each week, out of 10 */
    public const CASES = [
        self::PROJECTION_ONLY => [10, 10, 9, 9],
        self::CURRENT_RULE => [3, 3, 2, 3],
        self::STEADY => [9, 9, 9, 9],
    ];

    public function run(): void
    {
        $students = User::where('role', Role::STUDENT)
            ->whereIn('matric_no', array_keys(self::CASES))
            ->get()
            ->keyBy('matric_no');

        if ($students->isEmpty()) {
            $this->command?->warn('None of the demo students ('.implode(', ', array_keys(self::CASES)).') exist -- run the main seeders first.');

            return;
        }

        foreach ($students as $matric => $student) {
            AttendanceRecord::where('student_id', $student->id)->delete();

            foreach (NureenAttendanceDemoSeeder::WEEKS as $w => $friday) {
                $record = AttendanceRecord::recordPeriod($student->id, $friday, self::CASES[$matric][$w], NureenAttendanceDemoSeeder::SESSIONS_PER_WEEK);
                $record->update(['at_risk' => AttendanceRiskEvaluator::isAtRisk($record)]);
            }
        }

        app(AttendanceEarlyWarning::class)->run($students->pluck('id'));

        $pct = fn ($value) => $value === null ? '-' : rtrim(rtrim(number_format((float) $value, 1), '0'), '.').'%';

        $this->command?->table(
            ['Case', 'Login', 'Current', 'Projected', 'Reason', 'Dashboard card'],
            collect(self::CASES)->keys()->filter(fn ($m) => $students->has($m))->map(function (string $matric) use ($students, $pct) {
                $student = $students[$matric];
                $state = AttendanceRiskState::where('student_id', $student->id)->first();

                return [
                    match ($matric) {
                        self::PROJECTION_ONLY => 'Projection only',
                        self::CURRENT_RULE => 'Current rule',
                        default => 'Steady',
                    },
                    $student->email,
                    $pct($state?->current_percentage),
                    $pct($state?->projected_percentage),
                    $state?->reason ?? 'not flagged',
                    app(AttendanceProvider::class)->actionFor($student)['value'],
                ];
            })->values()->all(),
        );
    }
}
