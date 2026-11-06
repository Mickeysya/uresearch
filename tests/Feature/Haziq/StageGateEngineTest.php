<?php

namespace Tests\Feature\Haziq;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Haziq\Models\AllowancePaymentLog;
use App\Modules\Haziq\Models\GraApplicationDetail;
use App\Modules\Haziq\Models\GraGaStudentProfile;
use App\Modules\Haziq\Models\StageGate;
use App\Modules\Haziq\Models\StageGateRule;
use App\Modules\Haziq\Support\StageGateEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The core state machine: Stage 1 (RPD) recoverable, Stage 2/3
 * not -- the exact asymmetry docs/scope/haziq.md calls the module's
 * "distinctive piece", and the rule most likely to silently break
 * under a future edit since nothing else in the codebase enforces it.
 */
class StageGateEngineTest extends TestCase
{
    use RefreshDatabase;

    protected StageGateEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new StageGateEngine;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function student(): User
    {
        return User::create(['name' => 'Student', 'email' => 'student@test.com', 'password' => 'password', 'role' => Role::STUDENT]);
    }

    protected function application(User $student, float $allowance = 2000.0, Carbon $startDate = null): Application
    {
        $application = Application::create([
            'student_id' => $student->id, 'module_type' => 'gra_application',
            'status' => Application::STATUS_APPROVED, 'current_stage' => 'senior_director', 'submitted_at' => now(),
        ]);

        GraApplicationDetail::create([
            'application_id' => $application->id,
            'profile_id' => GraGaStudentProfile::create(['user_id' => $student->id, 'level_of_study' => 'PhD', 'programme' => 'CS', 'mode_of_study' => 'full_time', 'admission_date' => now()->subYear()])->id,
            'application_type' => 'new', 'candidature_status' => 'active',
            'project_leader_name' => 'Dr. Test', 'project_title' => 'Test Project', 'funding_source' => 'Internal',
            'service_start_date' => $startDate ?? now()->subMonths(6), 'service_end_date' => now()->addYears(3),
            'proposed_allowance' => $allowance,
        ]);

        return $application;
    }

    /** A gate with an explicit deadline/grace_end, bypassing StageGateRule fixtures -- isRecoverable() falls back to stage_order === 1 with no rule attached. */
    protected function gate(Application $application, int $stageOrder, Carbon $deadline, Carbon $graceEnd, array $overrides = []): StageGate
    {
        return StageGate::create($overrides + [
            'application_id' => $application->id, 'stage_order' => $stageOrder,
            'stage_name' => $stageOrder === 1 ? 'RPD Completion' : "Stage {$stageOrder}",
            'requirement_description' => 'Test requirement',
            'deadline_date' => $deadline, 'grace_end_date' => $graceEnd,
        ]);
    }

    // ---- generateSchedule --------------------------------------------------

    public function test_generate_schedule_creates_one_gate_per_rule_and_dates_them_from_service_start(): void
    {
        StageGateRule::create(['level_of_study' => 'PhD', 'stage_order' => 1, 'stage_name' => 'RPD', 'requirement_description' => 'x', 'deadline_month' => 3, 'grace_end_month' => 6, 'recoverable' => true]);
        StageGateRule::create(['level_of_study' => 'PhD', 'stage_order' => 2, 'stage_name' => 'Journal', 'requirement_description' => 'x', 'deadline_month' => 12, 'grace_end_month' => 18, 'recoverable' => false]);

        $student = $this->student();
        $profile = GraGaStudentProfile::create(['user_id' => $student->id, 'level_of_study' => 'PhD', 'programme' => 'CS', 'mode_of_study' => 'full_time', 'admission_date' => now()]);
        $application = Application::create(['student_id' => $student->id, 'module_type' => 'gra_application', 'status' => Application::STATUS_APPROVED, 'current_stage' => 'senior_director', 'submitted_at' => now()]);
        $start = Carbon::parse('2026-01-01');

        $this->engine->generateSchedule($application, $profile, $start);

        $gates = StageGate::where('application_id', $application->id)->orderBy('stage_order')->get();
        $this->assertCount(2, $gates);
        $this->assertTrue($gates[0]->deadline_date->isSameDay(Carbon::parse('2026-04-01')));
        $this->assertTrue($gates[0]->grace_end_date->isSameDay(Carbon::parse('2026-07-01')));
        $this->assertTrue($gates[1]->deadline_date->isSameDay(Carbon::parse('2027-01-01')));
    }

