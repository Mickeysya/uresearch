<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApprovalHistory;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\Concerns\BuildsPanels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Everything the Chair of Department dashboard puts on screen.
 *
 * Same shape as StudentDashboard and CgsDashboard: one named method per
 * panel, every query through safely() so a dead source costs that panel
 * rather than the page.
 *
 * WHY A CHAIR NEEDS ITS OWN SCREEN. The generic approver dashboard builds one
 * stat card per queue, and a Chair owns five stages, so it renders six cards
 * of which five normally read zero, plus a bar chart of five categories with
 * one bar in it. Neither tells a Chair the only thing they are actually
 * measured on: whether anything has been sitting on their desk too long.
 *
 * SCOPE, AND WHAT IS DELIBERATELY MISSING. Every figure here is the Chair's
 * own desk -- their stages, their filed nominations -- and none of it is
 * department-wide. `WorkflowEngine::queue()` does not scope by department
 * yet (it is an open team decision, see TODO.md "Scope approver queues to the
 * right people"), so a figure called "my department" would quietly be a
 * portal-wide figure. Better absent than wrong. When scoping lands, this is
 * where the department panels go.
 */
class ChairDashboard
{
    use BuildsPanels;

    /** Waiting longer than this is called out. Matches the queue's own tones. */
    public const OVERDUE_DAYS = 14;

    public const CRITICAL_DAYS = 30;

    public function __construct(
        protected User $chair,
        protected ModuleRegistry $registry,
    ) {}

    /* -----------------------------------------------------------------
     | The stages this Chair owns. Everything else is derived from it.
     |------------------------------------------------------------------*/

    /**
     * One entry per queue: the module, the stage, how many are on it and how
     * long the oldest has waited.
     *
     * @return Collection<int, array{label: string, count: int, oldest: ?int, route: ?string}>
     */
    public function queues(): Collection
    {
        return $this->remember('queues', fn () => $this->safely('queues', function () {
            return collect($this->registry->queuesForRole($this->chair->role))
                ->map(function (array $q) {
                    $rows = Application::query()
                        ->where('module_type', $q['module']->key())
                        ->where('current_stage', $q['stage']->key)
                        ->where('status', Application::STATUS_PENDING);

                    $oldest = (clone $rows)->min('submitted_at');

                    return [
                        'label' => $q['module']->label(),
                        'count' => $rows->count(),
                        // Carbon 3's diffInDays is SIGNED, so now()->diffInDays($past)
                        // is negative and every wait read as "fine". Measured from
                        // the date forward instead.
                        'oldest' => $oldest ? (int) Carbon::parse($oldest)->diffInDays() : null,
                        'route' => route($q['module']->queueRoute(), ['stage' => $q['stage']->key]),
                    ];
                })
                ->sortByDesc('count')
                ->values();
        }, collect()));
    }

    /* -----------------------------------------------------------------
     | The four headline figures.
     |------------------------------------------------------------------*/

    public function awaitingMe(): int
    {
        return (int) $this->queues()->sum('count');
    }

    /**
     * How long the longest-waiting application has sat, in days. This is the
     * figure a Chair is actually judged on, and the one the old dashboard had
     * nowhere to put.
     */
    public function longestWait(): ?int
    {
        return $this->queues()->pluck('oldest')->filter(fn ($d) => $d !== null)->max();
    }

    /** What this Chair has actually decided in the last 30 days. */
    public function decidedRecently(): array
    {
        return $this->safely('decided', function () {
            $rows = ApprovalHistory::where('approver_id', $this->chair->id)
                ->where('created_at', '>=', now()->subDays(30));

            return [
                'total' => (clone $rows)->count(),
                'rejected' => (clone $rows)->where('decision', 'rejected')->count(),
            ];
        }, ['total' => 0, 'rejected' => 0]);
    }

    /* -----------------------------------------------------------------
     | Panels.
     |------------------------------------------------------------------*/

    /**
     * The rows closest to breaching, oldest first. The triage list: five rows
     * a Chair can clear before anything else.
     *
     * @return Collection<int, Application>
     */
    public function oldestWaiting(int $limit = 5): Collection
    {
        return $this->safely('oldest', function () use ($limit) {
            $mine = collect($this->registry->queuesForRole($this->chair->role));

            if ($mine->isEmpty()) {
                return collect();
            }

            return Application::query()
                ->where('status', Application::STATUS_PENDING)
                ->where(function ($outer) use ($mine) {
                    foreach ($mine as $q) {
                        $outer->orWhere(fn ($w) => $w
                            ->where('module_type', $q['module']->key())
                            ->where('current_stage', $q['stage']->key));
                    }
                })
                ->with('student')
                ->orderBy('submitted_at')
                ->limit($limit)
                ->get();
        }, collect());
    }

    /**
     * Panels this Chair filed themselves.
     *
     * A Chair files an examiner panel and it leaves their hands entirely --
     * no queue of theirs, no tracking page (that is students only). Until
     * this panel, a filed nomination was simply invisible to the person who
     * filed it. `TODO.md` carries it as the one thing a Chair should be able
     * to reach and could not.
     *
     * @return Collection<int, Application>
     */
    public function myNominations(int $limit = 5): Collection
    {
        return $this->safely('nominations', fn () => Application::query()
            ->where('submitted_by_id', $this->chair->id)
            ->whereNot('student_id', $this->chair->id)
            ->with('student')
            ->latest('submitted_at')
            ->limit($limit)
            ->get(), collect());
    }

    /**
     * Anything blocking this Chair, declared by the modules themselves --
     * Core cannot know that approving a hardbound thesis needs a signature
     * on file. See Contracts\ProvidesDashboardAlerts.
     *
     * @return array<int, array<string, mixed>>
     */
    public function alerts(): array
    {
        return $this->safely('alerts', fn () => $this->registry->alertsFor($this->chair), []);
    }

    /**
     * "with the Academic Executive" for each nomination still in flight.
     *
     * Resolved here rather than in the view because it needs the engine, and
     * a Blade template calling into the workflow engine per row is how a
     * dashboard ends up with a query it cannot see.
     *
     * @param  Collection<int, Application>  $applications
     * @return array<int, string>
     */
    public function stageLabels(Collection $applications, WorkflowEngine $engine): array
    {
        return $this->safely('nominations', fn () => $applications
            ->mapWithKeys(fn (Application $a) => [$a->id => $engine->currentStage($a)?->label])
            ->filter()
            ->all(), []);
    }

    /**
     * The queue with the most on it, for the headline card to point at.
     * Null when every queue is empty, and the card then shows a flat pill
     * rather than a link to nothing.
     */
    public function busiestQueueRoute(): ?string
    {
        return $this->queues()->firstWhere('count', '>', 0)['route'] ?? null;
    }

    /**
     * The queue holding the longest-waiting row, sorted so that row is at the
     * top when you land. This is the one card whose link does real work: it
     * takes a Chair straight to the thing that has waited longest.
     */
    public function longestWaitRoute(): ?string
    {
        $queue = $this->queues()
            ->filter(fn ($q) => $q['oldest'] !== null)
            ->sortByDesc('oldest')
            ->first();

        return $queue['route'] ?? null;
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
