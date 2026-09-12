<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApprovalHistory;
use App\Modules\Core\Contracts\SuppliesAttendance;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\Concerns\BuildsPanels;
use App\Modules\Core\Services\Concerns\ReadsAttendance;
use App\Modules\Core\Support\Role;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Everything the administrator's dashboard puts on screen.
 *
 * Same shape as StudentDashboard and CgsDashboard: one named method per panel,
 * every query behind safely(), so a dead source costs that panel and not the
 * page.
 *
 * WHERE THIS DIVERGES FROM Sample/ADMIN_DASHBOARD.png, and why:
 *
 *   - **"Active Courses" has no course model.** "Course" appears in none of
 *     the six scope documents and there is no `courses` table. The closest
 *     real figure is the number of distinct `users.programme` values, so that
 *     is what the card counts, and its caption says so rather than implying a
 *     course registry exists.
 *   - **"Faculty Members" is a count of staff accounts** (supervisor, chair,
 *     academic executive). There is no faculty/staff model either — staff are
 *     `users` with a role.
 *   - **"Server Status" is replaced by queue health.** A server-status tile
 *     rendered by the server is tautological; failed jobs are a real signal,
 *     and there are usually some.
 *   - **Deltas are month-over-month, not "from last semester".** There is no
 *     semester or intake model to measure a semester against.
 */
class AdminDashboard
{
    /** safely(), remember(), markUnavailable(), unavailable(), withTrend(). */
    use BuildsPanels;

    /** attendanceSource() — the module supplying attendance, or null. */
    use ReadsAttendance;

    /* ---------------------------------------------------------------
     | The five headline figures
     |---------------------------------------------------------------*/

    /** @return array{count: int, delta: ?float} */
    public function totalStudents(): array
    {
        return $this->safely('users', fn () => $this->withTrend(
            fn ($from, $to) => User::where('role', Role::STUDENT)
                ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
                ->when($to, fn ($q) => $q->where('created_at', '<', $to))
                ->count()
        ), $this->noTrend());
    }

    /**
     * Distinct programmes on record — the nearest real thing to a course
     * count. See the class docblock.
     *
     * @return array{count: int, delta: ?float}
     */
    public function programmes(): array
    {
        return $this->safely('users', fn () => [
            'count' => User::whereNotNull('programme')->distinct()->count('programme'),
            'delta' => null,
        ], $this->noTrend());
    }

    /** @return array{count: int, delta: ?float} */
    public function staffMembers(): array
    {
        $staffRoles = [Role::SUPERVISOR, Role::CHAIR, Role::ACADEMIC_EXEC];

        return $this->safely('users', fn () => $this->withTrend(
            fn ($from, $to) => User::whereIn('role', $staffRoles)
                ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
                ->when($to, fn ($q) => $q->where('created_at', '<', $to))
                ->count()
        ), $this->noTrend());
    }

    /** Mean of every student's most recent attendance record, or null. */
    public function averageAttendance(): ?float
    {
        $source = $this->attendanceSource();

        if (! $source) {
            $this->markUnavailable('attendance');

            return null;
        }

        return $this->safely('attendance', function () use ($source) {
            $latest = $this->latestAttendance($source);

            return $latest->isEmpty() ? null : round($latest->avg('percentage'), 1);
        }, null);
    }

    /** @return array{count: int, delta: ?float} */
    public function activeApplications(): array
    {
        return $this->safely('applications', fn () => $this->withTrend(
            fn ($from, $to) => Application::where('status', Application::STATUS_PENDING)
                ->when($from, fn ($q) => $q->where('submitted_at', '>=', $from))
                ->when($to, fn ($q) => $q->where('submitted_at', '<', $to))
                ->count()
        ), $this->noTrend());
    }

    /* ---------------------------------------------------------------
     | Recent Activities
     |---------------------------------------------------------------*/

