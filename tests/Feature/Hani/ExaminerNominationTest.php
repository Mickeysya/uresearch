<?php

namespace Tests\Feature\Hani;

use App\Modules\Core\Models\Application;
use App\Modules\Hani\Models\Examiner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * Examiner nomination and the pool lifecycle — `docs/scope/hani.md`.
 *
 * The examiner pool's four-state machine is the part the Stage graph cannot
 * express, so nothing in WorkflowEngine guards it: an approval has to tie both
 * nominees up, and only a finished chain may do so. State itself is derived in
 * Examiner::state(), so the only stored half is what these cover.
 */
class ExaminerNominationTest extends TestCase
{
    use MakesUsers;
    use RefreshDatabase;

    protected function examiner(string $name, string $email): Examiner
    {
        return Examiner::create([
            'name' => $name,
            'email' => $email,
            'department' => 'Computer and Information Sciences',
            'faculty' => 'FSMC',
            'type' => 'internal',
            'is_active' => true,
        ]);
    }

    public function test_final_approval_ties_up_both_examiners(): void
    {
        $supervisor = $this->supervisor();
        $student = $this->student(attributes: ['supervisor_id' => $supervisor->id]);
        $ae = $this->academicExec();

        $main = $this->examiner('Prof. Tan', 'tan@test.my');
        $backup = $this->examiner('Dr. Kumar', 'kumar@test.my');

        $this->actingAs($supervisor)
            ->post(route('examiner-nomination.store'), [
                'student_id' => $student->id,
                'thesis_title' => 'A Study of Something',
                'main_examiner_id' => $main->id,
                'backup_examiner_id' => $backup->id,
            ])
            ->assertSessionHasNoErrors();

        $application = Application::sole();

        // Neither examiner is tied up until the chain actually finishes.
        $this->assertNull($main->fresh()->assigned_until);

        $this->actingAs($ae)
            ->post(route('examiner-nomination.decide', $application), ['decision' => 'approve']);

        $this->assertSame(Application::STATUS_APPROVED, $application->fresh()->status);
        $this->assertNotNull($main->fresh()->assigned_until);
        $this->assertNotNull($backup->fresh()->assigned_until);
    }

    public function test_a_rejected_nomination_leaves_the_pool_untouched(): void
    {
        $supervisor = $this->supervisor();
        $student = $this->student(attributes: ['supervisor_id' => $supervisor->id]);
        $ae = $this->academicExec();
        $main = $this->examiner('Prof. Tan', 'tan@test.my');

        $this->actingAs($supervisor)->post(route('examiner-nomination.store'), [
            'student_id' => $student->id,
            'thesis_title' => 'A Study of Something',
            'main_examiner_id' => $main->id,
        ]);

        $this->actingAs($ae)->post(
            route('examiner-nomination.decide', Application::sole()),
            ['decision' => 'reject', 'remarks' => 'Conflict of interest.']
        );

        $this->assertNull($main->fresh()->assigned_until);
    }
}