    public function test_generate_schedule_never_runs_twice_for_the_same_application(): void
    {
        StageGateRule::create(['level_of_study' => 'PhD', 'stage_order' => 1, 'stage_name' => 'RPD', 'requirement_description' => 'x', 'deadline_month' => 3, 'grace_end_month' => 6, 'recoverable' => true]);

        $student = $this->student();
        $profile = GraGaStudentProfile::create(['user_id' => $student->id, 'level_of_study' => 'PhD', 'programme' => 'CS', 'mode_of_study' => 'full_time', 'admission_date' => now()]);
        $application = Application::create(['student_id' => $student->id, 'module_type' => 'gra_application', 'status' => Application::STATUS_APPROVED, 'current_stage' => 'senior_director', 'submitted_at' => now()]);

        $this->engine->generateSchedule($application, $profile, now());
        $this->engine->generateSchedule($application, $profile, now());

        $this->assertSame(1, StageGate::where('application_id', $application->id)->count());
    }

    // ---- evaluateGate: timeline boundaries ---------------------------------

    public function test_timeline_is_upcoming_more_than_a_month_before_the_deadline(): void
    {
        $application = $this->application($this->student());
        $gate = $this->gate($application, 1, now()->addMonths(3), now()->addMonths(6));

        Carbon::setTestNow(now());
        $this->invokeEvaluate($gate);

        $this->assertSame('upcoming', $gate->refresh()->timeline_status);
    }

    public function test_timeline_is_due_soon_within_a_month_of_the_deadline(): void
    {
        $application = $this->application($this->student());
        $gate = $this->gate($application, 1, now()->addWeeks(2), now()->addMonths(3));

        $this->invokeEvaluate($gate);

        $this->assertSame('due_soon', $gate->refresh()->timeline_status);
    }

    public function test_timeline_is_grace_period_between_deadline_and_grace_end_plus_a_month(): void
    {
        $application = $this->application($this->student());
        $gate = $this->gate($application, 1, now()->subWeek(), now()->addMonth());

        $this->invokeEvaluate($gate);

        $this->assertSame('grace_period', $gate->refresh()->timeline_status);
    }

    public function test_timeline_is_grace_expired_a_full_month_past_grace_end(): void
    {
        $application = $this->application($this->student());
        $gate = $this->gate($application, 1, now()->subMonths(4), now()->subMonths(2));

        $this->invokeEvaluate($gate);

        $this->assertSame('grace_expired', $gate->refresh()->timeline_status);
    }

    // ---- the recoverable/non-recoverable asymmetry -------------------------

    public function test_grace_expiry_on_stage_1_withholds_allowance_and_does_not_terminate(): void
    {
        $student = $this->student();
        $application = $this->application($student);
        $gate = $this->gate($application, 1, now()->subMonths(4), now()->subMonths(2));

        $this->invokeEvaluate($gate);
        $gate->refresh();

        $this->assertSame('withheld', $gate->allowance_status);
        $this->assertSame('active', GraApplicationDetail::where('application_id', $application->id)->first()->candidature_status);
    }

    public function test_grace_expiry_on_stage_2_forfeits_allowance_and_terminates_candidature(): void
    {
        $student = $this->student();
        $application = $this->application($student);
        $gate = $this->gate($application, 2, now()->subMonths(4), now()->subMonths(2));

        $this->invokeEvaluate($gate);
        $gate->refresh();

        $this->assertSame('forfeited', $gate->allowance_status);
        $this->assertSame('terminated', GraApplicationDetail::where('application_id', $application->id)->first()->candidature_status);
    }

    public function test_grace_expiry_only_applies_its_consequence_once_not_on_every_re_evaluation(): void
    {
        $application = $this->application($this->student());
        $gate = $this->gate($application, 1, now()->subMonths(4), now()->subMonths(2));

        $this->invokeEvaluate($gate);
        $gate->refresh();
        $gate->allowance_status = 'backdated'; // simulate CGS having already recovered it
        $gate->save();

        $this->invokeEvaluate($gate);

        $this->assertSame('backdated', $gate->refresh()->allowance_status);
    }

    // ---- recordCompletion / back-pay ---------------------------------------

    public function test_completing_an_overdue_stage_1_gate_back_pays_every_withheld_month(): void
    {
        $student = $this->student();
        $application = $this->application($student);
        $gate = $this->gate($application, 1, now()->subMonths(4), now()->subMonths(2), ['allowance_status' => 'withheld']);

        AllowancePaymentLog::create(['application_id' => $application->id, 'stage_gate_id' => $gate->id, 'student_id' => $student->id, 'payment_month' => now()->subMonth()->startOfMonth(), 'amount' => 2000, 'status' => 'withheld']);
        AllowancePaymentLog::create(['application_id' => $application->id, 'stage_gate_id' => $gate->id, 'student_id' => $student->id, 'payment_month' => now()->startOfMonth(), 'amount' => 2000, 'status' => 'withheld']);

        $this->engine->recordCompletion($gate, User::create(['name' => 'CGS', 'email' => 'cgs@test.com', 'password' => 'x', 'role' => Role::NON_EXEC_CGS]), true);

        $this->assertSame('backdated', $gate->refresh()->allowance_status);
        $this->assertSame(2, AllowancePaymentLog::where('application_id', $application->id)->where('status', 'backdated')->count());
        $this->assertSame(0, AllowancePaymentLog::where('application_id', $application->id)->where('status', 'withheld')->count());
    }

