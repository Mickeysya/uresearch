<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\Contracts\DescribesApplicationProgress;
use App\Modules\Core\Contracts\SuppliesDecisionDates;
use App\Modules\Core\Contracts\SuppliesOverdueStatus;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApprovalHistory;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\WorkingDayCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Everything "Track My Applications" shows, for one student, for every
 * module -- without naming any of them.
 *
 * Where each part comes from:
 *   tab            ModuleRegistry::categoryFor() (the module's own CATEGORY)
 *   stepper        WorkflowEngine::progress(), i.e. the module's
 *                  stages($application), so a stored snapshot chain is
 *                  respected; each done step's action and date from
 *                  approval_history
 *   waiting time   Core\Support\WorkingDayCalendar, from when the
 *                  application reached its current stage
 *   expected date  Contracts\SuppliesDecisionDates (optional)
 *   overdue        Contracts\SuppliesOverdueStatus (optional)
 *   footer/buttons Contracts\DescribesApplicationProgress (optional),
 *                  otherwise a generic sentence from the chain and history
 *
 * Two passes, to keep the page cheap: every application gets a light
 * summary (tab, status, "needs you", last activity) for the counts and the
 * filters; only the page's own cards (at most PER_PAGE) are built in full.
 */
class ApplicationTracker
{
    public const PER_PAGE = 20;

    /** The status filters, in display order. */
    public const STATUSES = [
        'all' => 'All statuses',
        'needs-you' => 'Needs you',
        'in-progress' => 'In progress',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
    ];

    /** @var array<string, string> the list's tab/status, carried on View details */
    protected array $back = [];

    public function __construct(
        protected ModuleRegistry $registry,
        protected WorkflowEngine $engine,
        protected WorkingDayCalendar $calendar,
    ) {}

    /**
     * @return array{tabs: array, tab: string, statuses: array, status: string, cards: LengthAwarePaginator, total: int, tabTotal: int}
     */
    public function page(User $student, string $tab, string $status, int $page, string $path, array $query): array
    {
        $summaries = $student->applications()
            ->where('status', '!=', Application::STATUS_DRAFT)
            ->with(['history' => fn ($q) => $q->orderBy('created_at')->orderBy('id'), 'history.approver', 'documents'])
            ->get()
            // A row whose module is no longer registered cannot be drawn;
            // leaving it out beats taking the whole page down.
            ->filter(fn (Application $a) => $this->registry->has($a->module_type))
            ->map(fn (Application $a) => $this->summarise($a))
            ->sortByDesc(fn (array $s) => $s['activity']->getTimestamp())
            ->values();

        // View details carries the list's tab and filter, so the details
        // page's breadcrumb returns the student to exactly this view.
        $this->back = array_filter(['tab' => $tab === 'all' ? null : $tab, 'status' => $status === 'all' ? null : $status]);

        $tabs = $this->tabs($summaries);
        $tab = $tabs->has($tab) ? $tab : 'all';
        $inTab = $tab === 'all' ? $summaries : $summaries->where('category.key', $tab)->values();

        $status = array_key_exists($status, self::STATUSES) ? $status : 'all';
        $statuses = collect(self::STATUSES)->map(fn ($label, $key) => [
            'key' => $key,
            'label' => $label,
            'count' => $this->filter($inTab, $key)->count(),
        ])->values()->all();

        $filtered = $this->filter($inTab, $status);
        $onPage = $filtered->forPage($page, self::PER_PAGE)->values()
            ->map(fn (array $s) => $this->card($s));

        return [
            'tabs' => $tabs->values()->all(),
            'tab' => $tab,
            'statuses' => $statuses,
            'status' => $status,
            'cards' => new LengthAwarePaginator($onPage, $filtered->count(), self::PER_PAGE, $page, ['path' => $path, 'query' => $query]),
            'total' => $summaries->count(),
            'tabTotal' => $inTab->count(),
        ];
    }

    /** All, then each category the student has applications in (by label, Other last), with counts. */
    protected function tabs(Collection $summaries): Collection
    {
        $categories = $summaries->pluck('category')->unique('key')
            ->sortBy(fn (array $c) => [$c['key'] === 'other' ? 1 : 0, $c['label']])
            ->mapWithKeys(fn (array $c) => [$c['key'] => $c + ['count' => $summaries->where('category.key', $c['key'])->count()]]);

        return collect(['all' => ['key' => 'all', 'label' => 'All', 'tone' => 'neutral', 'count' => $summaries->count()]])
            ->merge($categories);
    }

    protected function filter(Collection $summaries, string $status): Collection
    {
        return match ($status) {
            'needs-you' => $summaries->where('needs_student', true)->values(),
            'in-progress' => $summaries->whereIn('application.status', [Application::STATUS_PENDING, Application::STATUS_RETURNED])->values(),
            'approved' => $summaries->where('application.status', Application::STATUS_APPROVED)->values(),
            'rejected' => $summaries->where('application.status', Application::STATUS_REJECTED)->values(),
            default => $summaries,
        };
    }

    /**
     * The light pass: enough for tabs, counts, filters and order. Public
     * so the details page judges "needs you" exactly as the list does.
     */
    public function summarise(Application $application): array
    {
        $module = $application->module();
        $described = $module instanceof DescribesApplicationProgress ? $module->describeProgress($application) : [];

        $needs = $application->status === Application::STATUS_RETURNED
            || ($described['needs_student'] ?? false)
            || ($application->status === Application::STATUS_REJECTED && ($described['resubmission_expected'] ?? false));

        $lastDecision = $application->history->last();

        return [
            'application' => $application,
            'module' => $module,
            'category' => $this->registry->categoryFor($application->module_type),
            'described' => $described,
            'needs_student' => (bool) $needs,
            'activity' => CarbonImmutable::instance(
                collect([$application->submitted_at, $application->created_at, $lastDecision?->created_at])->filter()->max() ?? now()
            ),
        ];
    }

    /** The full card, for the page's own applications only. */
    protected function card(array $summary): array
    {
        /** @var Application $application */
        $application = $summary['application'];
        $module = $summary['module'];
        $described = $summary['described'];
        $pending = $application->status === Application::STATUS_PENDING;

        $expected = $pending && $module instanceof SuppliesDecisionDates ? $module->expectedDecisionDate($application) : null;
        $overdue = $pending && $module instanceof SuppliesOverdueStatus && $module->decisionIsOverdue($application);
        $steps = $this->steps($application, $overdue);
        $current = collect($steps)->firstWhere('state', 'current');

        return [
            'application' => $application,
            'label' => $module->label(),
            'category' => $summary['category'],
            'title' => $module->summary($application),
            'reference' => $application->reference(),
            'document_reference' => $described['reference'] ?? null,
            'status' => $application->status,
            'needs_student' => $summary['needs_student'],
            'expected' => $expected,
            'overdue' => $overdue,
            // Approved: the whole chain is done, so the card keeps to its
            // outcome, as in the design. The detail page still shows it.
            'steps' => $application->status === Application::STATUS_APPROVED ? [] : $steps,
            // The calm overdue note is Core's, added whoever wrote the
            // sentence, so a module's own wording can never drop it.
            'sentence' => ($described['sentence'] ?? $this->sentence($application, $current))
                .($overdue ? ' This step is taking longer than usual; you will be notified as soon as it moves.' : ''),
            'actions' => $this->actions($application, $module, $described, $summary['needs_student']),
        ];
    }

    /**
     * The stepper: "Submitted", then this application's own chain. A done
     * stage carries its decision and date from approval_history; the
     * current one how long it has been waiting; a rejected one ends the
     * chain there.
     *
     * @return array<int, array{label: string, state: string, detail: string, overdue: bool}>
     */
    protected function steps(Application $application, bool $overdue): array
    {
        $submitted = $application->submitted_at ?? $application->created_at;
        $steps = [['label' => 'Submitted', 'state' => 'completed', 'detail' => $submitted?->format('j M') ?? '', 'overdue' => false]];

        foreach ($this->engine->progress($application) as $step) {
            $stage = $step['stage'];
            $row = $this->lastDecision($application, $stage->key);

            if ($step['state'] === 'rejected') {
                $steps[] = ['label' => $stage->label, 'state' => 'rejected', 'detail' => 'Rejected '.($row?->created_at->format('j M') ?? ''), 'overdue' => false];
                break;
            }

            $steps[] = match ($step['state']) {
                'completed' => ['label' => $stage->label, 'state' => 'completed', 'detail' => trim(Str::ucfirst((string) $row?->decision).' '.$row?->created_at->format('j M')), 'overdue' => false],
                'current' => ['label' => $stage->label, 'state' => 'current', 'detail' => $this->waiting($application), 'overdue' => $overdue],
                'skipped' => ['label' => $stage->label, 'state' => 'skipped', 'detail' => 'Not required', 'overdue' => false],
                default => ['label' => $stage->label, 'state' => 'upcoming', 'detail' => 'Next', 'overdue' => false],
            };
        }

        return $steps;
    }

    protected function lastDecision(Application $application, string $stageKey): ?ApprovalHistory
    {
        return $application->history->where('stage_key', $stageKey)->last();
    }

    /** "Waiting 20 working days", or "Since 30 Oct" in its first couple of days; "Returned 3 Nov" when back with the student. */
    protected function waiting(Application $application): string
    {
        $last = $application->history->last();

        if ($application->status === Application::STATUS_RETURNED) {
            return 'Returned '.($last?->created_at->format('j M') ?? '');
        }

        $since = CarbonImmutable::instance(collect([$application->submitted_at, $last?->created_at])->filter()->max() ?? now());
        $days = $this->calendar->workingDaysBetween($this->calendar->onOrAfter($since), CarbonImmutable::now());

        return $days >= 2 ? "Waiting {$days} working days" : 'Since '.$since->format('j M');
    }

    /** One plain sentence: where it is, and whether the student must act. */
    protected function sentence(Application $application, ?array $current): string
    {
        $last = $application->history->last();

        return match ($application->status) {
            Application::STATUS_APPROVED => 'Approved'.($last ? ' by '.$last->stage_label.' on '.$last->created_at->format('j M Y') : '').'.',
            Application::STATUS_REJECTED => ($rejection = $application->history->where('decision', 'rejected')->last()) && $rejection->remarks
                ? 'Reason from '.$rejection->stage_label.': “'.$rejection->remarks.'”'
                : 'Not approved'.($rejection ? ' by '.$rejection->stage_label.' on '.$rejection->created_at->format('j M Y') : '').'.',
            Application::STATUS_RETURNED => 'Returned to you'.($last ? ' by '.$last->stage_label : '')
                .($last?->remarks ? ': “'.$last->remarks.'”' : '.').' Please make the changes and resubmit it.',
            default => 'Now with '.($current['label'] ?? 'the reviewer').'. No action needed from you.',
        };
    }

    /**
     * The card's buttons: the module's own (its action first when the
     * student must act), Start a new application on a rejection, and View
     * details always last.
     *
     * @return array<int, array{label: string, url: string, primary: bool}>
     */
    protected function actions(Application $application, $module, array $described, bool $needsStudent): array
    {
        $actions = array_map(fn (array $a) => ['label' => $a['label'], 'url' => $a['url'], 'primary' => (bool) ($a['primary'] ?? false)], $described['actions'] ?? []);

        if ($application->status === Application::STATUS_REJECTED && ($create = $module->createRoute())) {
            $actions[] = ['label' => 'Start a new application', 'url' => route($create), 'primary' => true];
        }

        $details = ['label' => 'View details', 'url' => route('applications.show', ['application' => $application] + $this->back), 'primary' => false];

        // Returned with nothing from the module: the details page is where
        // the student acts, so it becomes the primary button.
        if ($application->status === Application::STATUS_RETURNED && ! collect($actions)->contains('primary', true)) {
            $details = ['label' => 'Review and resubmit', 'url' => $details['url'], 'primary' => true];
        }

        // Only one primary: the first one asked for.
        $seenPrimary = false;
        foreach ($actions as &$action) {
            $action['primary'] = $action['primary'] && ! $seenPrimary;
            $seenPrimary = $seenPrimary || $action['primary'];
        }

        return [...$actions, $details];
    }
}
