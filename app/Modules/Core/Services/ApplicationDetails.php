<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\Contracts\DescribesApplicationDetails;
use App\Modules\Core\Contracts\SuppliesDecisionDates;
use App\Modules\Core\Contracts\SuppliesOverdueStatus;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApprovalHistory;
use App\Modules\Core\Support\WorkingDayCalendar;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * Everything the student's application details page shows, for any module,
 * without naming one.
 *
 *   header         module label, reference, title (summary()), status with
 *                  the current holder in words, expected decision date
 *                  (SuppliesDecisionDates) or, when the student must act,
 *                  that action (DescribesApplicationProgress)
 *   progress       WorkflowEngine::progress() -- the application's own
 *                  chain, skipped stages included -- with who acted, when,
 *                  and working days against each stage's target
 *                  (DescribesApplicationDetails::stageTargetDays())
 *   what next      DescribesApplicationProgress['next'], otherwise generic
 *   timeline       submission + approval_history + the module's own events
 *   your details   DescribesApplicationDetails::details() (optional)
 *   documents      the student's uploads, and the issued document
 *   help           config('tracking.help')
 *
 * Working days are counted on Core\Support\WorkingDayCalendar, the same
 * calendar a module's charter uses, so "4 of 3 working days" here and
 * "overdue" on the list can never disagree.
 */
class ApplicationDetails
{
    public function __construct(
        protected ApplicationTracker $tracker,
        protected WorkflowEngine $engine,
        protected WorkingDayCalendar $calendar,
    ) {}

    /** @return array<string, mixed> */
    public function for(Application $application, array $back): array
    {
        $application->loadMissing(['history' => fn ($q) => $q->orderBy('created_at')->orderBy('id'), 'history.approver', 'documents']);

        $summary = $this->tracker->summarise($application);
        $module = $summary['module'];
        $described = $summary['described'];
        $about = $module instanceof DescribesApplicationDetails ? $module : null;
        $pending = $application->status === Application::STATUS_PENDING;

        $steps = $this->steps($application, $about);
        $current = collect($steps)->firstWhere('state', 'current');
        $issued = $about?->issuedDocument($application);

        return [
            'application' => $application,
            'back' => route('applications.index', $back),
            'label' => $module->label(),
            'category' => $summary['category'],
            'title' => $module->summary($application),
            'reference' => $application->reference(),
            'status' => $application->status,
            'holder' => $current['label'] ?? null,
            'expected' => $pending && $module instanceof SuppliesDecisionDates ? $module->expectedDecisionDate($application) : null,
            'overdue' => $pending && $module instanceof SuppliesOverdueStatus && $module->decisionIsOverdue($application),
            'needs_student' => $summary['needs_student'],
            'primary' => $summary['needs_student'] ? collect($described['actions'] ?? [])->firstWhere('primary', true) : null,
            'steps' => $steps,
            'next' => $described['next'] ?? $this->next($application, $current, $summary),
            'timeline' => $this->timeline($application, $about),
            'details' => $about?->details($application) ?? [],
            'uploads' => $application->documents
                ->reject(fn ($doc) => isset($issued['document']) && $doc->is($issued['document']))
                ->values(),
            'issued' => $issued,
            'rejection' => $application->status === Application::STATUS_REJECTED ? $application->history->where('decision', 'rejected')->last() : null,
            'help' => (array) config('tracking.help', []),
        ];
    }

    /**
     * The chain, each stage with who acted and when, and working days
     * against its target where the module promises one.
     *
     * @return array<int, array{label: string, state: string, who: ?string, when: ?string, timing: ?string, late: bool}>
     */
    protected function steps(Application $application, ?DescribesApplicationDetails $about): array
    {
        $history = $application->history;
        $steps = [];

        foreach ($this->engine->progress($application) as $step) {
            $stage = $step['stage'];
            $row = $history->where('stage_key', $stage->key)->last();
            $target = $about?->stageTargetDays($application, $stage->key);
            $base = ['label' => $stage->label, 'state' => $step['state'], 'who' => null, 'when' => null, 'timing' => null, 'late' => false];

            $steps[] = match ($step['state']) {
                'completed', 'rejected' => array_merge($base, [
                    'who' => $row?->approver?->name,
                    'when' => trim(Str::ucfirst((string) $row?->decision).' '.$row?->created_at->format('j M Y')),
                ], $this->timing($this->enteredAt($application, $row), $row?->created_at, $target)),
                'current' => array_merge($base, [
                    'when' => $application->status === Application::STATUS_RETURNED
                        ? 'Returned to you '.$history->last()?->created_at->format('j M Y')
                        : 'Waiting since '.$this->enteredAt($application, null)->format('j M Y'),
                ], $application->status === Application::STATUS_RETURNED ? [] : $this->timing($this->enteredAt($application, null), CarbonImmutable::now(), $target)),
                'skipped' => array_merge($base, ['when' => 'Not required for this application']),
                default => array_merge($base, ['when' => 'Next'.($target !== null ? ' · target '.$target.' working '.Str::plural('day', $target) : '')]),
            };

            if ($step['state'] === 'rejected') {
                break;
            }
        }

        return $steps;
    }

