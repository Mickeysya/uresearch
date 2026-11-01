<?php

namespace App\Modules\Chloe\Assistant;

use App\Modules\Chloe\Models\CandidacyAppeal;
use App\Modules\Chloe\Models\CandidacyAppealApproval;
use App\Modules\Chloe\Models\CandidacyReminderLog;
use App\Modules\Chloe\Models\StudyCandidacy;
use App\Modules\Chloe\Services\CandidacyAppealService;
use App\Modules\Chloe\Support\CandidacyAppealPresenter as P;
use App\Modules\Chloe\Support\CandidacyDismissalList;
use App\Modules\Chloe\Support\CandidacyOperationsQuery as Ops;
use App\Modules\Chloe\Support\CandidacyPortalQuery;
use App\Modules\Chloe\Support\CandidacyReminderEngine;
use App\Modules\Chloe\Support\CandidacyStaffDirectory;
use App\Modules\Chloe\Support\CandidacyStatistics as Stats;
use App\Modules\Chloe\Support\StudentDirectory;
use App\Modules\Chloe\Support\StudentStatus;
use App\Modules\Chloe\Support\StudentStatusReader;
use App\Modules\Core\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * The Study Candidacy data answers. Read-only and live; every number comes from the SAME query or class the page itself uses (CandidacyOperationsQuery, CandidacyPortalQuery,
 * CandidacyStatistics, CandidacyDismissalList, CandidacyAppealService::eligibilityProblems, StudentStatusReader), so an answer can never disagree with a page.
 * A student only ever reads their own record; an approver only the appeals named on them (CandidacyPortalQuery); CGS the aggregates. The model never sees what is returned here.
 *
 * Each method returns ['outcome' => 'answered'|'asked_back', 'text' => ..., 'links' => [...], 'resultCount' => ?int].
 */
final class CandidacyLookups
{
    public const WINDOWS = ['this_month' => 'this month', 'last_month' => 'last month', 'last_30_days' => 'the last 30 days'];

    /** @return array<string, string> lookup name => method */
    public static function methods(): array
    {
        return [
            'cand_my_expiry' => 'myExpiry', 'cand_my_extension' => 'myExtension', 'cand_can_appeal' => 'canAppeal', 'cand_my_appeal' => 'myAppeal',
            'cand_my_return_reason' => 'myReturnReason', 'cand_next_reminder' => 'nextReminder', 'cand_my_softbound' => 'mySoftbound',
            'cand_ap_pending' => 'approverPending', 'cand_ap_oldest' => 'approverOldest', 'cand_ap_waiting_days' => 'approverWaiting', 'cand_ap_decided_month' => 'approverDecided', 'cand_ap_ongoing' => 'approverOngoing',
            'cand_cgs_pending_by_step' => 'cgsPendingByStep', 'cand_cgs_outcome_counts' => 'cgsOutcomes', 'cand_cgs_oldest' => 'cgsOldest', 'cand_cgs_waiting_10' => 'cgsWaiting10',
            'cand_cgs_avg_waiting' => 'cgsAverageWaiting', 'cand_cgs_expiring' => 'cgsExpiring', 'cand_cgs_no_action' => 'cgsNoAction', 'cand_cgs_twelve_of_twelve' => 'cgsTwelve',
            'cand_cgs_by_department' => 'cgsByDepartment', 'cand_cgs_reminders_sent' => 'cgsRemindersSent', 'cand_cgs_reminder_response' => 'cgsReminderResponse',
            'cand_cgs_dismissal_candidates' => 'cgsDismissalCandidates', 'cand_cgs_dismissed' => 'cgsDismissed', 'cand_cgs_in_progress' => 'cgsInProgress',
        ];
    }

    /** @param array<string,mixed> $parameters @return array{outcome:string,text:string,links?:array,resultCount?:?int,skipFooter?:bool} */
    public static function run(string $name, array $parameters, User $user): array
    {
        $method = self::methods()[$name] ?? null;

        return $method === null ? ['outcome' => 'asked_back', 'text' => 'I do not have that answer.'] : self::$method($user, $parameters);
    }

    // ------------------------------------------------------------------ helpers

    protected static function today(): CarbonImmutable
    {
        return Stats::today();
    }

