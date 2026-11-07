<?php

namespace Tests\Feature\Haziq;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Haziq\Http\Controllers\AllowanceController;
use App\Modules\Haziq\Models\AllowancePaymentConfirmation;
use App\Modules\Haziq\Models\AllowancePaymentLog;
use App\Modules\Haziq\Models\GraApplicationDetail;
use App\Modules\Haziq\Models\GraGaStudentProfile;
use App\Modules\Haziq\Models\StageGate;
use App\Modules\Haziq\Support\StageGateEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use ReflectionMethod;
use Tests\TestCase;

/**
 * rowFor() is the single most revised, most subtle piece of this whole
 * module -- the permanent payroll lag, the every-month-confirmation
 * rule, withheld-is-confirmable (2026-11-06's own reversal), and the
 * determined-figure carryover all live inside this one walk. These
 * tests build a raw AllowancePaymentLog history directly (bypassing
 * StageGateEngine entirely) so each rule is isolated and provable on
 * its own, independent of whatever the engine would normally produce.
 */
class AllowanceRowForTest extends TestCase
{
    use RefreshDatabase;

    protected AllowanceController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->controller = new AllowanceController;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function application(): Application
    {
        $student = User::create(['name' => 'Student', 'email' => 'student@test.com', 'password' => 'password', 'role' => Role::STUDENT]);
        $application = Application::create(['student_id' => $student->id, 'module_type' => 'gra_application', 'status' => Application::STATUS_APPROVED, 'current_stage' => 'senior_director', 'submitted_at' => now()]);

        GraApplicationDetail::create([
            'application_id' => $application->id,
            'profile_id' => GraGaStudentProfile::create(['user_id' => $student->id, 'level_of_study' => 'PhD', 'programme' => 'CS', 'mode_of_study' => 'full_time', 'admission_date' => now()])->id,
            'application_type' => 'new', 'candidature_status' => 'active',
            'project_leader_name' => 'Dr. Test', 'project_title' => 'Test', 'funding_source' => 'Internal',
            'service_start_date' => now()->subYear(), 'service_end_date' => now()->addYears(2), 'proposed_allowance' => 2000,
        ]);

        return $application;
    }

    protected function log(Application $application, string $month, string $status, float $amount, ?float $pendingAddition = null): AllowancePaymentLog
    {
        return AllowancePaymentLog::create([
            'application_id' => $application->id, 'student_id' => $application->student_id,
            'payment_month' => Carbon::parse($month)->startOfMonth(), 'status' => $status, 'amount' => $amount,
            'pending_addition' => $pendingAddition,
        ]);
    }

    protected function confirm(Application $application, string $month): void
    {
        AllowancePaymentConfirmation::create(['application_id' => $application->id, 'confirmed_month' => Carbon::parse($month)->startOfMonth(), 'amount' => 0, 'confirmed_by' => 1, 'confirmed_at' => now()]);
    }

    protected function rowFor(AllowancePaymentLog $log): array
    {
        $method = new ReflectionMethod(AllowanceController::class, 'rowFor');
        $method->setAccessible(true);

        return $method->invoke($this->controller, $log);
    }

    /** Only needed by the one test below that exercises the real StageGateEngine::recordCompletion() back-pay, not the raw-log style the rest of this file uses. */
    protected function gate(Application $application, Carbon $deadline, Carbon $graceEnd, array $overrides = []): StageGate
    {
        return StageGate::create($overrides + [
            'application_id' => $application->id, 'stage_order' => 1, 'stage_name' => 'RPD Completion',
            'requirement_description' => 'Test requirement',
            'deadline_date' => $deadline, 'grace_end_date' => $graceEnd,
            'completion_status' => 'not_recorded', 'timeline_status' => 'grace_period',
        ]);
    }

    // ---- the baseline: confirmed every month never accumulates -------------

    public function test_a_confirmed_every_month_eligible_row_stays_flat_not_accumulated(): void
    {
        $application = $this->application();
        $this->log($application, '2026-08-01', 'eligible', 2000);
        $this->confirm($application, '2026-08-01');
        $row = $this->rowFor($this->log($application, '2026-09-01', 'eligible', 2000));

        $this->assertSame(2000.0, $row['amount']);
    }