    public function test_completing_a_gate_that_was_never_withheld_just_marks_it_eligible(): void
    {
        $application = $this->application($this->student());
        $gate = $this->gate($application, 1, now()->addMonths(3), now()->addMonths(6));

        $this->engine->recordCompletion($gate, User::create(['name' => 'CGS', 'email' => 'cgs@test.com', 'password' => 'x', 'role' => Role::NON_EXEC_CGS]), true);

        $this->assertSame('eligible', $gate->refresh()->allowance_status);
        $this->assertSame(0, AllowancePaymentLog::where('application_id', $application->id)->count());
    }

    // ---- statusAsOf, via ensureMonthlyLogs's real backfill -----------------

    public function test_a_month_before_grace_end_plus_one_is_always_eligible_even_if_the_gate_is_open(): void
    {
        $student = $this->student();
        $start = now()->subMonths(2);
        $application = $this->application($student, 2000.0, $start);
        $this->gate($application, 1, $start->copy()->addMonths(3), $start->copy()->addMonths(6));

        $this->invokeEnsureMonthlyLogs($application);

        $log = AllowancePaymentLog::where('application_id', $application->id)->where('payment_month', $start->copy()->startOfMonth())->first();
        $this->assertSame('eligible', $log->status);
    }

    public function test_an_incomplete_recoverable_gate_backfills_as_withheld_past_grace(): void
    {
        $student = $this->student();
        $start = now()->subMonths(10);
        $application = $this->application($student, 2000.0, $start);
        $this->gate($application, 1, $start->copy()->addMonth(), $start->copy()->addMonths(2));

        $this->invokeEnsureMonthlyLogs($application);

        $log = AllowancePaymentLog::where('application_id', $application->id)->where('payment_month', now()->startOfMonth())->first();
        $this->assertSame('withheld', $log->status);
    }

    public function test_a_completed_gates_backfill_uses_its_own_allowance_status_not_a_date_guess(): void
    {
        $student = $this->student();
        $start = now()->subMonths(10);
        $application = $this->application($student, 2000.0, $start);
        $this->gate($application, 1, $start->copy()->addMonth(), $start->copy()->addMonths(2), ['completion_status' => 'completed', 'allowance_status' => 'eligible']);

        $this->invokeEnsureMonthlyLogs($application);

        $log = AllowancePaymentLog::where('application_id', $application->id)->where('payment_month', now()->startOfMonth())->first();
        $this->assertSame('eligible', $log->status);
    }

    // ---- governingAllowanceStatus -------------------------------------------

    public function test_governing_allowance_status_is_forfeited_when_terminated_even_if_stage_1_is_fine(): void
    {
        $student = $this->student();
        $application = $this->application($student);
        $this->gate($application, 1, now()->addMonths(3), now()->addMonths(6), ['allowance_status' => 'eligible']);
        GraApplicationDetail::where('application_id', $application->id)->update(['candidature_status' => 'terminated']);

        $this->assertSame('forfeited', $this->engine->governingAllowanceStatus($application));
    }

    public function test_governing_allowance_status_follows_stage_1_when_active(): void
    {
        $application = $this->application($this->student());
        $this->gate($application, 1, now()->subMonths(4), now()->subMonths(2), ['allowance_status' => 'withheld']);

        $this->assertSame('withheld', $this->engine->governingAllowanceStatus($application));
    }

    // ---- reinstateCandidature touches candidature_status only --------------

    public function test_reinstating_candidature_never_touches_timeline_or_allowance_status(): void
    {
        $student = $this->student();
        $application = $this->application($student);
        $gate = $this->gate($application, 2, now()->subMonths(4), now()->subMonths(2));
        $this->invokeEvaluate($gate);
        $gate->refresh();

        $this->engine->reinstateCandidature($application);

        $this->assertSame('active', GraApplicationDetail::where('application_id', $application->id)->first()->candidature_status);
        $this->assertSame('forfeited', $gate->refresh()->allowance_status);
        $this->assertSame('grace_expired', $gate->refresh()->timeline_status);
    }

    // ---- helpers to reach protected methods via evaluateAll()'s own path ---

    protected function invokeEvaluate(StageGate $gate): void
    {
        $method = new \ReflectionMethod(StageGateEngine::class, 'evaluateGate');
        $method->setAccessible(true);
        $method->invoke($this->engine, $gate);
    }

    protected function invokeEnsureMonthlyLogs(Application $application): void
    {
        $method = new \ReflectionMethod(StageGateEngine::class, 'ensureMonthlyLogs');
        $method->setAccessible(true);
        $method->invoke($this->engine, $application);
    }
}