    protected static function link(string $label, string $route, array $params = []): array
    {
        return ['label' => $label, 'url' => route($route, $params)];
    }

    protected static function date(?\DateTimeInterface $d): string
    {
        return $d ? CarbonImmutable::parse($d->format('Y-m-d'))->format('j M Y') : '—';
    }

    protected static function done(string $text, array $links = [], ?int $count = null, bool $skipFooter = false): array
    {
        return ['outcome' => 'answered', 'text' => $text, 'links' => $links, 'resultCount' => $count] + ($skipFooter ? ['skipFooter' => true] : []);
    }

    protected static function plural(int $n, string $one, string $many): string
    {
        return $n.' '.($n === 1 ? $one : $many);
    }

    protected static function candidacy(User $user): ?StudyCandidacy
    {
        return StudyCandidacy::where('student_id', $user->id)->first();
    }

    protected static function noRecord(): array
    {
        return self::done("I can't find a study candidacy record for you yet. If you think you should have one, please contact CGS.", [], 0, true);
    }

    /** @return array{0:CarbonImmutable,1:int} the expiry date and the days left (negative once past) */
    protected static function expiryOf(StudyCandidacy $c): array
    {
        $expiry = CarbonImmutable::parse($c->candidacy_expiry_date->toDateString(), Stats::TZ);

        return [$expiry, (int) self::today()->diffInDays($expiry, false)];
    }

    protected static function window(array $p, string $default): string
    {
        $w = $p['window'] ?? null;

        return is_string($w) && isset(self::WINDOWS[$w]) ? $w : $default;
    }

    /** @return array{0:CarbonImmutable,1:CarbonImmutable} from..to (inclusive dates) */
    protected static function range(string $window): array
    {
        $t = self::today();

        return match ($window) {
            'last_month' => [$t->startOfMonth()->subMonthNoOverflow(), $t->startOfMonth()->subDay()],
            'last_30_days' => [$t->subDays(29), $t],
            default => [$t->startOfMonth(), $t],
        };
    }

    // ------------------------------------------------------------------ student: own record only

    protected static function myExpiry(User $user, array $p): array
    {
        $c = self::candidacy($user);

        if (! $c) {
            return self::noRecord();
        }

        [$expiry, $days] = self::expiryOf($c);
        $when = $days > 0 ? self::plural($days, 'day', 'days').' from today' : ($days === 0 ? 'today' : self::plural(-$days, 'day', 'days').' ago');
        $text = $days >= 0
            ? "Your study candidacy expires on {$expiry->format('j M Y')} ({$when}). An appeal for more time must be submitted on or before that date."
            : "Your study candidacy expired on {$expiry->format('j M Y')} ({$when}). Please contact CGS about what happens next.";

        return self::done($text, [self::link('My candidacy', 'candidacy.status')], 1);
    }

    protected static function myExtension(User $user, array $p): array
    {
        $c = self::candidacy($user);

        if (! $c) {
            return self::noRecord();
        }

        $max = (int) config('candidacy.max_extension_months', 12);
        $used = $c->allowanceUsed();
        $left = $c->remainingAppealMonths();
        $text = "You have used {$used} of {$max} months of extension, so {$left} month(s) are left in total across all appeals.".($left < 1 ? ' That is the maximum: no further appeal can be submitted.' : '');

        return self::done($text, [self::link('My candidacy', 'candidacy.status')], 1);
    }

    protected static function canAppeal(User $user, array $p): array
    {
        $c = self::candidacy($user);
        $problems = app(CandidacyAppealService::class)->eligibilityProblems($c);

        if ($problems === []) {
            [$expiry] = self::expiryOf($c);
            $left = $c->remainingAppealMonths();

            return self::done("Yes. You can submit an appeal now, for between 1 and {$left} month(s). It must be submitted on or before {$expiry->format('j M Y')}.", [self::link('New appeal', 'candidacy.appeals.new')], 1);
        }

        return self::done("Not at the moment:\n- ".implode("\n- ", $problems), [self::link('My candidacy', 'candidacy.status')], count($problems), false);
    }

