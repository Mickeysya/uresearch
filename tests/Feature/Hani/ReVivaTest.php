<?php

namespace Tests\Feature\Hani;

use App\Modules\Hani\Models\ReVivaDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * Re-viva monitoring — `docs/scope/hani.md`.
 *
 * The one-open-cycle rule and the level-4 loop back are enforced in the
 * controller, not by the Stage graph: a loop back is a *new* application
 * linked to the previous cycle, because WorkflowEngine has no way to express
 * a cycle. That makes these the only guard on the rule.
 */
class ReVivaTest extends TestCase
{
    use MakesUsers;
    use RefreshDatabase;

    protected function thesis(): UploadedFile
    {
        return UploadedFile::fake()->create('corrected-thesis.pdf', 64, 'application/pdf');
    }

    /** The latest cycle logged for a student, whatever its outcome. */
    protected function latestCycleFor(int $studentId): ?ReVivaDetail
    {
        return ReVivaDetail::whereHas('application', fn ($q) => $q->where('student_id', $studentId))
            ->latest('cycle_number')
            ->first();
    }

    public function test_cgs_can_log_a_first_cycle_and_not_a_second_while_it_is_open(): void
    {
        Storage::fake('local');

        $cgs = $this->cgs();
        $student = $this->student();

        $this->actingAs($cgs)
            ->post(route('reviva.store'), ['student_id' => $student->id, 'thesis' => $this->thesis()])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, ReVivaDetail::sole()->cycle_number);

        // The first cycle has no outcome yet, so a second may not be opened.
        $this->actingAs($cgs)
            ->post(route('reviva.store'), ['student_id' => $student->id, 'thesis' => $this->thesis()])
            ->assertSessionHasErrors('student_id');

        $this->assertSame(1, ReVivaDetail::count());
    }

    public function test_only_a_level_four_outcome_opens_another_cycle(): void
    {
        Storage::fake('local');

        $cgs = $this->cgs();
        $passed = $this->student('22001001', 'a@test.my');
        $loops = $this->student('22001002', 'b@test.my');

        foreach ([[$passed, 1], [$loops, ReVivaDetail::OUTCOME_LOOP_BACK]] as [$student, $level]) {
            $this->actingAs($cgs)->post(route('reviva.store'), [
                'student_id' => $student->id,
                'thesis' => $this->thesis(),
            ]);

            $this->latestCycleFor($student->id)->update(['outcome_level' => $level]);
        }

        // Level 1 is a pass: the re-viva is over, no new cycle.
        $this->actingAs($cgs)
            ->post(route('reviva.store'), ['student_id' => $passed->id, 'thesis' => $this->thesis()])
            ->assertSessionHasErrors('student_id');

        // Level 4 loops back: CGS may log the next cycle, linked to the last.
        $this->actingAs($cgs)
            ->post(route('reviva.store'), ['student_id' => $loops->id, 'thesis' => $this->thesis()])
            ->assertSessionHasNoErrors();

        $second = $this->latestCycleFor($loops->id);

        $this->assertSame(2, $second->cycle_number);
        $this->assertNotNull($second->previous_cycle_id);
    }

    /** The create form flags every student it cannot open a cycle for, and says why. */
    public function test_the_create_form_reports_who_is_blocked(): void
    {
        Storage::fake('local');

        $cgs = $this->cgs();
        $blocked = $this->student('22001001', 'a@test.my');
        $free = $this->student('22001002', 'b@test.my');

        $this->actingAs($cgs)->post(route('reviva.store'), [
            'student_id' => $blocked->id,
            'thesis' => $this->thesis(),
        ]);

        $reasons = $this->actingAs($cgs)->get(route('reviva.create'))
            ->assertOk()
            ->viewData('blockedReasons');

        $this->assertNotNull($reasons[$blocked->id]);
        $this->assertNull($reasons[$free->id]);
    }
}
