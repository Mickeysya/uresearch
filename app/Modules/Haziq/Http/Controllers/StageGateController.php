<?php

namespace App\Modules\Haziq\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Haziq\Models\CandidatureAppeal;
use App\Modules\Haziq\Models\GaApplicationDetail;
use App\Modules\Haziq\Models\GraApplicationDetail;
use App\Modules\Haziq\Models\StageGate;
use App\Modules\Haziq\Support\StageGateEngine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Monitoring + the one action CGS actually takes: Mark Complete / Mark Not
 * Complete. Everything else on this screen — timeline_status,
 * allowance_status, the warning colour — is computed by the daily job, not
 * chosen here.
 */
class StageGateController extends Controller
{
    public function index(Request $request, StageGateEngine $engine)
    {
        $user = $request->user();

        // A student tracking their own progress isn't "monitoring" — no
        // filters, no portal-wide dashboard, no action column, just their
        // own rows across every GRA/GA application they've had approved.
        if ($user->isStudent()) {
            $gates = StageGate::query()
                ->with(['application.student', 'rule'])
                ->whereHas('application', fn ($q) => $q->where('student_id', $user->id))
                ->whereIn('application_id', $this->startedApplicationIds($user))
                ->orderBy('deadline_date')
                ->paginate(25);

            // A student deserves the same real answer an approver gets —
            // "is my allowance actually being paid right now" — not each
            // row's own narrow column, which reads as "eligible" on a
            // later stage even while an earlier one of theirs is still
            // blocking it. Confirmed explicitly 2026-10-15: this was only
            // fixed for the approver table the round before, by mistake.
            $applicationIds = $gates->pluck('application_id')->unique();

            // Active/Terminated is the student's own standing, not just an
            // approver concern — confirmed explicitly 2026-10-15, after
            // the earlier round scoped this to the approver table only.
            $candidatureStatus = $this->candidatureStatusByApplication($applicationIds);

            // One per terminated application, so the student's own appeal
            // button/status reads correctly even if they've had more than
            // one GRA/GA appointment terminated — latest row wins if they
            // appealed more than once (e.g. after a rejection).
            $terminatedIds = collect($candidatureStatus)->filter(fn ($s) => $s === 'terminated')->keys();
            $latestAppealByApplication = CandidatureAppeal::whereIn('application_id', $terminatedIds)
                ->latest('id')
                ->get()
                ->unique('application_id')
                ->keyBy('application_id');

            return view('haziq::stage_gate.index', [
                'gates' => $gates,
                'canAct' => false,
                'isStudent' => true,
                'governingAllowance' => $this->governingAllowanceByApplication($applicationIds, $engine),
                'candidatureStatus' => $candidatureStatus,
                'isTerminated' => in_array('terminated', $candidatureStatus, true),
                'latestAppealByApplication' => $latestAppealByApplication,
                'riskByGate' => $gates->mapWithKeys(fn (StageGate $gate) => [$gate->id => $this->riskPercent($gate)]),
            ]);
        }

        $query = $this->scopedQuery($user);

        if ($stage = $request->query('stage')) {
            $query->where('stage_order', $stage);
        }

        if ($completion = $request->query('completion_status')) {
            $query->where('completion_status', $completion);
        }

        if ($timeline = $request->query('timeline_status')) {
            $query->where('timeline_status', $timeline);
        }

        if ($allowance = $request->query('allowance_status')) {
            $query->where('allowance_status', $allowance);
        }

        // CASE WHEN, not MySQL's own FIELD() -- same ordering, but also
        // runs under the sqlite connection the test suite uses, which
        // has no FIELD() function at all. Found 2026-11-10 while adding
        // a test for this same route's approver branch: nothing had ever
        // exercised it before, so this had silently never been testable.
        $gates = $query->orderByRaw("CASE timeline_status
                WHEN 'grace_expired' THEN 1
                WHEN 'grace_period' THEN 2
                WHEN 'due_soon' THEN 3
                WHEN 'upcoming' THEN 4
                ELSE 5
            END")
            ->orderBy('deadline_date')
            ->paginate(25)
            ->withQueryString();

        // Only CGS (the same role that owns cgs_review in the approval
        // chain) records completion — not Admin, and not Senior Director,
        // who only views this screen like Supervisor does.
        $canAct = $user->role === Role::NON_EXEC_CGS;

        $applicationIds = $gates->pluck('application_id')->unique();

        return view('haziq::stage_gate.index', compact('gates', 'canAct')
            + [
                'isStudent' => false,
                'governingAllowance' => $this->governingAllowanceByApplication($applicationIds, $engine),
                'candidatureStatus' => $this->candidatureStatusByApplication($applicationIds),
                'riskByGate' => $gates->mapWithKeys(fn (StageGate $gate) => [$gate->id => $this->riskPercent($gate)]),
            ]
            + $this->dashboardData($user));
    }

    /**
     * A student's own appointments whose service has actually started —
     * confirmed explicitly 2026-11-05: "aku rasa better tayah tunjuk
     * stage gate or allowance selagi tak masuk service date month."
     * Allowance already only ever shows something once service has
     * begun (ensureMonthlyLogs() never backfills before
     * service_start_date, and applicationsPendingFirstPay() only
     * covers the one month it starts in) — this is the same rule
     * applied to Stage Gate's own student view, which otherwise shows
     * every future deadline the moment an application is approved,
     * months before there's anything to actually track. Scoped to the
     * student's own view only, not the CGS/Admin monitoring table —
     * an approver watching the pipeline still benefits from seeing an
     * appointment that hasn't started yet.
     *
     * "Started" means service_start_date's own month has arrived, not
     * the exact day — matches the user's own phrasing ("masuk service
     * date month"), so an appointment starting later this same month
     * already shows.
     *
     * @return \Illuminate\Support\Collection<int, int>
     */
    protected function startedApplicationIds(User $user): Collection
    {
        $throughThisMonth = Carbon::today()->endOfMonth();

        $gra = GraApplicationDetail::whereHas('application', fn ($q) => $q->where('student_id', $user->id))
            ->where('service_start_date', '<=', $throughThisMonth)
            ->pluck('application_id');

        $ga = GaApplicationDetail::whereHas('application', fn ($q) => $q->where('student_id', $user->id))
            ->where('service_start_date', '<=', $throughThisMonth)
            ->pluck('application_id');

        return $gra->merge($ga);
    }

    /**
     * What actually determines this month's payment for each displayed
     * application, not what a specific row's own `allowance_status`
     * happens to say — see StageGateEngine::governingAllowanceStatus()'s
     * docblock. Keyed by application_id so the view can look up any row's
     * real status regardless of which stage that row is.
     *
     * @param  \Illuminate\Support\Collection<int, int>  $applicationIds
     * @return array<int, string>
     */
    protected function governingAllowanceByApplication($applicationIds, StageGateEngine $engine): array
    {
        $applications = Application::whereIn('id', $applicationIds)->get()->keyBy('id');

        return $applicationIds->mapWithKeys(function (int $id) use ($applications, $engine) {
            $application = $applications->get($id);

            return [$id => $application ? $engine->governingAllowanceStatus($application) : 'eligible'];
        })->all();
    }

    /**
     * Approved / Active / Inactive / Terminated -- this appointment's own
     * lifecycle (GraApplicationDetail/GaApplicationDetail::serviceStatus(),
     * not the raw candidature_status column directly), not the student's
     * broader standing. Keyed by application_id, GRA and GA both, since
     * the monitoring screen shows both module types together. Confirmed
     * explicitly 2026-11-10: this screen should show "Approved" before
     * service_start_date arrives and "Active" once it does, for every
     * role that reaches this screen, not just candidature_status's own
     * plain active/terminated.
     *
     * @param  \Illuminate\Support\Collection<int, int>  $applicationIds
     * @return array<int, string>
     */
    protected function candidatureStatusByApplication($applicationIds): array
    {
        $gra = GraApplicationDetail::whereIn('application_id', $applicationIds)
            ->get()
            ->mapWithKeys(fn (GraApplicationDetail $detail) => [$detail->application_id => $detail->serviceStatus()]);
        $ga = GaApplicationDetail::whereIn('application_id', $applicationIds)
            ->get()
            ->mapWithKeys(fn (GaApplicationDetail $detail) => [$detail->application_id => $detail->serviceStatus()]);

        return $gra->union($ga)->all();
    }

    /**
     * Base StageGate query, scoped by role — shared by the list and the
     * dashboard stats so a Supervisor's numbers only ever reflect their own
     * supervisees, not the whole portal.
     */
    protected function scopedQuery(User $user): Builder
    {
        $query = StageGate::query()->with(['application.student', 'rule']);

        // CGS/Admin see every student, matching CgsDashboard's portal-wide
        // scope. A Supervisor sees only their own supervisees — the same
        // scoping gap flagged for the approver dashboard in TODO.md is one
        // this module does not want to repeat.
        if ($user->role === Role::SUPERVISOR) {
            $query->whereHas('application.student', fn ($q) => $q->where('supervisor_id', $user->id));
        } elseif (! $user->isAdmin() && ! $user->isCgs() && $user->role !== Role::SENIOR_DIRECTOR_CGS) {
            abort(403);
        }

        return $query;
    }

    /**
     * Bottleneck-by-stage, active/terminated counts, and the suspended
     * allowance list — same role scope as the list above, independent of
     * whatever filters are currently applied to it.
     */
    protected function dashboardData(User $user): array
    {
        $bottleneck = (clone $this->scopedQuery($user))
            ->where('completion_status', '!=', 'completed')
            ->whereIn('timeline_status', ['grace_period', 'grace_expired'])
            ->selectRaw('stage_order, count(*) as total')
            ->groupBy('stage_order')
            ->orderBy('stage_order')
            ->pluck('total', 'stage_order');

        $bottleneckChart = collect(range(1, 4))
            ->filter(fn ($order) => (clone $this->scopedQuery($user))->where('stage_order', $order)->exists())
            ->map(fn ($order) => ['stage' => $order, 'label' => "Stage {$order}", 'count' => $bottleneck->get($order, 0)])
            ->values();

        $graActive = GraApplicationDetail::where('application_type', 'new')
            ->where('candidature_status', 'active')
            ->whereHas('application', fn ($q) => $q->where('status', Application::STATUS_APPROVED))
            ->count();
        $graTerminated = GraApplicationDetail::where('application_type', 'new')
            ->where('candidature_status', 'terminated')
            ->count();
        $gaActive = GaApplicationDetail::where('candidature_status', 'active')
            ->whereHas('application', fn ($q) => $q->where('status', Application::STATUS_APPROVED))
            ->count();
        $gaTerminated = GaApplicationDetail::where('candidature_status', 'terminated')->count();

        $suspended = (clone $this->scopedQuery($user))
            ->where('allowance_status', 'withheld')
            ->orderBy('grace_end_date')
            ->get();

        return [
            'bottleneckChart' => $bottleneckChart,
            'activeCount' => $graActive + $gaActive,
            'terminatedCount' => $graTerminated + $gaTerminated,
            'suspended' => $suspended,
        ];
    }

    public function markComplete(Request $request, StageGate $gate, StageGateEngine $engine)
    {
        $this->authorizeAction($request);
        $this->abortIfAlreadyCompleted($gate);

        $data = $request->validate(['notes' => ['nullable', 'string', 'max:2000']]);

        $engine->recordCompletion($gate, $request->user(), true, $data['notes'] ?? null);

        return back()->with('status', "Stage {$gate->stage_order} ({$gate->stage_name}) marked complete.");
    }

    public function markNotComplete(Request $request, StageGate $gate, StageGateEngine $engine)
    {
        $this->authorizeAction($request);
        $this->abortIfAlreadyCompleted($gate);

        $data = $request->validate(['notes' => ['nullable', 'string', 'max:2000']]);

        $engine->recordCompletion($gate, $request->user(), false, $data['notes'] ?? null);

        return back()->with('status', "Stage {$gate->stage_order} ({$gate->stage_name}) marked not complete.");
    }

    /**
     * Confirmed explicitly 2026-10-15: "once dah mark complete no action
     * return" — completing a Stage Gate is one-way. Back-pay, allowance
     * eligibility and the completion notification already fired off that
     * first completion; letting CGS flip it back to not_completed (or
     * re-fire completion) afterward would quietly re-run or contradict
     * all of that. The view stops rendering either button once completed
     * (see stage_gate/index.blade.php) — this is the server-side half of
     * that, since a hidden button is not an authorization check.
     */
    protected function abortIfAlreadyCompleted(StageGate $gate): void
    {
        abort_if($gate->completion_status === 'completed', 404, 'This Stage Gate has already been marked complete and cannot be changed.');
    }

    protected function authorizeAction(Request $request): void
    {
        abort_unless($request->user()->role === Role::NON_EXEC_CGS, 403, 'Only CGS can record Stage Gate completion.');
    }

    /**
     * A plain, explainable heuristic, not a trained model — there isn't
     * enough historical completion data across cohorts yet for a real
     * one to be worth anything. 0% at the deadline itself (nothing to
     * worry about yet), 100% once grace has fully run out (already
     * forfeited/withheld); scales linearly across the grace window in
     * between, which is the one stretch where "how close to the edge"
     * actually matters. Null once completed — there's nothing left to
     * be at risk of.
     */
    protected function riskPercent(StageGate $gate): ?int
    {
        if ($gate->completion_status === 'completed') {
            return null;
        }

        $today = now()->startOfDay();
        $window = $gate->deadline_date->diffInDays($gate->grace_end_date);

        if ($window <= 0) {
            return $today->gte($gate->grace_end_date) ? 100 : 0;
        }

        $elapsed = $gate->deadline_date->diffInDays($today, absolute: false);

        return max(0, min(100, (int) round($elapsed / $window * 100)));
    }

    /**
     * The one number the shared dashboard's "Postgraduate Progress" panel
     * needs — whichever of this student's own open gates is closest to
     * its grace period running out, reusing riskPercent() so the figure
     * can never drift from what the student's own Stage Gate page shows.
     * Scoped through startedApplicationIds() the same way the student's
     * own index() view is — an appointment that hasn't started yet
     * contributes nothing here either. Null when there's nothing open at
     * all (every gate completed, or no started appointment).
     */
    public function riskiestOpenGateFor(User $student): ?int
    {
        return StageGate::query()
            ->whereHas('application', fn ($q) => $q->where('student_id', $student->id))
            ->whereIn('application_id', $this->startedApplicationIds($student))
            ->where('completion_status', '!=', 'completed')
            ->get()
            ->map(fn (StageGate $gate) => $this->riskPercent($gate))
            ->filter(fn (?int $r) => $r !== null)
            ->max();
    }
}
