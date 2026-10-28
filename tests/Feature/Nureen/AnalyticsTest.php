<?php

namespace Tests\Feature\Nureen;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApprovalHistory;
use App\Modules\Nureen\Models\GaExtensionDetail;
use App\Modules\Nureen\Support\ProcessingTimeAnalytics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * Processing-time analytics for CGS Staff — average days per (module,
 * stage), computed from approval_history, and the overdue list, which
 * reuses the exact same per-module threshold the stalled-reminder commands
 * fire on.
 */
class AnalyticsTest extends TestCase
{
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

    public function test_average_days_is_computed_from_consecutive_approval_history_rows(): void
    {
        $supervisor = $this->supervisor();
        $student = $this->student(attributes: ['supervisor_id' => $supervisor->id]);

        $application = Application::create([
            'student_id' => $student->id,
            'submitted_by_id' => $student->id,
            'module_type' => 'ga_extension',
            'status' => Application::STATUS_APPROVED,
            'current_stage' => 'senior_director',
            'submitted_at' => now()->subDays(10),
        ]);
        GaExtensionDetail::create([
            'application_id' => $application->id,
            'current_end_date' => now()->subMonth(),
            'requested_new_end_date' => now()->addMonths(5),
            'reason_for_extension' => 'Test fixture.',
        ]);

        // Exactly 4 days from submission to the supervisor's decision.
        $decision = ApprovalHistory::create([
            'application_id' => $application->id,
            'approver_id' => $supervisor->id,
            'stage_key' => 'supervisor',
            'stage_label' => 'Lecturer/Supervisor',
            'decision' => 'endorsed',
        ]);
        DB::table('approval_history')->where('id', $decision->id)
            ->update(['created_at' => $application->submitted_at->addDays(4)]);

        $averages = app(ProcessingTimeAnalytics::class)->averageDaysByStageAndModule();

        $row = $averages->firstWhere('stage_key', 'supervisor');
        $this->assertNotNull($row);
        $this->assertSame('GA Extension', $row['module_label']);
        $this->assertSame(4.0, $row['average_days']);
        $this->assertSame(1, $row['decisions']);
    }

    public function test_overdue_applications_match_each_modules_reminder_threshold(): void
    {
        $supervisor = $this->supervisor();
        $student = $this->student(attributes: ['supervisor_id' => $supervisor->id]);

        // Stalled 4 days (over the configured 3-day threshold).
        $overdue = Application::create([
            'student_id' => $student->id,
            'submitted_by_id' => $student->id,
            'module_type' => 'ga_extension',
            'status' => Application::STATUS_PENDING,
            'current_stage' => 'supervisor',
            'submitted_at' => now()->subDays(5),
        ]);
        GaExtensionDetail::create([
            'application_id' => $overdue->id,
            'current_end_date' => now()->subMonth(),
            'requested_new_end_date' => now()->addMonths(5),
            'reason_for_extension' => 'Overdue fixture.',
        ]);
        DB::table('applications')->where('id', $overdue->id)->update(['updated_at' => now()->subDays(4)]);

        // Only 1 day old -- must not appear.
        $fresh = Application::create([
            'student_id' => $student->id,
            'submitted_by_id' => $student->id,
            'module_type' => 'ga_extension',
            'status' => Application::STATUS_PENDING,
            'current_stage' => 'supervisor',
            'submitted_at' => now()->subDay(),
        ]);
        GaExtensionDetail::create([
            'application_id' => $fresh->id,
            'current_end_date' => now()->subMonth(),
            'requested_new_end_date' => now()->addMonths(5),
            'reason_for_extension' => 'Fresh fixture.',
        ]);
        DB::table('applications')->where('id', $fresh->id)->update(['updated_at' => now()->subDay()]);

        $overdueList = app(ProcessingTimeAnalytics::class)->overdueApplications();

        $this->assertTrue($overdueList->contains(fn ($row) => $row['application']->id === $overdue->id));
        $this->assertFalse($overdueList->contains(fn ($row) => $row['application']->id === $fresh->id));
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