    protected static function myAppeal(User $user, array $p): array
    {
        $appeal = CandidacyAppeal::where('student_id', $user->id)->orderByDesc('id')->first();

        if (! $appeal) {
            return self::done('You have no appeal yet. You can start one from New appeal if you are eligible.', [self::link('New appeal', 'candidacy.appeals.new')], 0, true);
        }

        $link = [['label' => 'Open the appeal', 'url' => route('candidacy.appeals.show', ['appeal' => $appeal->id])]];

        if ($appeal->status === CandidacyAppeal::STATUS_DRAFT) {
            return self::done('Your appeal is still a draft: it has not been submitted yet, so nobody has it. You can continue it from New appeal.', [self::link('Continue the draft', 'candidacy.appeals.new')], 1, true);
        }

        $who = match ($appeal->current_stage) {
            'supervisor' => $appeal->supervisorProfile?->full_name,
            'chair' => $appeal->chairProfile?->full_name,
            'dean' => $appeal->deanProfile?->full_name,
            'cgs' => 'the Centre for Graduate Studies',
            default => null,
        };
        $text = "Appeal {$appeal->displayNo()}: {$appeal->requested_months} month(s) requested.\nStatus: ".P::statusLabel($appeal).".\nCurrent step: ".P::currentStep($appeal).'.'
            .($who ? "\nIt is with {$who}".($appeal->current_stage ? ', waiting '.self::plural($appeal->waitingDays(), 'day', 'days').' so far' : '').'.' : '');

        return self::done($text, $link, 1);
    }

    protected static function myReturnReason(User $user, array $p): array
    {
        $appeal = CandidacyAppeal::where('student_id', $user->id)->where('status', CandidacyAppeal::STATUS_RETURNED)->orderByDesc('id')->first();

        if (! $appeal) {
            return self::done('None of your appeals is returned at the moment, so there is nothing to fix.', [], 0, true);
        }

        $event = $appeal->events()->where('action', 'returned')->orderByDesc('id')->first();
        $reasons = $event ? P::reasonLabels($event->stage, 'returned', (array) $event->reason_codes) : '';
        $stage = $event ? (P::STAGES[$event->stage] ?? $event->stage) : 'an approver';
        $text = "Your appeal {$appeal->displayNo()} was returned by {$stage}.\nReason: ".($reasons !== '' ? $reasons : 'not recorded').'.'.($event?->remark ? "\nRemark: {$event->remark}" : '')
            ."\nFix what was asked and resubmit; it goes back to the step that returned it.";

        return self::done($text, [['label' => 'Open the appeal', 'url' => route('candidacy.appeals.show', ['appeal' => $appeal->id])]], 1);
    }

    protected static function nextReminder(User $user, array $p): array
    {
        $c = self::candidacy($user);

        if (! $c) {
            return self::noRecord();
        }

        $identity = StudentDirectory::fromUser($user);
        [$expiry] = self::expiryOf($c);
        $today = self::today();
        $engine = new CandidacyReminderEngine;
        $decision = $engine->decide(['identity' => $identity, 'candidacy' => $c, 'demo' => null, 'expiry' => $expiry, 'programme' => $user->programme], $today);

        if ($decision['action'] === 'stop') {
            $why = match ($decision['reason']) {
                'coursework' => 'reminders are for research students only',
                'expired' => 'your candidacy has already expired',
                'softbound submitted' => 'your Softbound submission is recorded',
                default => 'your status ('.$decision['reason'].') stops them',
            };

            return self::done("No more reminders will be sent: {$why}.", [], 0, true);
        }

        $paused = $decision['action'] === 'pause' ? ' Reminders are paused while your appeal is in review and continue if it is returned or decided.' : '';
        $logged = CandidacyReminderLog::where('candidacy_id', $c->id)->whereDate('expiry_date', $expiry->toDateString())->pluck('milestone')->map(fn ($m) => (int) $m)->all();
        $next = null;

        foreach (CandidacyReminderEngine::dates($expiry) as $months => $date) {
            if ($date->toDateString() >= $today->toDateString() && ! in_array($months, $logged, true)) {
                $next = [$months, $date];
                break;
            }
        }

        if (! $next) {
            return self::done('All three reminders (3, 2 and 1 month before your expiry date) have been sent or skipped.'.$paused, [], 0, true);
        }

        return self::done("Your next reminder is the {$next[0]}-month reminder, on {$next[1]->format('j M Y')} at 08:00, by e-mail and bell.".$paused, [], 1);
    }

