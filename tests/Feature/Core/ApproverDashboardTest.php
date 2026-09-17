<?php

namespace Tests\Feature\Core;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\GeneralApproverDashboard;
use App\Modules\Core\Services\ModuleRegistry;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Core\Support\Role;
use App\Modules\Norhanis\Models\TravelDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * The screen every approving role without one of its own lands on: the Dean
 * of PGR, the Academic Executive, the Registry, the Faculty office, the
 * Senior Executive.
 *
 * It was the last screen on the pre-.sdash markup, and the one that showed
 * why the Chair and Supervisor needed their own: it built ONE STAT CARD PER
 * QUEUE, so the Academic Executive got seven cards of which six normally read
 * zero, over a chart of six categories with one bar in it.
 */
class ApproverDashboardTest extends TestCase
{
    use MakesUsers;
    use RefreshDatabase;

    protected function internationalTravel(User $student, int $daysAgo): Application
    {
        $application = Application::create([
            'student_id' => $student->id,
            'submitted_by_id' => $student->id,
            'module_type' => 'travel',
            'status' => Application::STATUS_PENDING,
            'current_stage' => 'dean',
            'submitted_at' => now()->subDays($daysAgo),
        ]);

        TravelDetail::create([
            'application_id' => $application->id,
            'type_of_request' => 'Conference',
            'travel_start_date' => now()->addMonth(),
            'travel_end_date' => now()->addMonth()->addDays(3),
            'duration_days' => 4,
            'reason_for_travel' => 'Keynote',
            'destination_address' => 'Singapore',
            'is_international' => true,
        ]);

        return $application;
    }

    public function test_the_dean_gets_the_shared_approver_screen_not_the_old_one(): void
    {
        $this->actingAs($this->dean())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Awaiting your decision')
            ->assertSee('How long they have waited')
            ->assertSee('Your queues')
            ->assertSee('Your recent decisions')
            // The old screen's markup and its one-card-per-queue row.
            ->assertDontSee('stat-cards-row', false)
            ->assertDontSee('dashboardChart', false);
    }

    public function test_the_academic_executive_gets_it_too_with_its_module_shortcuts(): void
    {
        $this->actingAs($this->academicExec())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Awaiting your decision')
            // Declared by Hani's module through ProvidesLinks, so they reach
            // the examiner screens Core knows nothing about.
            ->assertSee('Pending Evaluation')
            ->assertSee('Conflict Detection')
            ->assertSee('Re-viva Outcomes');
    }

    /**
     * The fifth card, and the one that means most to a final approver: the
     * Dean is the last stage on international travel, so their approval is
     * what finishes the thing.
     */
    public function test_finalised_counts_only_applications_that_ended_with_this_approver(): void
    {
        $dean = $this->dean();
        $student = $this->student();
        $engine = app(WorkflowEngine::class);

        $finished = $this->internationalTravel($student, 4);
        $stillOpen = $this->internationalTravel($student, 2);

        // The Dean is the last stage here, so approving ends it.
        $engine->decide($finished, $dean, 'approve');
        $this->assertSame(Application::STATUS_APPROVED, $finished->fresh()->status);

        $dash = new GeneralApproverDashboard($dean, app(ModuleRegistry::class));

        $this->assertSame(1, $dash->finalisedByMe(), 'Only the one that ended at their decision.');
        $this->assertSame(1, $dash->awaitingMe(), 'The other is still waiting on them.');

        // And it shows up in their trail.
        $this->actingAs($dean)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Finalised by you')
            ->assertSee('ended at your decision')
            ->assertSee('#'.$finished->id);

        $this->assertSame(Application::STATUS_PENDING, $stillOpen->fresh()->status);
    }

    /** A decision by someone else is not this approver's to claim. */
    public function test_another_approvers_decision_is_not_counted_or_listed(): void
    {
        $dean = $this->dean();
        $other = $this->user(Role::DEAN_PGR, ['name' => 'Other Dean', 'email' => 'dean2@test.my']);

        $application = $this->internationalTravel($this->student(), 3);
        app(WorkflowEngine::class)->decide($application, $other, 'approve');

        $dash = new GeneralApproverDashboard($dean, app(ModuleRegistry::class));
        $this->assertSame(0, $dash->finalisedByMe());
        $this->assertTrue($dash->myRecentDecisions()->isEmpty());

        $this->actingAs($dean)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('You have not decided anything yet.');
    }
}
