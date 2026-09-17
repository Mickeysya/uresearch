<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApprovalHistory;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\Concerns\BuildsPanels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What every dashboard for someone who decides things has in common.
 *
 * A Chair and a Supervisor answer the same first question -- what is on my
 * desk, and how long has the worst of it been there -- and then diverge: a
 * Chair files examiner panels, a Supervisor is accountable for named
 * students. This holds the first part so the second can be the only thing
 * either subclass writes.
 *
 * SCOPE, AND WHAT IS DELIBERATELY MISSING. Every figure here is the person's
 * own desk. Nothing is department-wide or cohort-wide, because
 * `WorkflowEngine::queue()` is role-scoped rather than person-scoped (the open
 * decision in TODO.md), so a figure presented as "mine" would quietly be a
 * portal-wide one. Better absent than wrong.
 */
abstract class ApproverDashboard
{
    use BuildsPanels;

    /** Waiting longer than this is called out. Matches the queue's own tones. */
    public const OVERDUE_DAYS = 14;

    public const CRITICAL_DAYS = 30;

    public function __construct(
        protected User $user,
        protected ModuleRegistry $registry,
    ) {}

    /**
     * One entry per queue: the module, how many are on it, and how long the
     * oldest has waited.
     *
     * @return Collection<int, array{label: string, count: int, oldest: ?int, route: ?string}>
     */
    public function queues(): Collection
    {
        return $this->remember('queues', fn () => $this->safely('queues', function () {
            return collect($this->registry->queuesForRole($this->user->role))
                ->map(function (array $q) {
                    $rows = Application::query()
                        ->where('module_type', $q['module']->key())
                        ->where('current_stage', $q['stage']->key)
                        ->where('status', Application::STATUS_PENDING);

                    $oldest = (clone $rows)->min('submitted_at');

                    return [
                        'label' => $q['module']->label(),
                        'count' => $rows->count(),
                        // Carbon 3's diffInDays is SIGNED and returns a float,
                        // so now()->diffInDays($past) is negative and every
                        // wait reads as fine. Measured from the date forward.
                        'oldest' => $oldest ? (int) Carbon::parse($oldest)->diffInDays() : null,
                        'route' => route($q['module']->queueRoute(), ['stage' => $q['stage']->key]),
                    ];
                })
                ->sortByDesc('count')
                ->values();
        }, collect()));
    }

    public function awaitingMe(): int
    {
        return (int) $this->queues()->sum('count');
    }

    /**
     * How long the longest-waiting application has sat, in days. The figure an
     * approver is actually judged on, and the one a per-queue count cannot
     * show: "2 waiting" reads very differently at 3 days and at 40.
     */
    public function longestWait(): ?int
    {
        return $this->queues()->pluck('oldest')->filter(fn ($d) => $d !== null)->max();
    }

    /**
     * How many have been waiting longer than a fortnight.
     *
     * Distinct from longestWait(), which is one row: a desk with a single
     * 40-day straggler and one with nineteen of them report the same longest
     * wait and are not the same problem.
     *
     * Read off ageingProfile() rather than counted again -- it is already
     * memoised for the chart, and two queries that must agree are two
     * queries that can disagree.
     */
    public function overdueCount(): int
    {
        return (int) $this->ageingProfile()
            ->whereIn('tone', ['warn', 'critical'])
            ->sum('count');
    }

    /** What this person has actually decided in the last 30 days. */
    public function decidedRecently(): array
    {
        return $this->safely('decided', function () {
            $rows = ApprovalHistory::where('approver_id', $this->user->id)
                ->where('created_at', '>=', now()->subDays(30));

            return [
                'total' => (clone $rows)->count(),
                'rejected' => (clone $rows)->where('decision', 'rejected')->count(),
            ];
        }, ['total' => 0, 'rejected' => 0]);
    }

    /**
     * Everything pending on any stage this person owns, as a query.
     *
     * Null when they own no stages at all, which is not the same as an empty
     * result and would otherwise become `where(nothing)` — a query matching
     * every pending application in the portal.
     */
    protected function pendingOnMyStages(): ?\Illuminate\Database\Eloquent\Builder
    {
        $mine = collect($this->registry->queuesForRole($this->user->role));

        if ($mine->isEmpty()) {
            return null;
        }

        return Application::query()
            ->where('status', Application::STATUS_PENDING)
            ->where(function ($outer) use ($mine) {
                foreach ($mine as $q) {
                    $outer->orWhere(fn ($w) => $w
                        ->where('module_type', $q['module']->key())
                        ->where('current_stage', $q['stage']->key));
                }
            });
    }

    /**
     * The rows closest to breaching, oldest first. The triage list.
     *
     * @return Collection<int, Application>
     */
    public function oldestWaiting(int $limit = 5): Collection
    {
        return $this->safely('oldest', fn () => $this->pendingOnMyStages()
            ?->with('student')
            ->orderBy('submitted_at')
            ->limit($limit)
            ->get() ?? collect(), collect());
    }