    protected static function mySoftbound(User $user, array $p): array
    {
        $status = StudentStatusReader::for(StudentDirectory::fromUser($user));
        $demo = ($status->sources['softbound'] ?? null) === StudentStatus::OVERLAY ? ' (Demo data)' : '';

        if ($status->softboundSubmitted) {
            return self::done('Yes, your Softbound thesis is recorded'.($status->softboundDate ? ' on '.self::date($status->softboundDate) : '').'.'.$demo.' That ends your candidacy reminders.', [], 1, true);
        }

        return self::done('No Softbound submission is recorded for you yet.'.$demo.' It must be submitted for viva voce before the end of your candidature.', [], 0, true);
    }

    // ------------------------------------------------------------------ approver: only the appeals named on them

    protected static function profileOrNone(User $user): ?array
    {
        return CandidacyStaffDirectory::profileForUser($user)
            ? null
            : self::done('No appeals are named on you, so there is nothing to count.', [], 0, true);
    }

    protected static function approverPending(User $user, array $p): array
    {
        if ($none = self::profileOrNone($user)) {
            return $none;
        }

        $n = CandidacyPortalQuery::pending($user)->count();

        return self::done($n === 0 ? 'No appeal is pending your action.' : self::plural($n, 'appeal is', 'appeals are').' pending your action.', [self::link('Approval Portal', 'candidacy.portal.queue')], $n);
    }

    protected static function approverOldest(User $user, array $p): array
    {
        if ($none = self::profileOrNone($user)) {
            return $none;
        }

        $a = CandidacyPortalQuery::pending($user)->whereNotNull('stage_entered_at')->orderBy('stage_entered_at')->first();

        return $a
            ? self::done("The longest wait is appeal {$a->displayNo()}, waiting ".self::plural($a->waitingDays(), 'day', 'days').' at your step.', [['label' => 'Open the appeal', 'url' => route('candidacy.appeals.show', ['appeal' => $a->id])]], 1)
            : self::done('No appeal is pending your action.', [self::link('Approval Portal', 'candidacy.portal.queue')], 0);
    }

    protected static function approverWaiting(User $user, array $p): array
    {
        if ($none = self::profileOrNone($user)) {
            return $none;
        }

        $days = CandidacyPortalQuery::pending($user)->get()->map->waitingDays();
        $five = $days->filter(fn ($d) => $d >= 5)->count();
        $ten = $days->filter(fn ($d) => $d >= 10)->count();

        return self::done("Of the {$days->count()} appeal(s) waiting for you: {$five} have waited 5 days or more, and {$ten} have waited 10 days or more.", [self::link('Approval Portal', 'candidacy.portal.queue')], $days->count());
    }

    protected static function approverDecided(User $user, array $p): array
    {
        $profile = CandidacyStaffDirectory::profileForUser($user);

        if (! $profile) {
            return self::profileOrNone($user);
        }

        $from = self::today()->startOfMonth()->setTime(0, 0);
        $rows = CandidacyAppealApproval::where('profile_id', $profile->id)->where('decided_at', '>=', $from)->get();
        $approved = $rows->whereIn('decision', ['approved', 'verified'])->count();
        $returned = $rows->where('decision', 'returned')->count();

        return self::done("This month you approved {$approved} and returned {$returned} appeal(s).", [self::link('Approval Portal (History)', 'candidacy.portal.queue', ['tab' => 'history'])], $rows->count());
    }

    protected static function approverOngoing(User $user, array $p): array
    {
        if ($none = self::profileOrNone($user)) {
            return $none;
        }

        $n = CandidacyPortalQuery::ongoing($user)->count();

        return self::done(self::plural($n, 'appeal', 'appeals').' you are named on '.($n === 1 ? 'is' : 'are').' ongoing and not waiting for you now.', [self::link('Approval Portal (Ongoing)', 'candidacy.portal.queue', ['tab' => 'ongoing'])], $n);
    }

    // ------------------------------------------------------------------ CGS: aggregates, the same numbers as Operations, Insights and the Dismissal List

    protected static function opsCount(?string $status = null, ?int $waiting = null): int
    {
        return Ops::base(Ops::filters(array_filter(['status' => $status, 'waiting' => $waiting])))->count();
    }

