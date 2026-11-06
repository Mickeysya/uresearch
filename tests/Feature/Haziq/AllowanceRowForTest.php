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

    // ---- 2026-11-06's reversal: withheld is confirmable too -----------------

    public function test_an_unconfirmed_withheld_month_is_confirmable_not_just_payment_pending(): void
    {
        $application = $this->application();
        $this->log($application, '2026-08-01', 'withheld', 2000);
        $row = $this->rowFor($this->log($application, '2026-09-01', 'withheld', 2000));

        $this->assertSame(4000.0, $row['amount']);
        $this->assertSame('withheld', $row['displayStatus']);
        $this->assertTrue($row['confirmable'], 'withheld must stay confirmable -- this is the exact rule reversed 2026-11-06');
    }

    public function test_confirming_a_withheld_month_resets_the_running_total_for_the_next_month(): void
    {
        $application = $this->application();
        $this->log($application, '2026-08-01', 'withheld', 2000);
        $confirmedLog = $this->log($application, '2026-09-01', 'withheld', 2000);
        $this->confirm($application, '2026-09-01');

        $row = $this->rowFor($this->log($application, '2026-10-01', 'withheld', 2000));

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
