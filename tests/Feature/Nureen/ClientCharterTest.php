<?php

namespace Tests\Feature\Nureen;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApprovalHistory;
use App\Modules\Nureen\Models\GaCertificationDetail;
use App\Modules\Nureen\Models\GaExtensionDetail;
use App\Modules\Nureen\Models\SupervisionDetail;
use App\Modules\Nureen\Support\ClientCharter;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\MakesUsers;
use Tests\Feature\Nureen\Concerns\KeepsJobsOffTheDevQueue;
use Tests\TestCase;

/**
 * Stage due dates and the student's expected decision date, from CGS's
 * Client Charter -- see Support\ClientCharter.
 *
 * Today is pinned to Monday 2 Nov 2026 unless a test moves it, and every
 * fixture reaches its stage that morning unless it says otherwise.
 */
class ClientCharterTest extends TestCase
{
    use KeepsJobsOffTheDevQueue;
    use MakesUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-02 12:00'));
    }

    protected function charter(): ClientCharter
    {
        return app(ClientCharter::class);
    }

    protected function application(string $module, string $stage, string $status = Application::STATUS_PENDING, string $submittedAt = '2026-11-02 09:00'): Application
    {
        $student = $this->student(uniqid(), uniqid().'@test.my');

        return Application::create([
            'student_id' => $student->id,
            'submitted_by_id' => $student->id,
            'module_type' => $module,
            'status' => $status,
            'current_stage' => $stage,
            'submitted_at' => $status === Application::STATUS_DRAFT ? null : Carbon::parse($submittedAt),
        ]);
    }

    protected function gaExtension(string $stage, string $mode, string $status = Application::STATUS_PENDING, string $submittedAt = '2026-11-02 09:00'): Application
    {
        $application = $this->application('ga_extension', $stage, $status, $submittedAt);

        GaExtensionDetail::create([
            'application_id' => $application->id,
            'current_end_date' => '2026-12-31',
            'requested_new_end_date' => '2027-06-30',
            'reason_for_extension' => 'Test fixture.',
            'final_approver_snapshot' => $mode,
        ]);

        return $application;
    }

    protected function history(Application $application, string $stageKey, string $decision, string $at): void
    {
        $row = ApprovalHistory::create([
            'application_id' => $application->id,
            'approver_id' => $application->student_id,
            'stage_key' => $stageKey,
            'stage_label' => $stageKey,
            'decision' => $decision,
        ]);

        DB::table('approval_history')->where('id', $row->id)->update(['created_at' => $at]);
    }

    public function test_ga_extension_in_cgs_mode_adds_three_and_two_working_days(): void
    {
        $expected = $this->charter()->expectedDecision($this->gaExtension('supervisor', 'cgs'));

        // Monday + 3 = Thursday; + 2 more = the following Monday.
        $this->assertSame('2026-11-05', $expected['stage_due_date']->format('Y-m-d'));
        $this->assertSame('2026-11-09', $expected['expected_date']->format('Y-m-d'));
        // Total is the whole chain's own charter days, not just what's
        // left ahead -- admin_verify(2) + supervisor(3) + cgs_verify(2),
        // even though this fixture starts already past admin_verify.
        $this->assertSame(7, $expected['total_charter_days']);
        $this->assertNull($expected['untimed_stage']);
    }

    public function test_ga_extension_in_senior_director_mode_does_not_invent_a_date(): void
    {
        $atSupervisor = $this->charter()->expectedDecision($this->gaExtension('supervisor', 'senior_director'));

        // The supervisor's own stage is still timed...
        $this->assertSame('2026-11-05', $atSupervisor['stage_due_date']->format('Y-m-d'));
        // ...but the Senior Director's is not, so there is no final date.
        $this->assertNull($atSupervisor['expected_date']);
        $this->assertNull($atSupervisor['total_charter_days']);
        $this->assertSame('senior_director', $atSupervisor['untimed_stage']->key);

        $atDirector = $this->charter()->expectedDecision($this->gaExtension('senior_director', 'senior_director'));

        $this->assertNull($atDirector['stage_due_date']);
        $this->assertNull($atDirector['expected_date']);
        $this->assertFalse($atDirector['clock']['on_charter']);
    }

    public function test_supervision_in_automatic_mode_ends_at_the_chair(): void
    {
        $supervisor = $this->supervisor();
        $application = $this->application('supervision', 'supervisor');
        SupervisionDetail::create([
            'application_id' => $application->id,
            'requested_supervisor_id' => $supervisor->id,
            'justification' => 'Test fixture.',
            'appointment_role' => SupervisionDetail::ROLE_MAIN,
            'cgs_stage_snapshot' => 'automatic',
        ]);

        $expected = $this->charter()->expectedDecision($application);

        // Supervisor 3 (Thu 5), then Chair 3 (Tue 10) -- and nothing after.
        $this->assertSame('2026-11-10', $expected['expected_date']->format('Y-m-d'));
        $this->assertSame(6, $expected['total_charter_days']);
    }

    public function test_certification_sums_three_two_and_two(): void
    {
        $application = $this->application('ga_certification', 'supervisor');
        GaCertificationDetail::create([
            'application_id' => $application->id,
            'supervisor_id' => $this->supervisor()->id,
            'appointment_type' => 'GA',
            'period_start' => '2026-01-01',
            'period_end' => '2026-06-30',
            'purpose' => 'Test fixture.',
        ]);

        $expected = $this->charter()->expectedDecision($application);

        // Thu 5 + 2 = Mon 9, + 2 = Wed 11.
        $this->assertSame('2026-11-11', $expected['expected_date']->format('Y-m-d'));
        $this->assertSame(7, $expected['total_charter_days']);
    }

    public function test_an_overdue_stage_never_produces_a_date_in_the_past(): void
    {
        // Reached the supervisor two weeks ago: long overdue.
        $application = $this->gaExtension('supervisor', 'cgs', submittedAt: '2026-10-19 09:00');

        $expected = $this->charter()->expectedDecision($application);

        $this->assertTrue($expected['clock']['overdue']);
        $this->assertSame('2026-10-22', $expected['clock']['due_date']->format('Y-m-d'));
        // From today, not from the missed due date: Mon 2 + 2 = Wed 4.
        $this->assertSame('2026-11-04', $expected['expected_date']->format('Y-m-d'));

        // At the last stage, overdue, on a weekend: still not in the past.
        $final = $this->gaExtension('cgs_verify', 'cgs', submittedAt: '2026-10-19 09:00');
        $this->travelTo(Carbon::parse('2026-11-07 12:00'));

        $expected = $this->charter()->expectedDecision($final);

        $this->assertTrue($expected['expected_date']->gte(Carbon::today()));
        $this->assertSame('2026-11-09', $expected['expected_date']->format('Y-m-d'));
    }

    public function test_a_returned_application_is_timed_from_its_actual_current_stage(): void
    {
        $this->travelTo(Carbon::parse('2026-11-04 12:00'));

        // Supervisor endorsed Monday; CGS sent it back to the supervisor
        // Wednesday morning, so it is at 'supervisor' again from Wednesday.
        $application = $this->gaExtension('supervisor', 'cgs', submittedAt: '2026-10-30 09:00');
        $this->history($application, 'supervisor', 'endorsed', '2026-11-02 10:00:00');
        $this->history($application, 'cgs_verify', 'returned', '2026-11-04 09:00:00');

        $expected = $this->charter()->expectedDecision($application);

        $this->assertSame('supervisor', $expected['clock']['stage']->key);
        $this->assertSame('2026-11-04', $expected['clock']['entered_at']->format('Y-m-d'));
        // Wed 4 + 3 = Mon 9, then CGS's 2 = Wed 11.
        $this->assertSame('2026-11-09', $expected['stage_due_date']->format('Y-m-d'));
        $this->assertSame('2026-11-11', $expected['expected_date']->format('Y-m-d'));
        $this->assertFalse($expected['clock']['overdue']);
    }

    public function test_decided_and_draft_applications_return_nothing(): void
    {
        foreach ([
            Application::STATUS_APPROVED,
            Application::STATUS_REJECTED,
            Application::STATUS_RETURNED,
            Application::STATUS_DRAFT,
        ] as $status) {
            $application = $this->gaExtension('supervisor', 'cgs', $status);

            $this->assertNull($this->charter()->expectedDecision($application), $status);
            $this->assertNull($this->charter()->stageClock($application), $status);
        }
    }

    public function test_a_stage_is_not_overdue_on_its_due_date(): void
    {
        $application = $this->gaExtension('supervisor', 'cgs');

        $this->travelTo(Carbon::parse('2026-11-05 17:00'));
        $this->assertFalse($this->charter()->stageClock($application)['overdue']);

        $this->travelTo(Carbon::parse('2026-11-06 08:00'));
        $clock = $this->charter()->stageClock($application);
        $this->assertTrue($clock['overdue']);
        $this->assertSame(1, $clock['working_days_overdue']);
    }

    public function test_a_stage_reached_at_the_weekend_starts_its_clock_on_monday(): void
    {
        // Reached Saturday 7 Nov. A 3-day stage: Monday is day 0, due
        // Thursday 12 -- and a 0-day stage is due Monday, never Saturday.
        $this->travelTo(Carbon::parse('2026-11-07 12:00'));
        $application = $this->gaExtension('supervisor', 'cgs', submittedAt: '2026-11-07 10:00');

        $clock = $this->charter()->stageClock($application);
        $this->assertSame('2026-11-12', $clock['due_date']->format('Y-m-d'));
        $this->assertFalse($clock['overdue']);

        $supervisor = $this->supervisor();
        $supervision = $this->application('supervision', 'cgs_review', submittedAt: '2026-11-08 10:00');
        SupervisionDetail::create([
            'application_id' => $supervision->id,
            'requested_supervisor_id' => $supervisor->id,
            'justification' => 'Test fixture.',
            'appointment_role' => SupervisionDetail::ROLE_MAIN,
            'cgs_stage_snapshot' => 'endorse',
        ]);

        $this->travelTo(Carbon::parse('2026-11-09 16:00'));
        $clock = $this->charter()->stageClock($supervision);
        $this->assertSame('2026-11-09', $clock['due_date']->format('Y-m-d'));
        $this->assertFalse($clock['overdue']);

        $this->travelTo(Carbon::parse('2026-11-10 08:00'));
        $this->assertTrue($this->charter()->stageClock($supervision)['overdue']);
    }
}
