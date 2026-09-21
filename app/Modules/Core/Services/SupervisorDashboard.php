<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\Models\User;
use App\Modules\Core\Services\Concerns\ReadsAttendance;
use Illuminate\Support\Collection;

/**
 * The Supervisor dashboard.
 *
 * The queues half is ApproverDashboard, shared with the Chair. What makes
 * this a different screen rather than the same one with a new title is that a
 * supervisor is accountable for **named students**, not just for a desk: the
 * question they open the portal with is "is any of my candidates in trouble",
 * which no queue can answer.
 *
 * A supervisor owns seven stages -- Travel, Student Claims, Publication, RPD
 * Extension Appeal, GA Extension, Supervision Requests and Hardbound
 * Submission -- which is why the generic approver screen was worse for them
 * than for anyone: seven stat cards, most of them zero.
 */
class SupervisorDashboard extends ApproverDashboard
{
    use ReadsAttendance;

    /**
     * This supervisor's candidates, the ones in trouble first.
     *
     * Attendance comes through `Contracts\SuppliesAttendance`, not from
     * Nureen's models: Core must not import another folder's Eloquent
     * classes, and if no module binds the contract the column is simply
     * absent rather than the panel dying.
     *
     * @return Collection<int, array{student: User, attendance: ?\App\Modules\Core\Support\AttendanceReading, tone: string}>
     */
    public function candidates(int $limit = 8): Collection
    {
        return $this->remember('candidates', fn () => $this->safely('candidates', function () use ($limit) {
            $source = $this->attendanceSource();

            return User::where('supervisor_id', $this->user->id)
                ->orderBy('name')
                ->get()
                ->map(function (User $student) use ($source) {
                    $reading = $source?->latestFor($student);

                    return [
                        'student' => $student,
                        'attendance' => $reading,
                        'tone' => StudentDashboard::toneFor($reading?->percentage),
                    ];
                })
                // At-risk first, then worst attendance, then by name. A
                // supervisor with twenty candidates should not have to read
                // twenty rows to find the two that need them.
                ->sortBy([
                    fn ($a, $b) => ($b['attendance']?->at_risk ?? false) <=> ($a['attendance']?->at_risk ?? false),
                    fn ($a, $b) => ($a['attendance']?->percentage ?? 101) <=> ($b['attendance']?->percentage ?? 101),
                ])
                ->take($limit)
                ->values();
        }, collect()));
    }

    /**
     * The cohort's attendance, as parts of a whole.
     *
     * A doughnut rather than another bar chart, and deliberately a different
     * question from the Chair's: the Chair's chart is about a desk (how long
     * has work been sitting), a supervisor's is about people (is my cohort
     * healthy). Bands come from StudentDashboard so a student reading "Good"
     * on their own dashboard is counted as Good here -- two thresholds that
     * had to agree were two that could disagree.
     *
     * "Not recorded" is its own slice rather than dropped: a supervisor whose
     * candidates have no attendance on file should see that, not a chart that
     * silently describes three of their twelve students.
     *
     * @return Collection<int, array{label: string, tone: string, count: int}>
     */
    public function attendanceSpread(): Collection
    {
        return $this->safely('candidates', function () {
            $rows = $this->candidates(PHP_INT_MAX);

            $slices = collect(StudentDashboard::attendanceBands())
                ->map(fn (array $band) => [
                    'label' => $band['label'],
                    'tone' => $band['tone'],
                    'count' => $rows->where('tone', $band['tone'])->count(),
                ]);

            return $slices->push([
                'label' => 'Not recorded',
                'tone' => 'none',
                'count' => $rows->where('tone', 'none')->count(),
            ])->values();
        }, collect());
    }

    /** How many candidates this supervisor has, before the panel's limit. */
    public function candidateCount(): int
    {
        return $this->safely('candidates',
            fn () => User::where('supervisor_id', $this->user->id)->count(), 0);
    }

    /**
     * Candidates the attendance module has flagged. The second headline
     * figure, and the one that is genuinely theirs rather than portal-wide.
     */
    public function atRiskCount(): int
    {
        return $this->candidates(PHP_INT_MAX)
            ->filter(fn (array $row) => $row['attendance']?->at_risk)
            ->count();
    }
}
