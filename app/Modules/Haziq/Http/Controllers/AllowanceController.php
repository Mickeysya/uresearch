<?php

namespace App\Modules\Haziq\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\DocumentStore;
use App\Modules\Core\Support\Role;
use App\Modules\Haziq\Exports\AllowanceEligibilityExport;
use App\Modules\Haziq\Models\AllowancePaymentConfirmation;
use App\Modules\Haziq\Models\AllowancePaymentLog;
use App\Modules\Haziq\Models\GaApplicationDetail;
use App\Modules\Haziq\Models\GraApplicationDetail;
use App\Modules\Haziq\Notifications\AllowancePaymentConfirmed;
use App\Modules\Haziq\Support\AllowanceLedger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Module 3 — Allowance Eligibility. A reporting view, not a workflow:
 * nobody submits anything here, it reads what Modules 1 and 2 (via
 * Support\StageGateEngine's daily job) already wrote to
 * allowance_payment_logs. Same "open route, controller scopes by role"
 * split as Stage Gate monitoring — a student sees only their own row,
 * everyone else (Admin/CGS/Supervisor/Senior Director) sees every
 * student, confirmed explicitly 2026-10-23.
 */
class AllowanceController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $month = $request->filled('month')
            ? Carbon::parse($request->query('month'))->startOfMonth()
            : Carbon::today()->startOfMonth();

        $serviceMonth = $this->serviceMonthFor($month);
        $today = Carbon::today()->startOfMonth();

        $scope = function ($query) use ($user) {
            if ($user->isStudent()) {
                $query->where('student_id', $user->id);
            } elseif ($user->role === Role::SUPERVISOR) {
                $query->whereHas('student', fn ($q) => $q->where('supervisor_id', $user->id));
            } elseif (! $user->isAdmin() && ! $user->isCgs() && $user->role !== Role::SENIOR_DIRECTOR_CGS) {
                abort(403);
            }
        };

        $laggedQuery = AllowancePaymentLog::query()->with(['student', 'application'])->where('payment_month', $serviceMonth);
        $scope($laggedQuery);
        $byApplication = $laggedQuery->get()->keyBy('application_id');

        // Only today's own calendar month can ever need the immediate-
        // display bypass below — a past or future $month filter has no
        // "today" to bypass toward.
        if ($month->equalTo($today)) {
            $todayQuery = AllowancePaymentLog::query()->with(['student', 'application'])->where('payment_month', $today);
            $scope($todayQuery);

            foreach ($todayQuery->get() as $todayLog) {
                if ($this->needsImmediateDisplay($todayLog)) {
                    $byApplication->put($todayLog->application_id, $todayLog);
                }
            }
        }

        $rows = $byApplication->values()->map(fn (AllowancePaymentLog $log) => $this->rowFor($log))
            ->concat($this->applicationsPendingFirstPay($month, $user));

        if ($status = $request->query('status')) {
            $rows = $rows->filter(fn (array $row) => $row['displayStatus'] === $status);
        }

        return view('haziq::allowance.index', [
            'rows' => $rows->values(),
            'month' => $month,
            'serviceMonth' => $serviceMonth,
            'canExport' => ! $user->isStudent(),
            'canConfirmPayment' => $user->isAdmin() || $user->role === Role::NON_EXEC_CGS,
            'trend' => $user->isStudent() ? $this->trendFor($user, $month) : null,
        ]);
    }

    /**
     * The one number the shared dashboard's "Postgraduate Progress" panel
     * needs — reuses the exact same month-resolution this page's own table
     * uses (the lag, the immediate-display bypass, the first-pay
     * placeholder) via a single real `AllowancePaymentLog` row where one
     * exists, so the dashboard card can never show a different figure
     * than this page would for the same student right now. Null when the
     * student has no qualifying application at all — the dashboard holds
     * that panel's skeleton rather than showing a false zero.
     *
     * @return array{amount: float|null, forMonth: string}|null
     */
    public function summaryFor(User $student): ?array
    {
        $month = Carbon::today()->startOfMonth();
        $serviceMonth = $this->serviceMonthFor($month);

        $todayLog = AllowancePaymentLog::where('student_id', $student->id)
            ->where('payment_month', $month)
            ->first();

        if ($todayLog && $this->needsImmediateDisplay($todayLog)) {
            return ['amount' => $this->rowFor($todayLog)['amount'], 'forMonth' => $month->format('M Y')];
        }

        $log = AllowancePaymentLog::where('student_id', $student->id)
            ->where('payment_month', $serviceMonth)
            ->first();

        if ($log) {
            return ['amount' => $this->rowFor($log)['amount'], 'forMonth' => $serviceMonth->format('M Y')];
        }

        $placeholder = $this->applicationsPendingFirstPay($month, $student)->first();

        if ($placeholder) {
            return ['amount' => 0.0, 'forMonth' => $serviceMonth->format('M Y')];
        }

        return null;
    }

    /**
     * Portal-wide count for the CGS/Admin dashboard's stat card — the same
     * `monthsAccruing >= 3` threshold this page's own anomaly chart buckets
     * by, just counted instead of charted, so the dashboard figure can
     * never disagree with what the chart shows if CGS clicks through to
     * it. Deliberately only looks at the ordinary lagged month, not the
     * immediate-display bypass rows index() also checks — a dashboard
     * count is a coarse "how many need a look," not a precise match for
     * every edge case the full table handles.
     */
    public function anomalyCount(): int
    {
        $serviceMonth = $this->serviceMonthFor(Carbon::today()->startOfMonth());

        return AllowancePaymentLog::where('payment_month', $serviceMonth)
            ->get()
            ->map(fn (AllowancePaymentLog $log) => $this->rowFor($log))
            ->filter(fn (array $row) => $row['monthsAccruing'] >= 3)
            ->count();
    }

    /**
     * Payroll lag, confirmed explicitly 2026-11-03, permanent and
     * unconditional — "macam payroll sebenar... SETIAP bulan display
     * adalah bayaran untuk bulan sebelumnya, selama-lamanya": whatever
     * calendar month a human is looking at, what's actually payable is
     * last month's completed service, never the month still in
     * progress. A brand-new student whose service starts this month
     * sees nothing yet for that reason alone — not a termination, not
     * withheld, just genuinely nothing earned to pay out until next
     * month closes the books on this one.
     *
     * Deliberately only shifts which AllowancePaymentLog row a given
     * calendar month resolves to — StageGateEngine's own date-driven
     * accrual state machine (statusAsOf(), ensureMonthlyLogs(), the
     * termination/reinstatement lag already built into
     * resumeReinstatementMonth()) is untouched and keeps operating in
     * real service-month time; an appeal approved today genuinely
     * affects today's service month regardless of which month a human
     * happens to be viewing on this screen.
     */
    protected function serviceMonthFor(Carbon $displayMonth): Carbon
    {
        return $displayMonth->copy()->subMonth();
    }

    /**
     * The one deliberate exception to the lag above, confirmed
     * explicitly 2026-11-03 after the lag itself turned out to defer
     * exactly this: a termination entering (`review_required`/
     * `determined` — CGS hasn't decided the partial month yet) or a
     * Case B resumption (`withheld`/`backdated` immediately following
     * a `forfeited` one — a prior debt resurfacing) both need to
     * surface the moment they happen, same calendar day, not wait for
     * next month's view. User: "allowance tertunggak boleh di settle
     * bulan appeal approve tu jugak" — a restored RM24,000 debt reading
     * as blank on the very page meant to show it defeats the point of
     * resuming it at all.
     *
     * Deliberately reuses rowFor()'s own `reviewable` flag rather than
     * re-deriving the same Case A/B detection a second time — Case A's
     * own row (Stage 1 already complete) never goes blank in the first
     * place, visible every month straight through a termination
     * regardless of the lag, so it never needed this bypass; checking
     * `reviewable` alone already excludes it correctly.
     */
    protected function needsImmediateDisplay(AllowancePaymentLog $todayLog): bool
    {
        return $this->rowFor($todayLog)['reviewable'];
    }

    /**
     * Which row an action button actually lands on for $application
     * right now — the same bypass as needsImmediateDisplay() above,
     * so confirmPayment() and setReviewAmount() can never target a
     * different row than the one index() is actually showing CGS at
     * that moment.
     */
    protected function resolveActingMonth(Application $application): Carbon
    {
        $today = Carbon::today()->startOfMonth();

        $todayLog = AllowancePaymentLog::where('application_id', $application->id)
            ->where('payment_month', $today)
            ->first();

        if ($todayLog && $this->needsImmediateDisplay($todayLog)) {
            return $today;
        }

        return $this->serviceMonthFor($today);
    }

    /**
     * Placeholder rows for students who exist and are already approved,
     * but whose service only started *this* display month — under the
     * payroll lag, their lookup month (last month) predates their own
     * service_start_date, so the normal log query above finds nothing
     * and they'd otherwise vanish from the table entirely. Confirmed
     * explicitly 2026-11-03: "die baru enroll 1/11/2026, takkan dapat
     * allowance 1/10/2026 tak logic patut shows 0 skang" — nothing owed
     * is correct, but disappearing from the list isn't; CGS still needs
     * to see the student with RM0.00, not wonder why they're not
     * listed at all. Only ever matches a service_start_date falling
     * inside $displayMonth itself — any earlier start already has a
     * real AllowancePaymentLog row and is picked up by the query above
     * instead; any later start hasn't been approved into this view yet.
     */
    protected function applicationsPendingFirstPay(Carbon $displayMonth, User $user): Collection
    {
        $range = [$displayMonth->copy()->startOfMonth(), $displayMonth->copy()->endOfMonth()];

        $gra = GraApplicationDetail::with('application.student')
            ->whereBetween('service_start_date', $range)
            ->whereHas('application', fn ($q) => $q->where('status', Application::STATUS_APPROVED))
            ->get();

        $ga = GaApplicationDetail::with('application.student')
            ->whereBetween('service_start_date', $range)
            ->whereHas('application', fn ($q) => $q->where('status', Application::STATUS_APPROVED))
            ->get();

        return $gra->concat($ga)
            ->filter(function ($detail) use ($user) {
                $application = $detail->application;

                if ($user->isStudent()) {
                    return $application->student_id === $user->id;
                }

                if ($user->role === Role::SUPERVISOR) {
                    return $application->student?->supervisor_id === $user->id;
                }

                return $user->isAdmin() || $user->isCgs() || $user->role === Role::SENIOR_DIRECTOR_CGS;
            })
            ->map(fn ($detail) => [
                'application' => $detail->application,
                'student' => $detail->application->student,
                'amount' => 0.0,
                'displayStatus' => 'eligible',
                'monthsAccumulating' => 0,
                'monthsAccruing' => 0,
                'candidatureStatus' => $detail->candidature_status,
                'confirmable' => false,
                'reviewable' => false,
                'pendingForNextMonth' => null,
                'pendingInput' => null,
                'periodMonth' => $this->serviceMonthFor($displayMonth),
            ])
            ->values();
    }

    /**
     * The student's own last 6 *service* months, reusing rowFor() for
     * each one so this can never drift from what the table itself
     * shows. Not a monitoring tool — just lets a student see their own
     * trajectory at a glance, same as the "visual chart" request,
     * rather than reading six month-dropdown clicks in a row.
     *
     * Deliberately labelled and looked up by service month directly —
     * NOT payout-shifted through serviceMonthFor() the way index()'s
     * table is. A trend line is read as history: each point should be
     * what that month's service actually settled to, not a number that
     * happens to land in a later calendar month. Confirmed explicitly
     * 2026-11-03 with a concrete example (service Aug/Sep/Oct withheld,
     * accumulating RM2,000/month): wanted "September 4000, October
     * 6000", i.e. each *service* month's own running total — not
     * September showing August's payout figure the way the main table
     * would frame it.
     *
     * The most recent point (this real calendar month) is always blank,
     * on purpose — that service month isn't even over yet, so there's
     * nothing settled to plot; same payroll principle as the lag
     * itself, just applied to "don't draw an unfinished data point"
     * instead of "don't pay it out yet."
     *
     * @return \Illuminate\Support\Collection<int, array{month: string, amount: float|null}>
     */
    protected function trendFor(User $user, Carbon $month): \Illuminate\Support\Collection
    {
        $today = Carbon::today()->startOfMonth();

        return collect(range(5, 0))
            ->map(fn (int $i) => $month->copy()->subMonthsNoOverflow($i))
            ->map(function (Carbon $serviceMonth) use ($user, $today) {
                $log = $serviceMonth->equalTo($today)
                    ? null
                    : AllowancePaymentLog::where('student_id', $user->id)
                        ->where('payment_month', $serviceMonth)
                        ->first();

                return [
                    'month' => $serviceMonth->format('M Y'),
                    'amount' => $log ? $this->rowFor($log)['amount'] : null,
                ];
            });
    }

    /**
     * Admin/CGS/Supervisor/Senior Director only (route middleware) — the
     * same month filter as index(), but only ever lists students whose
     * candidature is currently active. Confirmed explicitly 2026-10-23:
     * "admin sampai director leh dowload excel every month yang student
     * active je akan keluar" — a terminated student's row is excluded
     * entirely from the download, not merely shown in red.
     */
    public function export(Request $request)
    {
        $month = $request->filled('month')
            ? Carbon::parse($request->query('month'))->startOfMonth()
            : Carbon::today()->startOfMonth();

        $rows = AllowancePaymentLog::query()
            ->with(['student', 'application'])
            ->where('payment_month', $this->serviceMonthFor($month))
            ->get()
            ->map(fn (AllowancePaymentLog $log) => $this->rowFor($log))
            ->filter(fn (array $row) => $row['candidatureStatus'] === 'active')
            ->values();

        return Excel::download(
            new AllowanceEligibilityExport($rows),
            "allowance-eligibility-{$month->format('Y-m')}.xlsx"
        );
    }

    /**
     * One student/application's displayed row for the month $log belongs
     * to. Two unrelated mechanisms, kept deliberately separate:
     *
     * Stage 1 (RPD) withheld/backdated — unchanged since 2026-10-23: the
     * running total keeps growing every month after completion reveals
     * it, exactly like a still-withheld gate, until Admin or CGS
     * separately confirm they've actually paid it out
     * (AllowancePaymentConfirmation, with proof) — "kalau cgs or admin
     * mark complete payment... next month reset balik 2000. kalau tak
     * complete... jadi 26000."
     *
     * Termination (Stage 2+) — corrected 2026-10-28, reversing the
     * previous round's design: "the debt not growing lah... bulan
     * terminated will not get allowance" — a terminated month shows
     * *blank*, never an accumulating number, and is never back-paid.
     * The two edges of a termination are deliberately asymmetric, as of
     * 2026-11-03: the month termination itself *starts* in is still a
     * partial-month judgement call for CGS (StageGateEngine::
     * ensureMonthlyLogs() flags it 'review_required', CGS types in what
     * it's worth via setReviewAmount(), raw status 'determined', shown
     * blank until the following month). The month an appeal is approved
     * in, however, no longer works that way — see StageGateEngine::
     * resumeReinstatementMonth()'s own docblock for why: it resolves
     * immediately, on its own, same day, no CGS figure needed. Confirmed
     * explicitly: "biasa kan allowance bulan sebelum dapat first day of
     * the first month" — payroll pays this month's wage at the start of
     * next month, so an *entry*-side determined amount doesn't appear on
     * its own ('determined') row at all — it shows as payment_pending on
     * the *following* month's row, which is why this walk tracks the
     * previous row's raw status and amount, not just a running sum.
     *
     * Walks this application's whole log history up to and including
     * $log's month, since what shows on one row can depend on the row
     * immediately before it.
     */
    protected function rowFor(AllowancePaymentLog $log): array
    {
        $candidatureStatus = $this->candidatureStatusFor($log->application);

        $history = AllowancePaymentLog::where('application_id', $log->application_id)
            ->where('payment_month', '<=', $log->payment_month)
            ->orderBy('payment_month')
            ->get(['payment_month', 'status', 'amount', 'pending_addition']);

        $confirmedMonths = AllowancePaymentConfirmation::where('application_id', $log->application_id)
            ->pluck('confirmed_month')
            ->map(fn ($m) => Carbon::parse($m)->format('Y-m'))
            ->flip();

        $runningSum = 0.0; // Stage 1 withheld/backdated only
        $monthsAccumulating = 0; // total consecutive months unpaid, frozen or not
        // Of those, how many actually added money — excludes months frozen
        // by an ongoing Stage 2/3 termination (Case A: still visible, but
        // $rowAmount is written as 0 while frozen, see StageGateEngine::
        // ensureMonthlyLogs()). Confirmed explicitly 2026-11-03: a debt
        // that's been outstanding 38 months but only 12 of them actually
        // grew the total reads as misleadingly urgent without this split —
        // "38 ni sebenarnya overstated/misleading sikit sebab campur bulan
        // frozen dengan bulan betul-betul accruing."
        $monthsAccruing = 0;
        $previousStatus = null;
        $previousAmount = 0.0;
        $previousPendingAddition = 0.0;
        // Once a reinstatement (or an entry-side determined figure)
        // first defers a month's own contribution, every month after
        // it keeps the same one-month offset permanently, not just the
        // one hop — confirmed explicitly 2026-11-03: "lag berterusan
        // selamanya lepas reinstatement," Dec=14000, Jan=16000 (+Dec's),
        // Feb=18000 (+Jan's, still deferred), Mar=20000 (+Feb's)... A
        // row's own contribution never lands in its own display once
        // this is active; it always becomes $heldBack for the row
        // after. Cleared back to normal, immediate display only once
        // actually confirmed-paid.
        $laggedMode = false;
        $heldBack = 0.0;

        $amount = null; // null = blank
        $displayStatus = 'eligible';
        $reviewable = false;
        $confirmable = false;

        foreach ($history as $row) {
            $rowAmount = (float) $row->amount;
            $rowMonthKey = Carbon::parse($row->payment_month)->format('Y-m');
            $rowConfirmed = isset($confirmedMonths[$rowMonthKey]);

            if ($previousStatus === 'determined') {
                // Last month's CGS-determined payout is due *this*
                // calendar month regardless of what this row's own
                // status says — confirmed explicitly 2026-10-28:
                // "bulan berikutnya die akan nampak allowance pending
                // with the amount yang cgs setupkan" names the very next
                // month unconditionally, not "the next month that
                // happens to be eligible." A still-ongoing termination
                // right after a determined entry month shows this one
                // payout, then reverts to blank the month after.
                //
                // That determined figure might itself be a *resumed*
                // Stage 1 total, not just a flat one-off settlement — see
                // setReviewAmount(). Confirmed explicitly 2026-11-03:
                // "kalau payment tu tak complete lagi by cgs, next month
                // added up cuma bukan by allowance biasa dapat" — if this
                // row's own real status says Stage 1 is still actively
                // accruing, the running total folds this month's own
                // amount in and keeps growing from here on, the same way
                // an ordinary withheld run would; otherwise (resolved,
                // confirmed, or anything else) it's a one-off payout and
                // everything resets to zero after, exactly as before.
                $stillAccruing = in_array($row->status, ['withheld', 'backdated'], true) && ! $rowConfirmed;

                $amount = $previousAmount; // the determined figure itself, never this row's own on top — that part defers one more month, below

                if ($stillAccruing) {
                    $laggedMode = true;
                    $heldBack = $rowAmount;
                    $runningSum = $amount;
                } else {
                    $runningSum = 0.0;
                }

                $monthsAccumulating = $stillAccruing ? 1 : 0;
                $monthsAccruing = $stillAccruing ? 1 : 0;
                $displayStatus = $rowConfirmed ? 'eligible' : 'payment_pending';
                $reviewable = false;
                $confirmable = ! $rowConfirmed;
            } elseif (in_array($row->status, ['withheld', 'backdated'], true)) {
                // Stage 1's own accumulate-then-settle mechanic, entirely
                // unrelated to termination — unchanged, as long as
                // nothing has ever deferred ($laggedMode stays false).
                $runningSum += $heldBack;
                $heldBack = 0.0;

                if (! $laggedMode && $previousPendingAddition > 0.0) {
                    // The reinstatement month's queued figure arrives
                    // now — this is the moment the permanent lag begins.
                    // Confirmed explicitly 2026-11-03: "amount tu masuk
                    // next monthnya," and every month after keeps the
                    // same offset, not just this one hop.
                    $runningSum += $previousPendingAddition;
                    $laggedMode = true;
                }

                if ($laggedMode) {
                    // This row's own contribution doesn't land in its
                    // own display either — it becomes next month's
                    // $heldBack, continuing the permanent offset.
                    $heldBack = $rowAmount;
                } else {
                    $runningSum += $rowAmount;
                }

                $previousPendingAddition = 0.0;

                $monthsAccumulating++;
                if ($rowAmount > 0.0) {
                    $monthsAccruing++;
                }
                $amount = $runningSum;
                $displayStatus = $rowConfirmed || $monthsAccumulating === 0
                    ? 'eligible'
                    : ($row->status === 'withheld' ? 'withheld' : 'payment_pending');
                $confirmable = $displayStatus === 'payment_pending';

                // A reinstatement month resumed immediately by
                // StageGateEngine::resumeReinstatementMonth() still lets
                // CGS add a partial-month figure (held in
                // `pending_addition`, folded into *next* month's total,
                // not this one — see that column's migration) — but
                // only while it's still today's month and not the 1st
                // (no partial-month question on the 1st). Covers both
                // Case B (this row directly follows a 'forfeited' one)
                // and Case A (the preceding row is a frozen 'backdated'
                // one, amount still 0 — see resumeReinstatementMonth()'s
                // own docblock for why the two ended up needing the
                // same rule). The candidature_status check is what
                // tells a genuinely resumed Case A row apart from one
                // still mid-freeze during an ongoing, unappealed
                // termination — both look identical (backdated,
                // amount=0) in isolation, but only the former should
                // ever offer this action. Confirmed explicitly
                // 2026-11-03.
                $reviewable = $candidatureStatus === 'active'
                    && ($previousStatus === 'forfeited'
                        || ($previousStatus === 'backdated' && $previousAmount == 0.0))
                    && $rowMonthKey === Carbon::today()->format('Y-m')
                    && Carbon::today()->day !== 1;

                if ($rowConfirmed) {
                    $runningSum = 0.0;
                    $monthsAccumulating = 0;
                    $monthsAccruing = 0;
                    $laggedMode = false;
                    $heldBack = 0.0;
                }
            } elseif ($row->status === 'forfeited') {
                // An ongoing terminated month — never shown as a number,
                // never back-paid. Also ends any lag cycle still running
                // from before this termination; resumption computes a
                // fresh total of its own (AllowanceLedger::
                // priorWithheldTotal()), unrelated to this walk's own
                // $laggedMode/$heldBack state.
                $runningSum = 0.0;
                $monthsAccumulating = 0;
                $monthsAccruing = 0;
                $laggedMode = false;
                $heldBack = 0.0;
                $amount = null;
                $displayStatus = 'terminated';
                $reviewable = false;
                $confirmable = false;
            } elseif ($row->status === 'review_required') {
                // The month a termination *enters* only — CGS hasn't
                // said yet what (if anything) this partial month is
                // worth. The exit side no longer produces this status at
                // all (StageGateEngine::resumeReinstatementMonth()
                // resolves it to a real status directly), so a row never
                // reaches here for that reason any more.
                $runningSum = 0.0;
                $monthsAccumulating = 0;
                $monthsAccruing = 0;
                $laggedMode = false;
                $heldBack = 0.0;
                $amount = null;
                $displayStatus = 'review_required';
                $reviewable = true;
                $confirmable = false;
            } elseif ($row->status === 'determined') {
                // CGS has set the figure, but it doesn't appear here —
                // payroll pays it out at the start of next month (the
                // $previousStatus === 'determined' branch above, on the
                // row after this one). Still editable while it's still
                // this same calendar month — "die boleh edit balik
                // amount tu selagi tak masuk next monthnya," confirmed
                // explicitly 2026-11-03 — setReviewAmount() itself is
                // what actually closes that window once the calendar
                // moves on (it only ever targets *today's* month).
                $runningSum = 0.0;
                $monthsAccumulating = 0;
                $monthsAccruing = 0;
                $laggedMode = false;
                $heldBack = 0.0;
                $amount = null;
                $displayStatus = 'review_required';
                $reviewable = $rowMonthKey === Carbon::today()->format('Y-m');
                $confirmable = false;
            } else {
                // Genuinely ordinary 'eligible' — never violated, far
                // enough past a resolved transition that nothing is owed,
                // or a reinstatement resumeReinstatementMonth() resolved
                // straight back to 'eligible' (Stage 1 already settled
                // before the termination, nothing to carry over) —
                // trusted and shown directly either way, not
                // second-guessed just because the row before it happened
                // to be 'forfeited'.
                //
                // Still has to honour a queued pending_addition, same as
                // the withheld/backdated branch — found 2026-11-03: a
                // resumption whose underlying Stage Gate was always
                // 'eligible' (never actually backdated at the gate
                // level, only the log rows froze during the
                // termination) reverts straight to this branch the very
                // next month, and a CGS-set partial-month figure was
                // silently dropped on the floor rather than landing
                // here, since only the withheld/backdated branch ever
                // checked for one.
                $runningSum += $heldBack;
                $heldBack = 0.0;

                if (! $laggedMode && $previousPendingAddition > 0.0) {
                    $runningSum += $previousPendingAddition;
                    $laggedMode = true;
                }

                if ($laggedMode) {
                    // A real lagged figure is showing here, not just
                    // the ordinary flat stipend — CGS genuinely hasn't
                    // confirmed paying this out yet, same as any other
                    // confirmable row, so it needs the same button.
                    // Found 2026-11-03 asking what happens if CGS marks
                    // it complete: this branch hardcoded `confirmable
                    // = false` unconditionally, which would have made
                    // the real "Mark Payment Complete" button never
                    // appear for a lagged eligible row at all.
                    $heldBack = $rowAmount;
                    $amount = $runningSum;
                    $displayStatus = 'payment_pending';
                    $confirmable = ! $rowConfirmed;
                } else {
                    $amount = $rowAmount;
                    $displayStatus = 'eligible';
                    $confirmable = false;
                }

                $previousPendingAddition = 0.0;
                $reviewable = false;

                if ($rowConfirmed) {
                    $runningSum = 0.0;
                    $laggedMode = false;
                    $heldBack = 0.0;
                }
            }

            $previousStatus = $row->status;
            $previousAmount = $rowAmount;
            $previousPendingAddition = (float) ($row->pending_addition ?? 0);
        }

        // What CGS/Admin has already queued for *next* month, read
        // straight off $log itself (not the walk above) — never shown
        // to the student, only ever surfaced where the caller gates on
        // $canConfirmPayment. Confirmed explicitly 2026-11-03: "die akan
        // keluar ah value pada diorang tapi student tak nampak... die
        // boleh edit balik amount tu selagi tak masuk next monthnya."
        // Two different numbers: `pendingForNextMonth` is what will
        // actually show up next month (informational); `pendingInput`
        // is what to pre-fill the edit form with, which for a
        // 'determined' row is *not* the same number — that row's own
        // `amount` already has priorWithheldTotal() folded in, and
        // setReviewAmount() adds that same total again on every
        // resubmit, so the form has to show just CGS's own original
        // figure or re-saving it unchanged would double-count the prior
        // total. A resumed withheld/backdated row's `pending_addition`
        // is already CGS's raw figure either way.
        $pendingForNextMonth = null;
        $pendingInput = null;

        if ($log->status === 'determined') {
            $priorForThisRow = $this->priorWithheldTotal($log->application, Carbon::parse($log->payment_month)->startOfMonth());
            $pendingForNextMonth = (float) $log->amount;
            $pendingInput = $pendingForNextMonth - $priorForThisRow;
        } elseif (in_array($log->status, ['withheld', 'backdated'], true) && (float) ($log->pending_addition ?? 0) > 0.0) {
            $pendingForNextMonth = (float) $log->pending_addition;
            $pendingInput = $pendingForNextMonth;
        }

        return [
            'application' => $log->application,
            'student' => $log->student,
            'amount' => $amount,
            'displayStatus' => $displayStatus,
            'monthsAccumulating' => $monthsAccumulating,
            'monthsAccruing' => $monthsAccruing,
            'candidatureStatus' => $candidatureStatus,
            'confirmable' => $confirmable,
            'reviewable' => $reviewable,
            'pendingForNextMonth' => $pendingForNextMonth,
            'pendingInput' => $pendingInput,
            // The actual service month this row's own content belongs
            // to — not always $serviceMonth (index()'s lagged default),
            // since needsImmediateDisplay() can bypass straight to
            // today's own row instead. The view labels each row by
            // this, not the page-level $serviceMonth, so a bypassed row
            // never shows a misleading "for {last month}" next to
            // content that's actually this month's.
            'periodMonth' => Carbon::parse($log->payment_month),
        ];
    }

    /**
     * Admin or CGS (non_exec) only — confirmed explicitly 2026-10-23:
     * "cgs admin dua dua boleh buat." Records that the *current* running
     * total has actually been paid out, with proof, and notifies every
     * stakeholder plus the student. The confirmation's own effective
     * month is resolved by resolveActingMonth() — ordinarily one
     * calendar month behind real "today", per the payroll lag confirmed
     * 2026-11-03 (serviceMonthFor()'s own docblock), except for the
     * immediate-display bypass that same day (needsImmediateDisplay())
     * for a termination entry or a Case B resumption. Must resolve to
     * the exact same row index() is actually showing CGS, since the
     * "Mark Payment Complete" button this is called from lives inside
     * that row — a mismatch here would confirm a different month than
     * the one CGS is actually looking at.
     */
    public function confirmPayment(Request $request, Application $application, DocumentStore $documents): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->isAdmin() || $user->role === Role::NON_EXEC_CGS, 403);

        $request->validate(['proof' => DocumentStore::rules(true)]);

        $month = $this->resolveActingMonth($application);

        $log = AllowancePaymentLog::where('application_id', $application->id)
            ->where('payment_month', $month)
            ->firstOrFail();

        $row = $this->rowFor($log);

        abort_unless($row['confirmable'], 404, 'Nothing pending to confirm for this application this month.');

        $document = $documents->attach($application, $request->file('proof'), 'Proof of Payment');

        $confirmation = AllowancePaymentConfirmation::create([
            'application_id' => $application->id,
            'confirmed_month' => $month,
            'amount' => $row['amount'],
            'proof_document_id' => $document->id,
            'confirmed_by' => $user->id,
            'confirmed_at' => now(),
        ]);

        $application->loadMissing('student.supervisor');
        $application->student?->notify(new AllowancePaymentConfirmed($confirmation, forStudent: true));
        $this->paymentStakeholders($application)->each(
            fn (User $u) => $u->notify(new AllowancePaymentConfirmed($confirmation, forStudent: false))
        );

        return back()->with('status', 'Payment marked complete. '.number_format((float) $row['amount'], 2).' confirmed paid.');
    }

    /**
     * Admin or CGS (non_exec) only — the human judgement call for a
     * month split between active and terminated, never on the 1st
     * (never a partial month then, see resumeReinstatementMonth()).
     * Two different rows can reach here, confirmed explicitly
     * 2026-11-03:
     *
     *  - The month a termination *enters* (StageGateEngine::
     *    ensureMonthlyLogs() flags it 'review_required') — nothing was
     *    showing before this, so the figure follows the normal payroll
     *    lag: always writes 'determined' (never 'withheld'/'backdated'
     *    directly), amount = priorWithheldTotal() (Stage 1 may have
     *    been independently mid-accumulation right up to this
     *    termination, that obligation doesn't vanish just because
     *    Stage 2/3 also forfeits) + this figure, visible only on the
     *    *following* month's row (rowFor()'s own `$previousStatus ===
     *    'determined'` branch) — "sebab kan allowance bulan sebelum
     *    dapat bulan afternya."
     *  - The month an appeal was approved in, already resumed live by
     *    resumeReinstatementMonth() (status already 'withheld' or
     *    'backdated', showing priorWithheldTotal()/the frozen Case A
     *    total) — this month's own display stays exactly as it already
     *    is; CGS's figure is stored in the row's `pending_addition`
     *    instead of its `amount`, and only shows up folded into the
     *    *following* month's total, same payroll-lag rule as the entry
     *    side. Corrected explicitly 2026-11-03, reversing this same
     *    day's earlier "added immediately, same month" version: "amount
     *    tu masuk next monthnya" — rowFor()'s withheld/backdated branch
     *    is what actually folds a previous row's `pending_addition` in
     *    once the following row arrives. Only reachable for a row that
     *    is genuinely a reinstatement month: either immediately preceded
     *    by a 'forfeited' one (Case B), or immediately preceded by a
     *    frozen Case A row (`backdated`, `amount=0`) *and* candidature
     *    is currently active — that last check is what tells a real
     *    resumption apart from a month still mid-freeze during an
     *    ongoing, unappealed termination, which looks identical
     *    otherwise. Never reachable for an arbitrary ordinary
     *    withheld/backdated month elsewhere in the application's
     *    history.
     *
     * Always an overwrite, never a running add — confirmed explicitly
     * 2026-11-03: "die boleh edit balik amount tu selagi tak masuk next
     * monthnya" — CGS/Admin can resubmit as many times as they like to
     * correct themselves, as long as it's still the current *service*
     * month (the `where('payment_month', $month)` below is what
     * actually enforces "until next month": once the calendar moves
     * on, this route can never touch last month's row again).
     * `$month` is resolved the same way confirmPayment() resolves it —
     * one calendar month behind real "today", per the payroll lag — so
     * this always targets the exact row index()'s default view is
     * showing CGS right now. `rowFor()`'s own `pendingForNextMonth` is
     * what surfaces the current value back to CGS/Admin to edit — never
     * shown to the student.
     */
    public function setReviewAmount(Request $request, Application $application): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->isAdmin() || $user->role === Role::NON_EXEC_CGS, 403);

        $today = Carbon::today();
        abort_if($today->day === 1, 403, 'The 1st already resolves the whole month automatically — nothing to set.');

        $data = $request->validate(['amount' => ['required', 'numeric', 'min:0']]);

        $month = $this->resolveActingMonth($application);

        $log = AllowancePaymentLog::where('application_id', $application->id)
            ->where('payment_month', $month)
            ->whereIn('status', ['review_required', 'determined', 'withheld', 'backdated'])
            ->firstOrFail();

        if (in_array($log->status, ['review_required', 'determined'], true)) {
            $priorTotal = $this->priorWithheldTotal($application, $month);

            $log->update([
                'amount' => $priorTotal + $data['amount'],
                'status' => 'determined',
            ]);

            return back()->with('status', 'Amount set — RM '.number_format((float) $data['amount'], 2).' recorded. Shows on next month\'s row — you can still change it until then.');
        }

        $preceding = AllowancePaymentLog::where('application_id', $application->id)
            ->where('payment_month', '<', $month)
            ->orderByDesc('payment_month')
            ->first(['status', 'amount']);

        $isCaseB = $preceding?->status === 'forfeited';
        $isCaseA = $preceding?->status === 'backdated'
            && (float) $preceding->amount === 0.0
            && $this->candidatureStatusFor($application) === 'active';

        abort_unless($isCaseB || $isCaseA, 404, 'Nothing pending to set for this application this month.');

        $log->update(['pending_addition' => $data['amount']]);

        return back()->with('status', 'Amount set — RM '.number_format((float) $data['amount'], 2).' recorded. Shows on next month\'s row — you can still change it until then.');
    }

    /**
     * The Stage 1 withheld/backdated running total at the exact moment a
     * Stage 2/3 termination interrupted it — 0.0 if Stage 1 wasn't
     * mid-accumulation right before this termination (either it was
     * already resolved and confirmed-paid, or never even due yet). Only
     * looks at the run immediately adjacent to the termination gap.
     * Delegates to the shared AllowanceLedger — StageGateEngine needs
     * this exact same sum for every reinstatement, via its own
     * resumeReinstatementMonth(), and the two must never be allowed to
     * drift apart.
     */
    protected function priorWithheldTotal(Application $application, Carbon $beforeMonth): float
    {
        return AllowanceLedger::priorWithheldTotal($application->id, $beforeMonth);
    }

    /** Admin + CGS team + the application's own student's assigned Supervisor. */
    protected function paymentStakeholders(Application $application): Collection
    {
        $recipients = User::where('role', Role::ADMIN)->get()
            ->merge(User::whereIn('role', Role::cgsTeam())->get());

        if ($supervisor = $application->student?->supervisor) {
            $recipients->push($supervisor);
        }

        return $recipients->unique('id');
    }

    protected function candidatureStatusFor(Application $application): string
    {
        $detail = $application->module_type === 'gra_application'
            ? GraApplicationDetail::where('application_id', $application->id)->first()
            : GaApplicationDetail::where('application_id', $application->id)->first();

        return $detail?->candidature_status ?? 'active';
    }
}
