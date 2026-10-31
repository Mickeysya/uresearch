<?php

namespace Tests\Feature\Nureen;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApprovalHistory;
use App\Modules\Nureen\Models\GaExtensionDetail;
use App\Modules\Nureen\Support\ProcessingTimeAnalytics;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\MakesUsers;
use Tests\Feature\Nureen\Concerns\KeepsJobsOffTheDevQueue;
use Tests\TestCase;

/**
 * Client Charter compliance for CGS Staff — per (module, stage), the share
 * of decisions made within the charter and the average working days taken,
 * computed from approval_history — and the overdue list, which uses the
 * exact same Support\ClientCharter rule the reminder commands fire on.
 */
class AnalyticsTest extends TestCase
{
    use KeepsJobsOffTheDevQueue;
    use MakesUsers;
    use RefreshDatabase;

    public function test_only_non_exec_cgs_can_open_the_page(): void
    {
        $this->actingAs($this->student())
            ->get(route('nureen.analytics'))
            ->assertForbidden();

        $this->actingAs($this->cgs())
            ->get(route('nureen.analytics'))
            ->assertOk();
    }

    /**
     * Builds one application with its decisions at fixed timestamps.
     *
     * @param  array<int, array{0: string, 1: string, 2: string}>  $decisions  [stage_key, decision, Y-m-d H:i]
     */
    protected function decidedApplication(string $module, string $submittedAt, array $decisions, string $status = Application::STATUS_APPROVED): Application
    {
        $approver = $this->supervisor('approver-'.uniqid().'@test.my');
        $student = $this->student(uniqid(), 'student-'.uniqid().'@test.my');

        $application = Application::create([
            'student_id' => $student->id,
            'submitted_by_id' => $student->id,
            'module_type' => $module,
            'status' => $status,
            'current_stage' => end($decisions)[0],
            'submitted_at' => Carbon::parse($submittedAt),
        ]);

        foreach ($decisions as [$stageKey, $decision, $at]) {
            $row = ApprovalHistory::create([
                'application_id' => $application->id,
                'approver_id' => $approver->id,
                'stage_key' => $stageKey,
                'stage_label' => $stageKey,
                'decision' => $decision,
            ]);
            DB::table('approval_history')->where('id', $row->id)->update(['created_at' => $at.':00']);
        }

        return $application;
    }

    public function test_compliance_gives_the_exact_share_of_decisions_within_charter(): void
    {
        $this->travelTo(Carbon::parse('2026-11-20 12:00'));

        // Certification: supervisor 3, cgs_verify 2, senior_director 2
        // working days. Working days are counted after the day the stage
        // was reached, up to and including the day it was decided.
        $this->decidedApplication('ga_certification', '2026-11-02 09:00', [
            ['supervisor', 'endorsed', '2026-11-04 10:00'],      // Mon -> Wed: 2, within
            ['cgs_verify', 'reviewed', '2026-11-09 10:00'],      // Wed -> Mon: 3, over 2
            ['senior_director', 'approved', '2026-11-10 10:00'], // Mon -> Tue: 1, within
        ]);
        $this->decidedApplication('ga_certification', '2026-11-05 09:00', [
            ['supervisor', 'endorsed', '2026-11-10 10:00'],      // Thu -> Tue: 3, within (weekend skipped)
            ['cgs_verify', 'reviewed', '2026-11-12 10:00'],      // Tue -> Thu: 2, within
        ], Application::STATUS_PENDING);
        $this->decidedApplication('ga_certification', '2026-11-06 09:00', [
            ['supervisor', 'rejected', '2026-11-12 10:00'],      // Fri -> Thu: 4, over 3
        ], Application::STATUS_REJECTED);
        $this->decidedApplication('ga_certification', '2026-11-02 09:00', [
            ['supervisor', 'endorsed', '2026-11-02 16:00'],      // same day: 0, within
        ], Application::STATUS_PENDING);

        $rows = app(ProcessingTimeAnalytics::class)->complianceByStageAndModule()->keyBy('stage_key');

        $this->assertSame(4, $rows['supervisor']['decisions']);
        $this->assertSame(3, $rows['supervisor']['within_charter']);
        $this->assertSame(75.0, $rows['supervisor']['within_charter_percent']);
        $this->assertSame(2.3, $rows['supervisor']['average_working_days']); // (2+3+4+0)/4 = 2.25
        $this->assertSame(3, $rows['supervisor']['charter_days']);

        $this->assertSame(2, $rows['cgs_verify']['decisions']);
        $this->assertSame(50.0, $rows['cgs_verify']['within_charter_percent']);
        $this->assertSame(2.5, $rows['cgs_verify']['average_working_days']);

        $this->assertSame(100.0, $rows['senior_director']['within_charter_percent']);

        // In chain order, not alphabetical.
        $this->assertSame(['supervisor', 'cgs_verify', 'senior_director'], $rows->keys()->all());
    }