    /** When the application reached the stage $decision closed (or, for null, the stage it is on now). */
    protected function enteredAt(Application $application, ?ApprovalHistory $decision): CarbonImmutable
    {
        $before = $application->history
            ->filter(fn (ApprovalHistory $h) => ! $decision || $h->created_at->lt($decision->created_at) || ($h->created_at->eq($decision->created_at) && $h->id < $decision->id))
            ->last();

        return CarbonImmutable::instance(collect([$application->submitted_at, $before?->created_at])->filter()->max() ?? $application->created_at ?? now());
    }

    /** "2 of 3 working days", "14 working days · target 2" (late), or plain "5 working days". */
    protected function timing(CarbonInterface $from, ?CarbonInterface $to, ?int $target): array
    {
        if (! $to) {
            return [];
        }

        $taken = $this->calendar->workingDaysBetween($this->calendar->onOrAfter($from), $to);
        $days = fn (int $n) => $n.' working '.Str::plural('day', $n);

        if ($target === null) {
            return ['timing' => $days($taken), 'late' => false];
        }

        return $taken > $target
            ? ['timing' => $days($taken).' · target '.$target, 'late' => true]
            : ['timing' => $taken.' of '.$days($target), 'late' => false];
    }

    /** One or two plain sentences, always ending with whether the student must act. */
    protected function next(Application $application, ?array $current, array $summary): string
    {
        return match ($application->status) {
            Application::STATUS_APPROVED => 'Your application is complete. You don\'t need to do anything else.',
            Application::STATUS_REJECTED => 'This application is closed and will not move further. If you still need it, start a new application; otherwise you don\'t need to do anything.',
            Application::STATUS_RETURNED => 'It has been sent back to you for changes. It cannot move on until you make them and resubmit, so this needs you.',
            default => ($current ? $current['label'].' will review it next.' : 'It is being reviewed.')
                .' You don\'t need to do anything right now; you will be notified when it moves.',
        };
    }

    /**
     * Newest first: submission, every decision (with the reviewer's remarks
     * as a quote), and the module's own events.
     *
     * @return array<int, array{date: CarbonInterface, title: string, detail: ?string, quote: ?string, tone: string}>
     */
    protected function timeline(Application $application, ?DescribesApplicationDetails $about): array
    {
        $events = [];

        if ($application->submitted_at) {
            $events[] = ['date' => $application->submitted_at, 'title' => 'Submitted', 'detail' => null, 'quote' => null, 'tone' => 'info'];
        }

        foreach ($application->history as $row) {
            $by = trim(($row->approver?->name ?? '').' ('.$row->stage_label.')');

            $events[] = [
                'date' => $row->created_at,
                'title' => match ($row->decision) {
                    'rejected' => 'Rejected by '.$by,
                    'returned' => 'Returned to you by '.$by,
                    default => Str::ucfirst($row->decision).' by '.$by,
                },
                'detail' => null,
                'quote' => $row->remarks ?: null,
                'tone' => match ($row->decision) {
                    'rejected' => 'critical',
                    'returned' => 'warn',
                    'approved' => 'good',
                    default => 'info',
                },
            ];
        }

        foreach ($about?->timelineEvents($application) ?? [] as $event) {
            // The module's own keys win; the defaults only fill gaps.
            $events[] = $event + ['detail' => null, 'quote' => null, 'tone' => 'info'];
        }

        // Newest first; on the same timestamp the later-recorded event
        // first, so "Submitted" never sits above a decision made with it.
        foreach ($events as $i => &$event) {
            $event['order'] = $i;
        }
        unset($event);
        usort($events, fn ($a, $b) => [$b['date'], $b['order']] <=> [$a['date'], $a['order']]);

        return $events;
    }
}
