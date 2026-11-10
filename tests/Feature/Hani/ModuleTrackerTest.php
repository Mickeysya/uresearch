<?php

namespace Tests\Feature\Hani;

use Tests\Feature\Hani\Concerns\KeepsJobsOffTheDevQueue;
use App\Modules\Core\Models\Application;
use App\Modules\Hani\Models\Examiner;
use App\Modules\Hani\Models\ExaminerNomination;
use App\Modules\Hani\Models\ReVivaDetail;
use App\Modules\Hani\Support\ModuleTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * The re-viva tracker's round and result rules: levels 1-3 pass, level 4
 * loops into another round, level 5 is final.
 */
class ModuleTrackerTest extends TestCase
{
    use KeepsJobsOffTheDevQueue;
    use MakesUsers;
    use RefreshDatabase;

    protected function cycle(): ReVivaDetail
    {
        $application = Application::create(['student_id' => $this->student()->id, 'module_type' => 're_viva', 'status' => Application::STATUS_DRAFT]);
        ReVivaDetail::create(['application_id' => $application->id, 'cycle_number' => 1, 'resubmission_at' => now()]);
        app(\App\Modules\Core\Services\WorkflowEngine::class)->submit($application);

        return ReVivaDetail::with('application')->sole();
    }

    public function test_a_new_round_waits_for_the_document_check(): void
    {
        $tracker = ModuleTracker::reviva($this->cycle());

        $this->assertSame('Re-viva round 1', $tracker['title']);
        $this->assertCount(10, $tracker['steps']);
        $this->assertSame('current', $tracker['steps'][0]['state']);
        $this->assertStringContainsString('checking', $tracker['now']);

        $this->actingAs($this->seniorExec())->get(route('reviva.queue'))->assertOk()->assertSee('Re-viva round 1');
    }

    public function test_outcome_levels(): void
    {
        $detail = $this->cycle();
        $detail->application->forceFill(['status' => Application::STATUS_APPROVED])->save();

        $detail->update(['outcome_level' => 2]);
        $this->assertStringContainsString('passed with corrections', ModuleTracker::reviva($detail)['now']);

        $detail->update(['outcome_level' => ReVivaDetail::OUTCOME_LOOP_BACK]);
        $tracker = ModuleTracker::reviva($detail);
        $this->assertStringContainsString('Round 2', $tracker['now']);
        $this->assertSame('rejected', $tracker['steps'][9]['state']);

        $detail->update(['outcome_level' => ReVivaDetail::OUTCOME_DISMISSED]);
        $this->assertStringContainsString('no further action', ModuleTracker::reviva($detail)['now']);
    }

    public function test_the_nomination_queue_shows_where_each_nomination_is(): void
    {
        $student = $this->student(attributes: ['department' => 'Computing']);
        $ae = $this->academicExec();
        $ae->update(['department' => 'Computing']);

        $application = Application::create([
            'student_id' => $student->id,
            'module_type' => 'examiner_nomination',
            'status' => Application::STATUS_PENDING,
            'current_stage' => 'academic_exec',
            'submitted_at' => now(),
        ]);
        ExaminerNomination::create([
            'application_id' => $application->id,
            'internal_main_id' => Examiner::create([
                'name' => 'Dr. Internal', 'email' => 'internal@test.my', 'department' => 'Computing',
                'faculty' => 'FSMC', 'type' => Examiner::TYPE_INTERNAL, 'is_active' => true,
            ])->id,
            'thesis_title' => 'Constraint-based examiner matching',
        ]);

        $this->actingAs($ae)->get(route('examiner-nomination.queue'))
            ->assertOk()
            ->assertSee('CGS Compilation')
            ->assertSee('With Academic Executive.');
    }
}