    public function test_a_stage_with_no_charter_reports_its_average_but_no_percentage(): void
    {
        $this->travelTo(Carbon::parse('2026-11-20 12:00'));

        $this->decidedApplication('ga_extension', '2026-11-02 09:00', [
            ['supervisor', 'endorsed', '2026-11-03 10:00'],
            ['cgs_verify', 'reviewed', '2026-11-04 10:00'],
            ['senior_director', 'approved', '2026-11-09 10:00'], // Wed -> Mon: 3
        ]);

        $row = app(ProcessingTimeAnalytics::class)->complianceByStageAndModule()->firstWhere('stage_key', 'senior_director');

        $this->assertNull($row['charter_days']);
        $this->assertNull($row['within_charter_percent']);
        $this->assertSame(3.0, $row['average_working_days']);
    }

    public function test_overdue_applications_are_the_ones_past_their_charter_due_date(): void
    {
        // Friday 6 Nov.
        $this->travelTo(Carbon::parse('2026-11-06 10:00'));

        $supervisor = $this->supervisor();
        $student = $this->student(attributes: ['supervisor_id' => $supervisor->id]);

        // Reached the supervisor Monday: 4 working days, over its 3.
        $overdue = Application::create([
            'student_id' => $student->id,
            'submitted_by_id' => $student->id,
            'module_type' => 'ga_extension',
            'status' => Application::STATUS_PENDING,
            'current_stage' => 'supervisor',
            'submitted_at' => Carbon::parse('2026-11-02 09:00'),
        ]);
        GaExtensionDetail::create([
            'application_id' => $overdue->id,
            'current_end_date' => now()->subMonth(),
            'requested_new_end_date' => now()->addMonths(5),
            'reason_for_extension' => 'Overdue fixture.',
            'final_approver_snapshot' => 'cgs',
        ]);

        // Reached it Tuesday: 3 working days, exactly on the charter's due date.
        $onTime = Application::create([
            'student_id' => $student->id,
            'submitted_by_id' => $student->id,
            'module_type' => 'ga_extension',
            'status' => Application::STATUS_PENDING,
            'current_stage' => 'supervisor',
            'submitted_at' => Carbon::parse('2026-11-03 09:00'),
        ]);
        GaExtensionDetail::create([
            'application_id' => $onTime->id,
            'current_end_date' => now()->subMonth(),
            'requested_new_end_date' => now()->addMonths(5),
            'reason_for_extension' => 'On-time fixture.',
            'final_approver_snapshot' => 'cgs',
        ]);

        $overdueList = app(ProcessingTimeAnalytics::class)->overdueApplications();

        $row = $overdueList->first(fn ($row) => $row['application']->id === $overdue->id);
        $this->assertNotNull($row);
        $this->assertSame('2026-11-05', $row['due_date']->format('Y-m-d'));
        $this->assertSame(1, $row['working_days_overdue']);
        $this->assertFalse($overdueList->contains(fn ($row) => $row['application']->id === $onTime->id));
    }

    public function test_the_page_shows_compliance_figures(): void
    {
        $this->travelTo(Carbon::parse('2026-11-20 12:00'));

        $this->decidedApplication('ga_certification', '2026-11-02 09:00', [
            ['supervisor', 'endorsed', '2026-11-04 10:00'],
            ['cgs_verify', 'reviewed', '2026-11-09 10:00'],
        ], Application::STATUS_PENDING);

        $this->actingAs($this->cgs())
            ->get(route('nureen.analytics'))
            ->assertOk()
            ->assertSee('Decisions within the Client Charter')
            ->assertSeeInOrder(['Certification Letter', 'supervisor', '100%', '(1 of 1)'])
            ->assertSeeInOrder(['cgs_verify', '0%', '(0 of 1)']);
    }

    public function test_the_page_renders_with_no_data_at_all(): void
    {
        $this->actingAs($this->cgs())
            ->get(route('nureen.analytics'))
            ->assertOk()
            ->assertSee('No decisions recorded yet')
            ->assertSee('Nothing is currently overdue');
    }
}
