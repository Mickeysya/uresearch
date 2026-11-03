<?php

namespace App\Modules\Nureen\Support;

use App\Modules\Core\Models\User;
use App\Modules\Nureen\Models\AttendanceRecord;
use Illuminate\Support\Collection;

/**
 * Approved annual leave against the allowance (Postgraduate Handbook 2025):
 * up to config('nureen.attendance.leave.annual_days') a year, split by
 * semester with no carry-over -- so each semester has its own cap as well.
 *
 * Leave is recorded per attendance period (AttendanceRecord::leave_days);
 * a period belongs to the semester its period_end month falls in
 * (config('nureen.attendance.leave.semesters')). Going over only WARNS CGS:
 * the days were approved by the Chair, so they still count.
 */
class AttendanceLeave
{
    /**
     * One student's leave in $year: per semester and in total, each
     * against its allowance.
     *
     * @return array{year: int, total: int, allowance: int, over_year: bool, semesters: array<string, array{label: string, days: int, allowance: int, over: bool}>}
     */
    public function usage(int $studentId, ?int $year = null): array
    {
        $year ??= now()->year;
        $config = config('nureen.attendance.leave');

        $records = AttendanceRecord::where('student_id', $studentId)
            ->whereYear('period_end', $year)
            ->where('leave_days', '>', 0)
            ->get(['period_end', 'leave_days']);

        $semesters = [];
        foreach ($config['semesters'] as $key => $semester) {
            $days = (int) $records->filter(fn ($r) => in_array((int) $r->period_end->month, $semester['months'], true))->sum('leave_days');
            $semesters[$key] = [
                'label' => $semester['label'],
                'days' => $days,
                'allowance' => (int) $semester['days'],
                'over' => $days > (int) $semester['days'],
            ];
        }

        $total = (int) $records->sum('leave_days');

        return [
            'year' => $year,
            'total' => $total,
            'allowance' => (int) $config['annual_days'],
            'over_year' => $total > (int) $config['annual_days'],
            'semesters' => $semesters,
        ];
    }

    /** What is wrong with one usage(), as sentences for CGS; empty when within the allowance. @return array<int, string> */
    public function problems(array $usage): array
    {
        $problems = [];

        foreach ($usage['semesters'] as $semester) {
            if ($semester['over']) {
                $problems[] = "{$semester['label']} {$usage['year']}: {$semester['days']} days recorded, allowance {$semester['allowance']} (unused leave is not carried over).";
            }
        }

        if ($usage['over_year']) {
            $problems[] = "{$usage['year']}: {$usage['total']} days recorded, over the {$usage['allowance']}-day annual allowance.";
        }

        return $problems;
    }

    /**
     * "Ahmad Danial (22001001): ..." for each of these students over an
     * allowance in any year their leave falls in.
     *
     * @param  array<int, int>  $studentIds
     * @return array<int, string>
     */
    public function warningsFor(array $studentIds): array
    {
        $warnings = [];

        foreach (User::whereIn('id', $studentIds)->orderBy('name')->get() as $student) {
            foreach ($this->yearsWithLeave($student->id) as $year) {
                foreach ($this->problems($this->usage($student->id, $year)) as $problem) {
                    $warnings[] = "{$student->name}".($student->matric_no ? " ({$student->matric_no})" : '').": {$problem}";
                }
            }
        }

        return $warnings;
    }

    /**
     * Every student with leave in $year, with their usage, those over an
     * allowance first -- the Approved Leave page and the at-risk banner.
     *
     * @return Collection<int, array{student: User, usage: array, problems: array<int, string>}>
     */
    public function overview(?int $year = null): Collection
    {
        $year ??= now()->year;

        $ids = AttendanceRecord::whereYear('period_end', $year)->where('leave_days', '>', 0)->distinct()->pluck('student_id');

        return User::whereIn('id', $ids)->orderBy('name')->get()
            ->map(function (User $student) use ($year) {
                $usage = $this->usage($student->id, $year);

                return ['student' => $student, 'usage' => $usage, 'problems' => $this->problems($usage)];
            })
            ->sortBy(fn (array $row) => $row['problems'] === [] ? 1 : 0)
            ->values();
    }

    /** @return array<int, int> */
    protected function yearsWithLeave(int $studentId): array
    {
        return AttendanceRecord::where('student_id', $studentId)
            ->where('leave_days', '>', 0)
            ->pluck('period_end')
            ->map(fn ($date) => (int) $date->year)
            ->unique()
            ->values()
            ->all();
    }
}