    /**
     * How long everything on the desk has been waiting, in four bands.
     *
     * This is the chart these screens earn. A count per module is what the
     * generic approver dashboard drew, and for five or seven queues it is a
     * row of mostly-empty categories that answers nothing. The question a
     * Chair or a Supervisor actually has is not "which module" but "how bad
     * is the backlog", and that is a shape: a fat green bar is a healthy
     * desk, a fat red one is not, and it reads the same whether you own two
     * queues or ten.
     *
     * Bands match the tones the queue rows and the stat cards already use, so
     * the same number is the same colour everywhere on the screen.
     *
     * @return Collection<int, array{label: string, short: string, tone: string, count: int}>
     */
    public function ageingProfile(): Collection
    {
        return $this->remember('ageing', fn () => $this->safely('ageing', function () {
            $bands = [
                ['label' => 'Up to a week', 'short' => '0–7d', 'tone' => 'good', 'max' => 7, 'count' => 0],
                ['label' => 'One to two weeks', 'short' => '8–14d', 'tone' => 'info', 'max' => self::OVERDUE_DAYS, 'count' => 0],
                ['label' => 'Two weeks to a month', 'short' => '15–30d', 'tone' => 'warn', 'max' => self::CRITICAL_DAYS, 'count' => 0],
                ['label' => 'Over a month', 'short' => '30d+', 'tone' => 'critical', 'max' => PHP_INT_MAX, 'count' => 0],
            ];

            $query = $this->pendingOnMyStages();

            foreach ($query ? $query->pluck('submitted_at') : collect() as $submitted) {
                // (int) because Carbon 3 hands back a float, and signed the
                // other way round if you diff from now().
                $days = $submitted ? (int) Carbon::parse($submitted)->diffInDays() : 0;

                foreach ($bands as $i => $band) {
                    if ($days <= $band['max']) {
                        $bands[$i]['count']++;
                        break;
                    }
                }
            }

            return collect($bands);
        }, collect()));
    }

    /**
     * How many applications ENDED at a decision of theirs.
     *
     * The figure that means most to a final approver: the Dean is the last
     * stage on international travel and on an RPD appeal, so their approval
     * is the one that finishes the thing. Mid-chain it is mostly their
     * rejections, which is also worth knowing -- either way it is "the buck
     * stopped with me", and it is the same figure for every role rather than
     * a card that only appears for one.
     *
     * "Latest history row is mine, and the application is no longer pending."
     * MAX(id) per application rather than a timestamp: two decisions can
     * share a second, and the id is what actually orders them.
     */
    public function finalisedByMe(): int
    {
        return $this->safely('decided', function () {
            $latest = ApprovalHistory::query()
                ->selectRaw('MAX(id)')
                ->groupBy('application_id');

            return ApprovalHistory::query()
                ->whereIn('id', $latest)
                ->where('approver_id', $this->user->id)
                ->whereHas('application', fn ($q) => $q->whereIn('status', [
                    Application::STATUS_APPROVED,
                    Application::STATUS_REJECTED,
                ]))
                ->count();
        }, 0);
    }

    /**
     * This person's own decision trail, newest first.
     *
     * Every other panel looks forward at what is waiting. This is the only
     * one that looks back, which is what an approver is asked about when
     * someone queries an outcome -- and the Dean, who signs the last stage
     * of several chains, is asked most.
     *
     * Scoped to their own rows: this is not an audit screen.
     *
     * @return Collection<int, ApprovalHistory>
     */
    public function myRecentDecisions(int $limit = 6): Collection
    {
        return $this->safely('decisions', fn () => ApprovalHistory::query()
            ->where('approver_id', $this->user->id)
            ->with('application.student')
            ->latest('id')
            ->limit($limit)
            ->get()
            ->filter(fn (ApprovalHistory $h) => $h->application !== null)
            ->values(), collect());
    }

    /**
     * Anything blocking this person, declared by the modules themselves --
     * Core cannot know that approving a hardbound thesis needs a signature on
     * file. See Contracts\ProvidesDashboardAlerts.
     *
     * @return array<int, array<string, mixed>>
     */
    public function alerts(): array
    {
        return $this->safely('alerts', fn () => $this->registry->alertsFor($this->user), []);
    }

    /** The queue with the most on it, for the headline card to point at. */
    public function busiestQueueRoute(): ?string
    {
        return $this->queues()->firstWhere('count', '>', 0)['route'] ?? null;
    }

    /**
     * The queue holding the longest-waiting row. The one card link that does
     * real work: it goes straight to the thing that has waited longest.
     */
    public function longestWaitRoute(): ?string
    {
        return $this->queues()
            ->filter(fn ($q) => $q['oldest'] !== null)
            ->sortByDesc('oldest')
            ->first()['route'] ?? null;
    }

    /** The tone a waiting time should be shown in. Shared with the queue screen. */
    public static function toneFor(?int $days): string
    {
        return match (true) {
            $days === null => 'quiet',
            $days >= self::CRITICAL_DAYS => 'critical',
            $days >= self::OVERDUE_DAYS => 'warn',
            default => 'quiet',
        };
    }
}