    // ---- every month needs confirmation, 2026-11-05's redesign -------------

    public function test_an_unconfirmed_eligible_month_accumulates_into_the_next(): void
    {
        $application = $this->application();
        $this->log($application, '2026-08-01', 'eligible', 2000); // left unconfirmed
        $row = $this->rowFor($this->log($application, '2026-09-01', 'eligible', 2000));

        $this->assertSame(4000.0, $row['amount']);
        $this->assertSame('payment_pending', $row['displayStatus']);
        $this->assertTrue($row['confirmable']);
    }

    // ---- 2026-11-08: only the FIRST withheld month is confirmable, the
    //      rest stay locked until Stage 1 actually completes -- replaces
    //      2026-11-06's "every withheld month is confirmable" reversal.
    //      The first one is still legitimately payable (the payroll lag
    //      means its own payment is for service from before Stage 1 went
    //      overdue); every one after that is locked precisely because
    //      there is no such excuse once the run is already underway.

    public function test_the_first_withheld_month_is_confirmable(): void
    {
        $application = $this->application();
        $this->log($application, '2026-07-01', 'eligible', 2000);
        $row = $this->rowFor($this->log($application, '2026-08-01', 'withheld', 2000));

        $this->assertSame(4000.0, $row['amount']);
        $this->assertSame('withheld', $row['displayStatus']);
        $this->assertTrue($row['confirmable'], 'the first withheld month is still payable -- it is for service from before Stage 1 went overdue');
    }

    public function test_a_second_consecutive_withheld_month_is_locked_not_confirmable(): void
    {
        $application = $this->application();
        $this->log($application, '2026-08-01', 'withheld', 2000);
        $row = $this->rowFor($this->log($application, '2026-09-01', 'withheld', 2000));

        $this->assertSame(4000.0, $row['amount'], 'still accumulates even though it is locked -- the money is still owed, just not confirmable yet');
        $this->assertSame('withheld', $row['displayStatus']);
        $this->assertFalse($row['confirmable'], 'only the first withheld month is confirmable; this is the second in a row');
    }

    /**
     * Corrected same day as the rule above: a resumption row right
     * after a termination carries an *unresolved* Stage 1 debt forward
     * -- it's not a fresh overdue episode, even though the row right
     * before it (forfeited, frozen) isn't itself 'withheld' either.
     * Only a row whose immediately preceding month was genuinely
     * 'eligible' gets the first-month exception.
     */
    public function test_a_resumption_row_after_termination_is_locked_not_treated_as_a_fresh_first_month(): void
    {
        $application = $this->application();
        $this->log($application, '2026-08-01', 'withheld', 2000); // the real first withheld month, long settled
        $this->log($application, '2026-09-01', 'forfeited', 0); // terminated
        $row = $this->rowFor($this->log($application, '2026-10-01', 'withheld', 30000)); // appeal approved, resumes with the full backlog

        $this->assertSame('withheld', $row['displayStatus']);
        $this->assertFalse($row['confirmable'], 'a resumption row continues an unresolved Stage 1 debt -- it is not a fresh case, so it does not get the first-month exception');
    }

    public function test_every_withheld_month_unlocks_together_once_stage_1_actually_completes(): void
    {
        $application = $this->application();
        $gate = $this->gate($application, now()->subMonths(4), now()->subMonths(2), ['allowance_status' => 'withheld']);

        // backPay() only touches rows tagged with this gate's own id --
        // the plain log() helper used everywhere else in this file never
        // sets one, since every other test checks rowFor()'s own walk,
        // never the real recordCompletion() back-pay this one needs.
        AllowancePaymentLog::create(['application_id' => $application->id, 'stage_gate_id' => $gate->id, 'student_id' => $application->student_id, 'payment_month' => now()->subMonth()->startOfMonth(), 'status' => 'withheld', 'amount' => 2000]);
        $secondRow = AllowancePaymentLog::create(['application_id' => $application->id, 'stage_gate_id' => $gate->id, 'student_id' => $application->student_id, 'payment_month' => now()->startOfMonth(), 'status' => 'withheld', 'amount' => 2000]);

        $this->assertFalse($this->rowFor($secondRow)['confirmable'], 'locked before completion, same as the test above');

        $cgs = User::create(['name' => 'CGS', 'email' => 'cgs-unlock@test.com', 'password' => 'x', 'role' => Role::NON_EXEC_CGS]);
        app(StageGateEngine::class)->recordCompletion($gate, $cgs, true);

        $row = $this->rowFor($secondRow->fresh());
        $this->assertSame('backdated', $secondRow->fresh()->status, 'back-pay flips every withheld row to backdated once Stage 1 completes');
        $this->assertSame('payment_pending', $row['displayStatus'], 'no longer overdue once completed, so the generic label, not withheld');
        $this->assertTrue($row['confirmable'], 'backdated rows are confirmable regardless of position -- the lock is specific to the raw withheld status');
    }