    /**
     * Institution-wide feed. Three sources, because the portal records them
     * separately: an account appearing in `users`, an application appearing
     * in `applications`, and a decision in `approval_history`.
     *
     * @return Collection<int, array{text: string, sub: string, at: mixed, tone: string, icon: string}>
     */
    public function recentActivities(int $limit = 6): Collection
    {
        return $this->safely('activity', function () use ($limit) {
            $registry = app(ModuleRegistry::class);

            $enrolments = User::where('role', Role::STUDENT)
                ->latest('created_at')->limit($limit)->get()
                ->map(fn ($u) => [
                    'text' => 'New student enrolled',
                    'sub' => $u->name.($u->programme ? ' · '.$u->programme : ''),
                    'at' => $u->created_at,
                    'tone' => 'info',
                    'icon' => 'people',
                ]);

            $submissions = Application::with('student')
                ->whereNotNull('submitted_at')
                ->latest('submitted_at')->limit($limit)->get()
                ->filter(fn ($a) => $a->student)
                ->map(fn ($a) => [
                    'text' => $registry->labelFor($a->module_type).' submitted',
                    'sub' => $a->student->name.' · '.$a->reference(),
                    'at' => $a->submitted_at,
                    'tone' => 'info',
                    'icon' => 'doc',
                ]);

            $decisions = ApprovalHistory::with('application.student', 'approver')
                ->latest('created_at')->limit($limit)->get()
                ->filter(fn ($h) => $h->application && $h->application->student)
                ->map(fn ($h) => [
                    'text' => $registry->labelFor($h->application->module_type).' '.$h->decision,
                    'sub' => $h->application->student->name.' · by '.($h->approver->name ?? 'an approver'),
                    'at' => $h->created_at,
                    'tone' => $h->decision === 'rejected' ? 'critical' : 'good',
                    'icon' => $h->decision === 'rejected' ? 'cross' : 'check',
                ]);

            return $enrolments->concat($submissions)->concat($decisions)
                ->sortByDesc(fn ($r) => $r['at'])
                ->take($limit)
                ->values();
        }, collect());
    }

    /* ---------------------------------------------------------------
     | System Overview
     |---------------------------------------------------------------*/

    /**
     * Four real health signals. Nothing here is decorative — each reads
     * something the system actually records.
     *
     * @return array<int, array{label: string, value: string, note: string, tone: string, icon: string}>
     */
    public function systemHealth(): array
    {
        return [
            $this->databaseHealth(),
            $this->queueHealth(),
            $this->storageHealth(),
            $this->activeUsersTile(),
        ];
    }

    /** Applications split by module — the donut inside System Overview. */
    public function applicationMix(): Collection
    {
        return $this->safely('applications', function () {
            $registry = app(ModuleRegistry::class);

            $rows = Application::select('module_type', DB::raw('COUNT(*) as total'))
                ->groupBy('module_type')
                ->orderByDesc('total')
                ->get();

            $sum = (int) $rows->sum('total');

            return $rows->map(fn ($r) => [
                'label' => $registry->labelFor($r->module_type),
                'count' => (int) $r->total,
                'share' => $sum > 0 ? (int) round($r->total / $sum * 100) : 0,
            ])->values();
        }, collect());
    }

    /* ---------------------------------------------------------------
     | Applications by Status
     |---------------------------------------------------------------*/

    /**
     * The status bars. "Pending" is split into whether anyone has acted yet,
     * which is the distinction the design draws and the one an administrator
     * actually cares about — an application nobody has touched is a different
     * problem from one mid-chain.
     *
     * @return Collection<int, array{label: string, count: int, tone: string}>
     */
    public function applicationsByStatus(): Collection
    {
        return $this->safely('applications', function () {
            $decided = ApprovalHistory::select('application_id')->distinct();

            return collect([
                [
                    'label' => 'Draft',
                    'count' => Application::where('status', Application::STATUS_DRAFT)->count(),
                    'tone' => 'muted',
                ],
                [
                    'label' => 'Awaiting First Action',
                    'count' => Application::where('status', Application::STATUS_PENDING)
                        ->whereNotIn('id', $decided)->count(),
                    'tone' => 'warn',
                ],
                [
                    'label' => 'Under Review',
                    'count' => Application::where('status', Application::STATUS_PENDING)
                        ->whereIn('id', $decided)->count(),
                    'tone' => 'info',
                ],
                [
                    'label' => 'Approved',
                    'count' => Application::where('status', Application::STATUS_APPROVED)->count(),
                    'tone' => 'good',
                ],
                [
                    'label' => 'Rejected',
                    'count' => Application::where('status', Application::STATUS_REJECTED)->count(),
                    'tone' => 'critical',
                ],
            ]);
        }, collect());
    }

