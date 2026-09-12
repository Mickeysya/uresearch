<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApprovalHistory;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Core\Support\Role;
use App\Modules\Norhanis\Models\TravelDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\UnauthorizedException;
use Tests\TestCase;

/**
 * The two authorisation locks, which are the portal's central safety claim.
 *
 * The legacy app checked only that *somebody* was signed in on every approval
 * page, so a student could open the Dean's URL and grant final approval to
 * their own application. The rewrite closes that twice over -- `role:` on the
 * route, and a second check inside WorkflowEngine::decide() against the stage
 * the application is actually sitting on -- and both had only ever been
 * verified by hand (see "Verified working" in TODO.md).
 *
 * A hand-check confirms the locks work today. These confirm they still work
 * after the next person edits the engine.
 */
class WorkflowAuthorisationTest extends TestCase
{
    use RefreshDatabase;

    protected function user(string $role, string $email): User
    {
        return User::create([
            'name' => ucfirst($role),
            'email' => $email,
            'password' => 'password',
            'role' => $role,
        ]);
    }

    /** A submitted travel application, sitting on its first stage. */
    protected function travelApplication(User $student, bool $international = false): Application
    {
        $application = Application::create([
            'student_id' => $student->id,
            'submitted_by_id' => $student->id,
            'module_type' => 'travel',
            'status' => Application::STATUS_DRAFT,
        ]);

        TravelDetail::create([
            'application_id' => $application->id,
            'type_of_request' => 'conference',
            'travel_start_date' => now()->addWeek(),
            'travel_end_date' => now()->addWeeks(2),
            'duration_days' => 8,
            'reason_for_travel' => 'Present a paper',
            'destination_address' => $international ? 'Singapore' : 'Kuala Lumpur',
            'is_international' => $international,
        ]);

        return app(WorkflowEngine::class)->submit($application);
    }

    public function test_a_student_cannot_post_to_a_decide_route(): void
    {
        $student = $this->user(Role::STUDENT, 'student@test.my');
        $application = $this->travelApplication($student);

        // Lock one: the route's `role:` middleware. The student never reaches
        // the controller, let alone the engine.
        $this->actingAs($student)
            ->post(route('travel.decide', $application), ['decision' => 'approve'])
            ->assertForbidden();

        $this->assertSame('supervisor', $application->fresh()->current_stage);
        $this->assertSame(Application::STATUS_PENDING, $application->fresh()->status);
    }

    public function test_an_approver_cannot_act_on_a_stage_that_is_not_theirs(): void
    {
        $student = $this->user(Role::STUDENT, 'student@test.my');
        $dean = $this->user(Role::DEAN_PGR, 'dean@test.my');
        $application = $this->travelApplication($student, international: true);

        // Lock two: the Dean holds a real stage in this chain and passes the
        // route middleware, but the application is still with the supervisor.
        $this->expectException(UnauthorizedException::class);

        try {
            app(WorkflowEngine::class)->decide($application, $dean, 'approve');
        } finally {
            // The row must be untouched -- no history, no stage advance.
            $this->assertSame('supervisor', $application->fresh()->current_stage);
            $this->assertSame(0, ApprovalHistory::where('application_id', $application->id)->count());
        }
    }

    public function test_the_right_approver_advances_the_application(): void
    {
        $student = $this->user(Role::STUDENT, 'student@test.my');
        $supervisor = $this->user(Role::SUPERVISOR, 'supervisor@test.my');
        $application = $this->travelApplication($student);

        app(WorkflowEngine::class)->decide($application, $supervisor, 'approve', 'Looks fine.');

        $application->refresh();

        $this->assertSame('chair', $application->current_stage);
        $this->assertSame(Application::STATUS_PENDING, $application->status);

        $history = ApprovalHistory::where('application_id', $application->id)->sole();
        $this->assertSame('endorsed', $history->decision);
        $this->assertSame($supervisor->id, $history->approver_id);
        $this->assertSame('Looks fine.', $history->remarks);
    }

    public function test_a_rejection_closes_the_application_and_holds_its_stage(): void
    {
        $student = $this->user(Role::STUDENT, 'student@test.my');
        $supervisor = $this->user(Role::SUPERVISOR, 'supervisor@test.my');
        $application = $this->travelApplication($student);

        app(WorkflowEngine::class)->decide($application, $supervisor, 'reject', 'Dates clash.');

        $application->refresh();

        $this->assertSame(Application::STATUS_REJECTED, $application->status);
        // current_stage deliberately stays put, so the stepper can show the
        // student exactly where it stopped.
        $this->assertSame('supervisor', $application->current_stage);
    }
}
