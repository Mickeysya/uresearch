<?php

namespace App\Modules\Core\Services\Concerns;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApprovalHistory;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use Illuminate\Support\Collection;

/**
 * The scaffolding every dashboard service shares.
 *
 * StudentDashboard, CgsDashboard and AdminDashboard are three different
 * screens, but they are all built the same way: run one query per panel,
 * survive a query that throws, memoise anything two panels both ask for, and
 * express a figure as "total, and how it moved since last month". Those four
 * behaviours were written out three times, identically, and had already begun
 * to drift -- CgsDashboard::latestRecords() and
 * AdminDashboard::latestAttendance() were the same query under two names
 * (both now gone; the survivor is ReadsAttendance::latestPerStudent()).
 *
 * Adding a fourth dashboard should mean writing its panels, not re-deriving
 * how a panel holds itself together.
 */
trait BuildsPanels
{
    /**
     * Panels whose data could not be read this request, keyed by panel name.
     * The view renders a held skeleton for these rather than an empty state --
     * "nothing to show" and "could not load" mean different things to someone
     * looking at their own record.
     *
     * @var array<string, true>
     */
    protected array $failed = [];

    /**
     * Per-request memo. Several panels routinely want the same rows, and a
     * dashboard is one page load -- there is nothing to invalidate.
     *
     * @var array<string, mixed>
     */
    protected array $cache = [];

    /**
     * Run a panel's query, and if it throws, mark the panel unavailable and
     * fall back rather than 500-ing the whole dashboard. One dead module or
     * one bad query should cost that panel, not the page.
     *
     * @template T
     *
     * @param  callable(): T  $fn
     * @param  T  $fallback
     * @return T
     */
    protected function safely(string $panel, callable $fn, mixed $fallback): mixed
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            $this->failed[$panel] = true;
            report($e);

            return $fallback;
        }
    }

    /**
     * Hold a panel's skeleton without an exception having been thrown --
     * for "the module that feeds this is not installed", which is not an
     * error but still leaves the panel nothing real to draw.
     */
    protected function markUnavailable(string $panel): void
    {
        $this->failed[$panel] = true;
    }

    /** @return array<string, true> */
    public function unavailable(): array
    {
        return $this->failed;
    }

    /**
     * Memoise a value for the rest of the request.
     *
     * array_key_exists rather than ??=, because null is a real answer here:
     * "this student has no attendance record" must be cached too, or every
     * panel that asks re-runs the same empty query.
     *
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    protected function remember(string $key, callable $fn): mixed
    {
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        return $this->cache[$key] = $fn();
    }

    /**
     * Run a count three ways: all time (the headline), this month, and last
     * month (the "vs last month" delta).
     *
     * Returns a null delta rather than a fake 0% when last month had nothing
     * to compare against -- a brand-new portal has no trend, and showing
     * "up 0%" would imply it measured one. The views render nothing for null.
     *
     * @param  callable(?\Illuminate\Support\Carbon, ?\Illuminate\Support\Carbon): int  $counter
     * @return array{count: int, delta: ?float}
     */
    protected function withTrend(callable $counter): array
    {
        $startOfThis = now()->startOfMonth();
        $startOfLast = now()->subMonthNoOverflow()->startOfMonth();

        $total = $counter(null, null);
        $thisMonth = $counter($startOfThis, null);
        $lastMonth = $counter($startOfLast, $startOfThis);

        return [
            'count' => $total,
            'delta' => $lastMonth > 0
                ? round((($thisMonth - $lastMonth) / $lastMonth) * 100, 1)
                : null,
        ];
    }

    /** The shape every trend figure falls back to, so a dead query still renders. */
    protected function noTrend(): array
    {
        return ['count' => 0, 'delta' => null];
    }

    /* -----------------------------------------------------------------
     | Activity feed sources.
     |
     | The CGS and admin feeds draw the same rows and phrase them
     | differently -- which is why the queries live here and the wording
     | stays in each dashboard. When they drifted before, it was over which
     | rows appeared at all, not over how they read.
     |------------------------------------------------------------------*/

    /** @return Collection<int, ApprovalHistory> */
    protected function recentDecisions(int $limit): Collection
    {
        return ApprovalHistory::with('application.student', 'approver')
            ->latest('created_at')->limit($limit)->get()
            ->filter(fn ($h) => $h->application && $h->application->student);
    }

    /** @return Collection<int, Application> */
    protected function recentSubmissions(int $limit): Collection
    {
        return Application::with('student')
            ->whereNotNull('submitted_at')
            ->latest('submitted_at')->limit($limit)->get()
            ->filter(fn ($a) => $a->student);
    }

    /** @return Collection<int, User> */
    protected function recentEnrolments(int $limit): Collection
    {
        return User::where('role', Role::STUDENT)
            ->latest('created_at')->limit($limit)->get();
    }

    /**
     * Interleave any number of already-mapped feeds, newest first.
     *
     * @param  Collection<int, array{at: mixed}>  ...$feeds
     * @return Collection<int, array<string, mixed>>
     */
    protected function mergeFeed(int $limit, Collection ...$feeds): Collection
    {
        return collect($feeds)
            ->reduce(fn (Collection $all, Collection $f) => $all->concat($f), collect())
            ->sortByDesc(fn ($row) => $row['at'])
            ->take($limit)
            ->values();
    }
}