    protected static function cgsInProgress(User $user, array $p): array
    {
        $n = Ops::counts(Ops::filters([]))['in_progress'];

        return self::done(self::plural($n, 'appeal is', 'appeals are').' in progress (with the Supervisor, Department Chair, CGS or Dean).', [self::link('Operations', 'candidacy.cgs.operations', ['group' => 'in_progress'])], $n);
    }

    protected static function cgsPendingByStep(User $user, array $p): array
    {
        $steps = ['with_supervisor' => 'Supervisor', 'with_chair' => 'Department Chair', 'with_cgs' => 'CGS verification', 'with_dean' => 'Dean'];
        $lines = [];
        $total = 0;

        foreach ($steps as $status => $label) {
            $n = self::opsCount($status);
            $total += $n;
            $lines[] = "- {$label}: {$n}";
        }

        $returned = self::opsCount('returned');

        return self::done("Appeals waiting at each step:\n".implode("\n", $lines)."\nTotal in progress: {$total}. Returned to the student (not waiting at a step): {$returned}.", [self::link('Operations', 'candidacy.cgs.operations')], $total);
    }

    protected static function cgsOutcomes(User $user, array $p): array
    {
        $c = Ops::counts(Ops::filters([]));
        $closed = self::opsCount('closed_expired');

        return self::done("Returned: {$c['returned']}. Approved: {$c['approved']}. Rejected (final): {$c['rejected']}. Closed expired: {$closed}.", [self::link('Operations', 'candidacy.cgs.operations')], $c['returned'] + $c['approved'] + $c['rejected'] + $closed);
    }

    protected static function cgsOldest(User $user, array $p): array
    {
        $rows = Ops::forGroup(Ops::filters([]), 'in_progress')->whereNotNull('stage_entered_at')->orderBy('stage_entered_at')->limit(5)->get();

        if ($rows->isEmpty()) {
            return self::done('No appeal is waiting at a step.', [self::link('Operations', 'candidacy.cgs.operations')], 0);
        }

        $links = [];
        $lines = $rows->map(function (CandidacyAppeal $a) use (&$links) {
            $links[] = ['label' => $a->displayNo(), 'url' => route('candidacy.appeals.show', ['appeal' => $a->id])];

            return "- {$a->displayNo()}: ".P::currentStep($a).', waiting '.self::plural($a->waitingDays(), 'day', 'days');
        });

        return self::done("The {$rows->count()} longest-waiting appeal(s):\n".$lines->implode("\n"), $links, $rows->count());
    }

    protected static function cgsWaiting10(User $user, array $p): array
    {
        $n = Ops::forGroup(Ops::filters([]), 'in_progress')->get()->filter(fn (CandidacyAppeal $a) => $a->waitingDays() >= 10)->count();

        return self::done($n === 0 ? 'No appeal has waited 10 days or more.' : self::plural($n, 'appeal has', 'appeals have').' waited 10 days or more at their current step.', [self::link('Operations (waiting 10+ days)', 'candidacy.cgs.operations', ['group' => 'in_progress', 'waiting' => 10])], $n);
    }

    protected static function cgsAverageWaiting(User $user, array $p): array
    {
        $steps = ['with_supervisor' => 'Supervisor', 'with_chair' => 'Department Chair', 'with_cgs' => 'CGS verification', 'with_dean' => 'Dean'];
        $lines = [];

        foreach ($steps as $status => $label) {
            $days = CandidacyAppeal::where('status', $status)->get()->map->waitingDays();
            $lines[] = "- {$label}: ".($days->isEmpty() ? 'nothing waiting' : number_format($days->avg(), 1).' days on average ('.$days->count().' waiting)');
        }

        return self::done("Average waiting time at the current step:\n".implode("\n", $lines), [self::link('Operations', 'candidacy.cgs.operations')], 4);
    }

    protected static function cgsExpiring(User $user, array $p): array
    {
        $rows = Stats::population();
        $within = fn (int $m) => $rows->filter(fn ($r) => $r['expiry'] <= self::today()->addMonthsNoOverflow($m)->toDateString())->count();
        $asked = isset($p['months']) && is_numeric($p['months']) && in_array((int) $p['months'], [1, 2, 3], true) ? (int) $p['months'] : null;
        $text = $asked
            ? 'Students whose candidacy expires within '.self::plural($asked, 'month', 'months').': '.$within($asked).'.'
            : 'Students whose candidacy expires within 1 month: '.$within(1).'; within 2 months: '.$within(2).'; within 3 months: '.$within(3).'.';

        return self::done($text.' (Research students with an active candidacy.)', [self::link('Candidacy Insights', 'candidacy.cgs.insights')], $within($asked ?? 3));
    }

