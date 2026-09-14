<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Hani\Models\Examiner;
use App\Modules\Hani\Models\ReVivaDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Hani's Examiner Nomination and Re-viva modules.
 *
 * Two pieces of state the Stage graph cannot express on its own, so nothing
 * else in the engine guards them: the examiner pool's four-state machine
 * (tied up on approval, released on evaluation) and the one-open-cycle rule
 * on re-viva, which is enforced in the controller rather than by a chain.
 */
class HaniModulesTest extends TestCase
{
    use RefreshDatabase;

    protected function user(string $name, string $email, string $role, array $extra = []): User
    {
        return User::create($extra + [
            'name' => $name,
            'email' => $email,
            'password' => 'password',
            'role' => $role,
        ]);
    }

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

    protected function thesis(): UploadedFile
    {
        return UploadedFile::fake()->create('corrected-thesis.pdf', 64, 'application/pdf');
    }

    /* -----------------------------------------------------------------
     | Examiner Nomination — the pool lifecycle
     |------------------------------------------------------------------*/

    public function test_final_approval_ties_up_both_examiners(): void
    {
        $supervisor = $this->user('Dr. Aisyah', 'sv@test.my', Role::SUPERVISOR);
        $student = $this->user('Ahmad Danial', 'student@test.my', Role::STUDENT, [
            'matric_no' => '22001001',
            'supervisor_id' => $supervisor->id,
        ]);
        $ae = $this->user('Siti', 'ae@test.my', Role::ACADEMIC_EXEC);

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
        $supervisor = $this->user('Dr. Aisyah', 'sv@test.my', Role::SUPERVISOR);
        $student = $this->user('Ahmad Danial', 'student@test.my', Role::STUDENT, [
            'matric_no' => '22001001',
            'supervisor_id' => $supervisor->id,
        ]);
        $ae = $this->user('Siti', 'ae@test.my', Role::ACADEMIC_EXEC);
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

    /* -----------------------------------------------------------------
     | Re-viva — one open cycle at a time
     |------------------------------------------------------------------*/

    public function test_cgs_can_log_a_first_cycle_and_not_a_second_while_it_is_open(): void
    {
        Storage::fake('local');

        $cgs = $this->user('Puan Waheeda', 'cgs@test.my', Role::NON_EXEC_CGS);
        $student = $this->user('Ahmad Danial', 'student@test.my', Role::STUDENT, [
            'matric_no' => '22001001',
        ]);

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

        $cgs = $this->user('Puan Waheeda', 'cgs@test.my', Role::NON_EXEC_CGS);
        $passed = $this->user('Ahmad Danial', 'a@test.my', Role::STUDENT, ['matric_no' => '22001001']);
        $loops = $this->user('Nur Farah', 'b@test.my', Role::STUDENT, ['matric_no' => '22001002']);

        foreach ([[$passed, 1], [$loops, ReVivaDetail::OUTCOME_LOOP_BACK]] as [$student, $level]) {
            $this->actingAs($cgs)->post(route('reviva.store'), [
                'student_id' => $student->id,
                'thesis' => $this->thesis(),
            ]);

            ReVivaDetail::whereHas('application', fn ($q) => $q->where('student_id', $student->id))
                ->latest('cycle_number')->first()
                ->update(['outcome_level' => $level]);
        }

        // Level 1 is a pass: the re-viva is over, no new cycle.
        $this->actingAs($cgs)
            ->post(route('reviva.store'), ['student_id' => $passed->id, 'thesis' => $this->thesis()])
            ->assertSessionHasErrors('student_id');

        // Level 4 loops back: CGS may log the next cycle, linked to the last.
        $this->actingAs($cgs)
            ->post(route('reviva.store'), ['student_id' => $loops->id, 'thesis' => $this->thesis()])
            ->assertSessionHasNoErrors();

        $second = ReVivaDetail::whereHas('application', fn ($q) => $q->where('student_id', $loops->id))
            ->latest('cycle_number')->first();

        $this->assertSame(2, $second->cycle_number);
        $this->assertNotNull($second->previous_cycle_id);
    }

    /** The create form flags every student it cannot open a cycle for, and says why. */
    public function test_the_create_form_reports_who_is_blocked(): void
    {
        Storage::fake('local');

        $cgs = $this->user('Puan Waheeda', 'cgs@test.my', Role::NON_EXEC_CGS);
        $blocked = $this->user('Ahmad Danial', 'a@test.my', Role::STUDENT, ['matric_no' => '22001001']);
        $free = $this->user('Nur Farah', 'b@test.my', Role::STUDENT, ['matric_no' => '22001002']);

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