    /* ---------------------------------------------------------------
     | Health tiles
     |---------------------------------------------------------------*/

    protected function databaseHealth(): array
    {
        try {
            $start = microtime(true);
            DB::connection()->getPdo();
            DB::select('select 1');
            $ms = round((microtime(true) - $start) * 1000);

            return ['label' => 'Database', 'value' => 'Healthy', 'note' => "{$ms}ms round trip",
                'tone' => 'good', 'icon' => 'database'];
        } catch (\Throwable $e) {
            return ['label' => 'Database', 'value' => 'Unreachable', 'note' => 'Check the container',
                'tone' => 'critical', 'icon' => 'database'];
        }
    }

    protected function queueHealth(): array
    {
        try {
            $failed = DB::table('failed_jobs')->count();
            $queued = DB::table('jobs')->count();

            return [
                'label' => 'Queue',
                'value' => $failed === 0 ? 'Healthy' : $failed.' failed',
                'note' => $queued === 0 ? 'Nothing waiting' : $queued.' waiting',
                'tone' => $failed === 0 ? 'good' : 'critical',
                'icon' => 'stack',
            ];
        } catch (\Throwable $e) {
            return ['label' => 'Queue', 'value' => 'Unknown', 'note' => 'No queue tables',
                'tone' => 'muted', 'icon' => 'stack'];
        }
    }

    protected function storageHealth(): array
    {
        try {
            $path = storage_path('app');
            $free = @disk_free_space($path);
            $total = @disk_total_space($path);

            if (! $free || ! $total) {
                return ['label' => 'Storage', 'value' => '—', 'note' => 'Not measurable here',
                    'tone' => 'muted', 'icon' => 'disk'];
            }

            $usedPct = (int) round((($total - $free) / $total) * 100);

            return [
                'label' => 'Storage',
                'value' => $usedPct.'% used',
                'note' => $this->bytes($free).' free',
                'tone' => $usedPct >= 90 ? 'critical' : ($usedPct >= 75 ? 'warn' : 'good'),
                'icon' => 'disk',
            ];
        } catch (\Throwable $e) {
            return ['label' => 'Storage', 'value' => '—', 'note' => 'Unavailable',
                'tone' => 'muted', 'icon' => 'disk'];
        }
    }

    protected function activeUsersTile(): array
    {
        try {
            // Anyone whose session touched the app in the last quarter hour.
            $active = DB::table('sessions')
                ->where('last_activity', '>', now()->subMinutes(15)->timestamp)
                ->count();

            return ['label' => 'Active Users', 'value' => (string) $active, 'note' => 'Last 15 minutes',
                'tone' => 'info', 'icon' => 'people'];
        } catch (\Throwable $e) {
            return ['label' => 'Active Users', 'value' => '—', 'note' => 'No session table',
                'tone' => 'muted', 'icon' => 'people'];
        }
    }

    /* ---------------------------------------------------------------
     | Helpers
     |---------------------------------------------------------------*/

    protected function bytes(float $b): string
    {
        foreach (['B', 'KB', 'MB', 'GB', 'TB'] as $unit) {
            if ($b < 1024) {
                return round($b, $unit === 'B' ? 0 : 1).' '.$unit;
            }
            $b /= 1024;
        }

        return round($b, 1).' PB';
    }

    /** @return Collection<int, \App\Modules\Core\Support\AttendanceReading> */
    protected function latestAttendance(SuppliesAttendance $source): Collection
    {
        return $this->remember('latestAttendance', fn () => $source->latestPerStudent());
    }
}