    public function test_confirming_the_first_withheld_month_resets_the_running_total_for_the_next_month(): void
    {
        $application = $this->application();
        $this->log($application, '2026-08-01', 'withheld', 2000);
        $this->confirm($application, '2026-08-01');

        $row = $this->rowFor($this->log($application, '2026-09-01', 'withheld', 2000));

        $this->assertSame(2000.0, $row['amount'], 'next month after a confirmation should be flat, not carrying the old total');
    }

    // ---- forfeited / review_required / determined --------------------------

    public function test_a_forfeited_month_shows_blank_not_zero(): void
    {
        $application = $this->application();
        $row = $this->rowFor($this->log($application, '2026-09-01', 'forfeited', 0));

        $this->assertNull($row['amount']);
        $this->assertSame('terminated', $row['displayStatus']);
        $this->assertFalse($row['confirmable']);
    }

    public function test_a_review_required_month_is_reviewable_not_confirmable(): void
    {
        $application = $this->application();
        $row = $this->rowFor($this->log($application, '2026-09-01', 'review_required', 0));

        $this->assertNull($row['amount']);
        $this->assertTrue($row['reviewable']);
        $this->assertFalse($row['confirmable']);
    }

    public function test_a_determined_figure_pays_out_blank_this_month_and_visible_next_month(): void
    {
        $application = $this->application();
        $determinedLog = $this->log($application, '2026-09-01', 'determined', 5000);
        $determinedRow = $this->rowFor($determinedLog);

        $this->assertNull($determinedRow['amount'], 'determined never shows on its own row');

        $nextRow = $this->rowFor($this->log($application, '2026-10-01', 'eligible', 2000));

        $this->assertSame(5000.0, $nextRow['amount'], 'the determined figure pays out on the row right after, not this months own eligible amount yet');
    }

    public function test_a_determined_figure_still_accruing_folds_in_the_next_rows_amount_too(): void
    {
        $application = $this->application();
        $this->log($application, '2026-09-01', 'determined', 5000);
        $nextRow = $this->rowFor($this->log($application, '2026-10-01', 'withheld', 2000));

        // Still accruing (unconfirmed withheld after a determined figure):
        // shows the determined figure now, and the row's OWN 2000 defers
        // one further month rather than landing immediately.
        $this->assertSame(5000.0, $nextRow['amount']);
        $this->assertTrue($nextRow['confirmable']);

        $thirdRow = $this->rowFor($this->log($application, '2026-11-01', 'withheld', 2000));
        // 5000 (the determined figure) + 2000 (October's own amount,
        // deferred into this row by the permanent lag) = 7000. November's
        // own 2000 defers again, landing on December's row instead.
        $this->assertSame(7000.0, $thirdRow['amount']);
    }

    // ---- Case B reinstatement: pending_addition lands one month later ------

    public function test_a_pending_addition_from_a_reinstatement_lands_on_the_following_month_not_immediately(): void
    {
        $application = $this->application();
        $firstLog = $this->log($application, '2026-09-01', 'withheld', 30000, pendingAddition: 2000); // resumed total + queued ordinary amount
        $thisRow = $this->rowFor($firstLog);

        $this->assertSame(30000.0, $thisRow['amount'], 'the resumed total shows immediately');

        $nextRow = $this->rowFor($this->log($application, '2026-10-01', 'withheld', 2000));

        $this->assertSame(32000.0, $nextRow['amount'], 'the queued pending_addition folds in next month, on top of the held-back row amount being deferred too');
    }
}
