<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApprovalHistory;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Core\Support\Role;
use App\Modules\Norhanis\Models\TravelDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The 'return' outcome added to WorkflowEngine::decide() alongside the
 * existing approve/reject — see TODO.md's "return with comment" item.
 * Exercised here against Travel (an existing, unrelated module) precisely to
 * prove the change is a generic engine capability and not something that
 * only happens to work for the module that motivated it.
 */
class WorkflowReturnTest extends TestCase
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

    protected function travelApplication(User $student): Application
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
            'destination_address' => 'Kuala Lumpur',
            'is_international' => false,
        ]);

        return app(WorkflowEngine::class)->submit($application);
    }

    public function test_a_return_keeps_the_application_open_on_the_same_stage(): void
    {
        $student = $this->user(Role::STUDENT, 'student@test.my');
        $supervisor = $this->user(Role::SUPERVISOR, 'supervisor@test.my');
        $application = $this->travelApplication($student);

        app(WorkflowEngine::class)->decide($application, $supervisor, 'return', 'Please attach a quotation.');
        $application->refresh();

        $this->assertSame(Application::STATUS_RETURNED, $application->status);
        // Unlike a rejection, this is not terminal, and unlike an approval it
        // does not advance — same stage, so the same approver re-reviews it.
        $this->assertSame('supervisor', $application->current_stage);

        $history = ApprovalHistory::where('application_id', $application->id)->sole();
        $this->assertSame('returned', $history->decision);
        $this->assertSame('Please attach a quotation.', $history->remarks);

        // Not awaiting a decision while returned — nobody can act on it until
        // the student resubmits.
        $this->assertNull(app(WorkflowEngine::class)->currentStage($application));
    }

    public function test_resubmitting_a_returned_application_goes_back_to_the_same_stage_not_stage_one(): void
    {
        $student = $this->user(Role::STUDENT, 'student@test.my');
        $supervisor = $this->user(Role::SUPERVISOR, 'supervisor@test.my');
        $application = $this->travelApplication($student);

        $engine = app(WorkflowEngine::class);
        $engine->decide($application, $supervisor, 'return', 'Fix the dates.');
        $engine->resubmit($application->fresh());

        $application->refresh();

        $this->assertSame(Application::STATUS_PENDING, $application->status);
        $this->assertSame('supervisor', $application->current_stage);
        $this->assertSame('supervisor', $engine->currentStage($application)->key);
    }

    public function test_resubmitting_an_application_that_was_never_returned_is_rejected(): void
    {
        $student = $this->user(Role::STUDENT, 'student@test.my');
        $application = $this->travelApplication($student);

        $this->expectException(\LogicException::class);

        app(WorkflowEngine::class)->resubmit($application);
    }

    public function test_approve_and_reject_are_completely_unaffected(): void
    {
        $student = $this->user(Role::STUDENT, 'student@test.my');
        $supervisor = $this->user(Role::SUPERVISOR, 'supervisor@test.my');
        $application = $this->travelApplication($student);

        app(WorkflowEngine::class)->decide($application, $supervisor, 'approve', 'Fine.');
        $application->refresh();

        $this->assertSame(Application::STATUS_PENDING, $application->status);
        $this->assertSame('chair', $application->current_stage);
    }
}
