<?php

namespace Tests\Feature\Hani;

use App\Modules\Core\Support\Role;
use App\Modules\Hani\Models\ReVivaDetail;
use App\Modules\Hani\Workflows\ReVivaWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Hani\Concerns\KeepsJobsOffTheDevQueue;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * Prompt 3 item a: who does what in re-viva.
 *
 * The Senior Executive logs; the Academic Executive runs the stages and the
 * outcome; the Non-Executive CGS reads Re-viva Cycles and can act on nothing.
 */
class ReVivaRolesTest extends TestCase
{
    use KeepsJobsOffTheDevQueue;
    use MakesUsers;
    use RefreshDatabase;

    protected $senior = null;

    protected function logCycle($student): ReVivaDetail
    {
        $this->senior ??= $this->seniorExec();

        $this->actingAs($this->senior)->post(route('reviva.store'), [
            'student_id' => $student->id,
            'thesis' => UploadedFile::fake()->create('corrected-thesis.pdf', 64, 'application/pdf'),
        ])->assertSessionHasNoErrors();

        return ReVivaDetail::whereHas('application', fn ($q) => $q->where('student_id', $student->id))
            ->latest('cycle_number')->firstOrFail();
    }

    public function test_the_non_executive_can_read_cycles_but_every_re_viva_action_is_forbidden(): void
    {
        Storage::fake('local');

        $student = $this->student(attributes: ['name' => 'Candidate One']);
        $detail = $this->logCycle($student);
        $nonExec = $this->cgs();

        $this->actingAs($nonExec)->get(route('reviva.cycles.index'))
            ->assertOk()
            ->assertSee('Candidate One')
            ->assertDontSee('Log Re-viva Submission')
            ->assertDontSee(route('reviva.create'), false)
            ->assertDontSee('action="'.route('reviva.store').'"', false)
            ->assertDontSee('/re-viva/'.$detail->application_id.'/decide', false)
            ->assertDontSee(route('reviva.outcomes.record', $detail), false);

        $this->actingAs($nonExec)->get(route('reviva.create'))->assertForbidden();
        $this->actingAs($nonExec)->post(route('reviva.store'), ['student_id' => $student->id])->assertForbidden();
        $this->actingAs($nonExec)->get(route('reviva.queue'))->assertForbidden();
        $this->actingAs($nonExec)->post(route('reviva.decide', $detail->application_id), ['decision' => 'approve'])->assertForbidden();
        $this->actingAs($nonExec)->get(route('reviva.outcomes.index'))->assertForbidden();
        $this->actingAs($nonExec)->post(route('reviva.outcomes.record', $detail), ['outcome_level' => 1])->assertForbidden();

        $this->assertSame(1, ReVivaDetail::count());
        $this->assertNull($detail->fresh()->outcome_level);
    }

    public function test_the_senior_executive_logs_and_sees_the_next_round_link_for_level_four(): void
    {
        Storage::fake('local');

        $student = $this->student();
        $this->logCycle($student)->update(['outcome_level' => ReVivaDetail::OUTCOME_LOOP_BACK]);

        $this->actingAs($this->senior)->get(route('reviva.cycles.index'))
            ->assertOk()
            ->assertSee('Log Re-viva Submission')
            ->assertSee('Next round to log (1)')
            ->assertSee(route('reviva.create', ['student' => $student->id]), false)
            ->assertSee('Log round 2');

        $this->actingAs($this->senior)->get(route('reviva.create'))->assertOk();
    }

    public function test_cycles_are_not_for_the_academic_executive_or_the_student(): void
    {
        $this->actingAs($this->academicExec())->get(route('reviva.cycles.index'))->assertForbidden();
        $this->actingAs($this->student())->get(route('reviva.cycles.index'))->assertForbidden();
    }

    public function test_sidebar_links_follow_the_roles(): void
    {
        $routes = fn (string $role) => array_column((new ReVivaWorkflow())->links($this->user($role, ['email' => $role.'@test.my'])), 'route');

        $this->assertSame(['reviva.create', 'reviva.cycles.index'], $routes(Role::SENIOR_EXEC_CGS));
        $this->assertSame(['reviva.cycles.index'], $routes(Role::NON_EXEC_CGS));
        $this->assertSame(['reviva.outcomes.index'], $routes(Role::ACADEMIC_EXEC));
    }
}