    protected static function cgsNoAction(User $user, array $p): array
    {
        $summary = Stats::summary(Stats::population());
        $none = array_sum(array_column($summary['bands'], 'none'));

        return self::done("Of the {$summary['within3']} students expiring within 3 months, {$none} have taken no action yet (no Softbound, no appeal in review and no returned appeal).", [self::link('Candidacy Insights', 'candidacy.cgs.insights')], $none);
    }

    protected static function cgsTwelve(User $user, array $p): array
    {
        $max = (int) config('candidacy.max_extension_months', 12);
        $n = Stats::population()->filter(fn ($r) => $r['monthsUsed'] >= $max)->count();

        return self::done(self::plural($n, 'student has', 'students have')." used all {$max} of {$max} months of extension, so no further appeal is possible.", [self::link('Candidacy Insights', 'candidacy.cgs.insights')], $n);
    }

    protected static function cgsByDepartment(User $user, array $p): array
    {
        $rows = CandidacyAppeal::where('status', '!=', CandidacyAppeal::STATUS_DRAFT)->with('department')->get()->groupBy(fn ($a) => $a->department?->name ?? 'No department')->map->count()->sortDesc();

        if ($rows->isEmpty()) {
            return self::done('There are no submitted appeals yet.', [], 0, true);
        }

        return self::done("Submitted appeals by department:\n".$rows->map(fn ($n, $d) => "- {$d}: {$n}")->implode("\n"), [self::link('Operations', 'candidacy.cgs.operations')], $rows->sum());
    }

    protected static function reminderCount(string $window): int
    {
        [$from, $to] = self::range($window);

        return CandidacyReminderLog::where('status', CandidacyReminderLog::SENT)->where('sent_at', '>=', $from->setTime(0, 0))->where('sent_at', '<=', $to->setTime(23, 59, 59))->count();
    }

    protected static function cgsRemindersSent(User $user, array $p): array
    {
        $w = isset($p['window']) ? self::window($p, 'this_month') : null;
        $text = $w
            ? 'Reminders sent '.self::WINDOWS[$w].': '.self::reminderCount($w).'.'
            : 'Reminders sent this month: '.self::reminderCount('this_month').'; last month: '.self::reminderCount('last_month').'.';

        return self::done($text, [self::link('Candidacy Insights', 'candidacy.cgs.insights')], self::reminderCount($w ?? 'this_month'));
    }

    protected static function cgsReminderResponse(User $user, array $p): array
    {
        $rows = collect(Stats::reminderRows());
        $answered = $rows->where('responded', true)->count();

        return self::done($rows->isEmpty()
            ? 'No reminders were sent in the last six months.'
            : "{$answered} of {$rows->count()} reminders (".round(100 * $answered / $rows->count()).'%) were followed by a Softbound submission or an appeal within 30 days (last six months and this month).', [self::link('Candidacy Insights', 'candidacy.cgs.insights')], $rows->count());
    }

    protected static function cgsDismissalCandidates(User $user, array $p): array
    {
        $c = CandidacyDismissalList::counts(CandidacyDismissalList::candidates(), CandidacyDismissalList::filters([]));

        return self::done("Dismissal candidates today: {$c['candidates']}. Not yet exported: {$c['not_exported']}. Exported, awaiting the Registry: {$c['exported']}.", [self::link('Dismissal List', 'candidacy.cgs.dismissals')], $c['candidates']);
    }

    protected static function cgsDismissed(User $user, array $p): array
    {
        $w = self::window($p, 'this_month');
        [$from, $to] = self::range($w);
        $n = collect(Stats::dismissedRows())->filter(fn ($r) => $r['date'] >= $from->toDateString() && $r['date'] <= $to->toDateString())->count();

        return self::done('Students dismissed '.self::WINDOWS[$w].': '.$n.'.', [self::link('Dismissal Upload', 'candidacy.cgs.dismissal-upload')], $n);
    }
}
