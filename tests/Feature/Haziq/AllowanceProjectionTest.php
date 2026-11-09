<?php

namespace Tests\Feature\Haziq;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Haziq\Assistant\AssistantContext;
use App\Modules\Haziq\Models\AllowancePaymentConfirmation;
use App\Modules\Haziq\Models\AllowancePaymentLog;
use App\Modules\Haziq\Models\GraApplicationDetail;
use App\Modules\Haziq\Models\GraGaStudentProfile;
use App\Modules\Haziq\Support\AllowanceLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * AllowanceLedger::projectedNextMonthIfUnconfirmed() -- the number the
 * student assistant needs to answer "what happens next month if this
 * isn't confirmed" with a real figure instead of refusing. Confirmed
 * explicitly 2026-11-07, user's own example: RM4,000 this month, RM2,000
 * monthly rate, unconfirmed -> RM6,000 next month.
 *
 * Deliberately conservative: every case that doesn't look like the
 * ordinary "carries forward and adds the flat rate" rule returns null
 * rather than a guessed number -- see the method's own docblock for why.
 */
class AllowanceProjectionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function application(float $rate = 2000): Application
    {
        $student = User::create(['name' => 'Student', 'email' => 'student'.uniqid().'@test.com', 'password' => 'password', 'role' => Role::STUDENT]);
        $application = Application::create(['student_id' => $student->id, 'module_type' => 'gra_application', 'status' => Application::STATUS_APPROVED, 'current_stage' => 'senior_director', 'submitted_at' => now()]);

        GraApplicationDetail::create([
            'application_id' => $application->id,
            'profile_id' => GraGaStudentProfile::create(['user_id' => $student->id, 'level_of_study' => 'PhD', 'programme' => 'CS', 'mode_of_study' => 'full_time', 'admission_date' => now()])->id,
            'application_type' => 'new', 'candidature_status' => 'active',
            'project_leader_name' => 'Dr. Test', 'project_title' => 'Test', 'funding_source' => 'Internal',
            'service_start_date' => now()->subYear(), 'service_end_date' => now()->addYears(2), 'proposed_allowance' => $rate,
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

    public function test_the_users_own_example_four_thousand_unconfirmed_plus_two_thousand_rate_is_six_thousand(): void
    {
        $application = $this->application(rate: 2000);
        $this->log($application, '2026-11-01', 'withheld', 4000);

        $projected = AllowanceLedger::projectedNextMonthIfUnconfirmed($application->id, Carbon::parse('2026-11-01'), 2000);

        $this->assertSame(6000.0, $projected);
    }

    /**
     * Real bug, caught live 2026-11-08: three real unconfirmed months in
     * a row, each stored as its own flat RM2,000 (never a single row
     * carrying the accumulated figure directly -- that's how
     * ensureMonthlyLogs() actually writes them). Walked total through
     * November is RM6,000 (2000+2000+2000); the first version of this
     * method used $log->amount directly (November's own RM2,000) and
     * told the student next month would be RM4,000 -- one month's rate
     * short of correct, silently dropping September and October's own
     * unconfirmed contribution entirely.
     */
    public function test_three_real_unconfirmed_months_in_a_row_project_correctly_not_just_the_last_rows_own_amount(): void
    {
        $application = $this->application(rate: 2000);
        $this->log($application, '2026-09-01', 'eligible', 2000);
        $this->log($application, '2026-10-01', 'eligible', 2000);
        $this->log($application, '2026-11-01', 'eligible', 2000);

        $currentTotal = AllowanceLedger::withheldTotalThrough($application->id, Carbon::parse('2026-11-01'));
        $projected = AllowanceLedger::projectedNextMonthIfUnconfirmed($application->id, Carbon::parse('2026-11-01'), 2000);

        $this->assertSame(6000.0, $currentTotal);
        $this->assertSame(8000.0, $projected);
    }

    public function test_an_eligible_unconfirmed_month_also_projects(): void
    {
        $application = $this->application(rate: 1500);
        $this->log($application, '2026-11-01', 'eligible', 1500);

        $projected = AllowanceLedger::projectedNextMonthIfUnconfirmed($application->id, Carbon::parse('2026-11-01'), 1500);

        $this->assertSame(3000.0, $projected);
    }

    public function test_a_confirmed_month_never_projects(): void
    {
        $application = $this->application();
        $this->log($application, '2026-11-01', 'withheld', 4000);
        $this->confirm($application, '2026-11-01');

        $this->assertNull(AllowanceLedger::projectedNextMonthIfUnconfirmed($application->id, Carbon::parse('2026-11-01'), 2000));
    }

    public function test_forfeited_review_required_and_determined_never_project(): void
    {
        foreach (['forfeited', 'review_required', 'determined'] as $status) {
            $application = $this->application();
            $this->log($application, '2026-11-01', $status, 4000);

            $this->assertNull(
                AllowanceLedger::projectedNextMonthIfUnconfirmed($application->id, Carbon::parse('2026-11-01'), 2000),
                "status={$status} should never project"
            );
        }
    }

    public function test_a_row_already_carrying_a_pending_addition_never_projects(): void
    {
        $application = $this->application();
        $this->log($application, '2026-11-01', 'withheld', 4000, pendingAddition: 1000);

        $this->assertNull(AllowanceLedger::projectedNextMonthIfUnconfirmed($application->id, Carbon::parse('2026-11-01'), 2000));
    }

    public function test_a_row_right_after_a_forfeited_one_never_projects(): void
    {
        $application = $this->application();
        $this->log($application, '2026-10-01', 'forfeited', 0);
        $this->log($application, '2026-11-01', 'withheld', 0);

        $this->assertNull(AllowanceLedger::projectedNextMonthIfUnconfirmed($application->id, Carbon::parse('2026-11-01'), 2000));
    }

    public function test_no_log_for_that_month_never_projects(): void
    {
        $application = $this->application();

        $this->assertNull(AllowanceLedger::projectedNextMonthIfUnconfirmed($application->id, Carbon::parse('2026-11-01'), 2000));
    }

    /* -----------------------------------------------------------------
     | AssistantContext -- the student-facing wiring
     |------------------------------------------------------------------*/

    public function test_the_assistant_context_states_the_rate_and_projection_as_plain_facts(): void
    {
        $application = $this->application(rate: 2000);
        $student = $application->student;
        $this->log($application, Carbon::today()->startOfMonth()->toDateString(), 'withheld', 4000);

        $context = AssistantContext::forStudent($student);

        $this->assertStringContainsString('amount=RM4,000.00', $context);
        $this->assertStringContainsString('Monthly rate once eligible: RM2,000.00', $context);
        $this->assertStringContainsString("next month's running total would be RM6,000.00", $context);
    }

    public function test_the_assistant_context_omits_the_projection_line_once_confirmed(): void
    {
        $application = $this->application(rate: 2000);
        $student = $application->student;
        $month = Carbon::today()->startOfMonth();
        $this->log($application, $month->toDateString(), 'withheld', 4000);
        $this->confirm($application, $month->toDateString());

        $context = AssistantContext::forStudent($student);

        $this->assertStringContainsString('Monthly rate once eligible: RM2,000.00', $context);
        $this->assertStringNotContainsString('running total would be', $context);
    }

    /** The exact scenario reported live 2026-11-08 -- see the Ledger test above for the isolated version. */
    public function test_the_assistant_context_reports_the_true_walked_total_across_three_unconfirmed_months(): void
    {
        $this->travelTo(Carbon::parse('2026-11-08'));

        $application = $this->application(rate: 2000);
        $student = $application->student;
        $this->log($application, '2026-09-01', 'eligible', 2000);
        $this->log($application, '2026-10-01', 'eligible', 2000);
        $this->log($application, '2026-11-01', 'eligible', 2000);

        $context = AssistantContext::forStudent($student);

        $this->assertStringContainsString('amount=RM6,000.00', $context);
        $this->assertStringContainsString("next month's running total would be RM8,000.00", $context);
    }
}
