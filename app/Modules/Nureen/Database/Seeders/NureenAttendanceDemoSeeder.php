<?php

namespace App\Modules\Nureen\Database\Seeders;

use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Nureen\Models\AttendanceRecord;
use App\Modules\Nureen\Support\AttendanceRiskEvaluator;
use Illuminate\Database\Seeder;

/**
 * One clean WEEKLY attendance history per demo student, for the demo
 * semester only -- replacing whatever they had (the shared DemoDataSeeder's
 * monthly rows, and weekly rows the simulated sync added on top, which mixed
 * period lengths in one student's history).
 *
 *     php artisan db:seed --class="App\Modules\Nureen\Database\Seeders\NureenAttendanceDemoSeeder"
 *
 * The demo semester starts on config('nureen.attendance.semester_start')
 * (Monday 5 October 2026), so the four weeks ending Friday 9, 16, 23 and
 * 30 October are complete by 1 November. 10 sessions a week: a check-in and
 * a check-out on each of five working days.
 *
 * Three students are the deliberate demo cases, with the patterns
 * AttendanceRiskDemoSeeder owns (that seeder sets just those three, and
 * prints their figures); everyone else gets a steady, healthy pattern, so
 * nobody else is flagged.
 *
 * Only students whose matric number is listed in DEMO_MATRICS are touched,
 * and the risk check is NOT run here -- run `attendance:check-risk` (or a
 * sync) afterwards to evaluate and send the automatic early warnings.
 */
class NureenAttendanceDemoSeeder extends Seeder
{
    /** Week-ending Fridays of the demo semester so far. */
    public const WEEKS = ['2026-10-09', '2026-10-16', '2026-10-23', '2026-10-30'];

    public const SESSIONS_PER_WEEK = 10;

    public const PROJECTION_ONLY = AttendanceRiskDemoSeeder::PROJECTION_ONLY;

    public const CURRENT_RULE = AttendanceRiskDemoSeeder::CURRENT_RULE;

    public const STEADY = AttendanceRiskDemoSeeder::STEADY;

    /** @var array<string, array<int, int>> the three deliberate cases */
    public const CASES = AttendanceRiskDemoSeeder::CASES;

    /** Healthy patterns for everyone else: never declining, never under 90%. */
    protected const STEADY_PATTERNS = [
        [10, 10, 10, 10],
        [9, 10, 9, 10],
        [10, 9, 10, 10],
        [9, 9, 10, 10],
        [10, 10, 9, 10],
    ];

    /**
     * Every student seeded by DemoDataSeeder (22001xxx) or by Nureen's own
     * demo seeders and verification runs (2209xxxx) -- matched exactly, so a
     * real student is never touched.
     */
    public const DEMO_MATRICS = [
        '22001001', '22001002', '22001010', '22001011', '22001012', '22001013', '22001014',
        '22001015', '22001016', '22001017', '22001018', '22001019', '22001020', '22001021',
        '22001022', '22001023', '22001024', '22001025',
        '22090001', '22090002', '22090003', '22090004', '22090005',
        '22091001', '22091002', '22091003', '22091004', '22091005', '22091006', '22091007',
        '22091011', '22091012', '22091013', '22091021', '22091022', '22091023', '22091024',
        '22092001', '22092002', '22092003', '22092004',
        '22092101', '22092102', '22092103', '22092104',
        '22092201', '22092202', '22092203',
        '22093001',
    ];

    public function run(): void
    {
        $students = User::where('role', Role::STUDENT)
            ->whereIn('matric_no', self::DEMO_MATRICS)
            ->orderBy('matric_no')
            ->get();

        foreach ($students as $i => $student) {
            $pattern = self::CASES[$student->matric_no] ?? self::STEADY_PATTERNS[$i % count(self::STEADY_PATTERNS)];

            // Replaces the student's whole attendance history: mixed monthly
            // and weekly rows cannot be projected, and the demo is one semester.
            AttendanceRecord::where('student_id', $student->id)->delete();

            foreach (self::WEEKS as $w => $friday) {
                $record = AttendanceRecord::recordPeriod($student->id, $friday, $pattern[$w], self::SESSIONS_PER_WEEK);
                $record->update(['at_risk' => AttendanceRiskEvaluator::isAtRisk($record)]);
            }
        }

        $this->command?->info("Seeded weekly attendance for {$students->count()} demo students (".implode(', ', self::WEEKS).').');
    }
}
