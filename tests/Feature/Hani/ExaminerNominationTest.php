<?php

namespace Tests\Feature\Hani;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Hani\Models\Examiner;
use App\Modules\Hani\Models\ExaminerNomination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * Examiner nomination, the pool lifecycle, and the six-stage chain the panel
 * travels along — `docs/scope/hani.md` and ExaminerNominationWorkflow.
 *
 * Three things here are not expressible as a Stage graph, so nothing in
 * WorkflowEngine guards them and these are what hold them up:
 *
 *   - the internal half of a panel must come from the candidate's own
 *     department, which no foreign key can state;
 *   - the pool is tied up by a FINISHED chain and no earlier approval;
 *   - the Senior Director and the Dean send a list back rather than
 *     rejecting it, and it has to replay forward from the department.
 */
class ExaminerNominationTest extends TestCase
{
    use MakesUsers;
    use RefreshDatabase;

    protected const DEPARTMENT = 'Computing';

    protected function internal(string $name, string $email, string $department = self::DEPARTMENT): Examiner
    {
        return Examiner::create([
            'name' => $name,
            'email' => $email,
            'department' => $department,
            'faculty' => 'FSMC',
            'type' => Examiner::TYPE_INTERNAL,
            'is_active' => true,
        ]);
    }

    protected function external(string $name, string $email): Examiner
    {
        return Examiner::create([
            'name' => $name,
            'email' => $email,
            'department' => 'External',
            'type' => Examiner::TYPE_EXTERNAL,
            'institution' => 'Universiti Malaya',
            'is_active' => true,
        ]);
    }

    /** A supervisor, their candidate, and an Academic Executive in the same department. */
    protected function cast(): array
    {
        $supervisor = $this->supervisor();

        $student = $this->student(attributes: [
            'supervisor_id' => $supervisor->id,
            'department' => self::DEPARTMENT,
        ]);

        $ae = $this->academicExec();
        $ae->update(['department' => self::DEPARTMENT]);

        return [$supervisor, $student, $ae];
    }

    /** @return array<string, int> a full, legal panel */
    protected function panel(): array
    {
        return [
            'internal_main_id' => $this->internal('Prof. Tan', 'tan@test.my')->id,
            'internal_backup_id' => $this->internal('Dr. Kumar', 'kumar@test.my')->id,
            'external_main_id' => $this->external('Prof. Nabila', 'nabila@um.test.my')->id,
            'external_backup_id' => $this->external('Dr. Chong', 'chong@usm.test.my')->id,
        ];
    }

    /** @var array<string, User> made once per test: MakesUsers inserts, it does not find. */
    protected array $chainActors = [];

    /**
     * Whoever acts at a stage above the department. Memoised because the
     * Senior Executive owns two of them (cgs_compile and cgs_final) and a
     * test often needs the same Dean again afterwards -- calling the
     * MakesUsers helper twice inserts the same email twice.
     */
    protected function actorFor(string $stage): User
    {
        return $this->chainActors[$stage] ??= match ($stage) {
            'cgs_compile', 'cgs_final' => $this->chainActors['cgs_compile']
                ?? $this->chainActors['cgs_final']
                ?? $this->seniorExec(),
            'senior_director' => $this->seniorDirector(),
            'dean' => $this->dean(),
            'cgs_release' => $this->cgs(),
        };
    }

    /**
     * Approves the application forward until it is sitting on $stageKey, or
     * to the end of the chain if it never gets there.
     */
    protected function advanceTo(Application $application, string $stageKey): void
    {
        foreach (['cgs_compile', 'senior_director', 'dean', 'cgs_final', 'cgs_release'] as $stage) {
            $actor = $this->actorFor($stage);

            if ($application->fresh()->current_stage === $stageKey) {
                return;
            }

            $this->actingAs($actor)
                ->post(route('examiner-nomination.decide', $application), ['decision' => 'approve'])
                ->assertSessionHasNoErrors();
        }
    }

    public function test_a_supervisor_files_a_four_seat_panel(): void
    {
        [$supervisor, $student] = $this->cast();
        $panel = $this->panel();

        $this->actingAs($supervisor)
            ->post(route('examiner-nomination.store'), [
                'student_id' => $student->id,
                'thesis_title' => 'A Study of Something',
            ] + $panel)
            ->assertSessionHasNoErrors();

        $nomination = ExaminerNomination::sole();

        foreach ($panel as $slot => $id) {
            $this->assertSame($id, $nomination->$slot, "{$slot} was not stored.");
        }

        $this->assertCount(4, $nomination->examiners());
        $this->assertSame('academic_exec', Application::sole()->current_stage);
    }

    public function test_an_internal_examiner_from_another_department_is_refused(): void
    {
        [$supervisor, $student] = $this->cast();

        $this->actingAs($supervisor)
            ->post(route('examiner-nomination.store'), [
                'student_id' => $student->id,
                'thesis_title' => 'A Study of Something',
                'internal_main_id' => $this->internal('Dr. Elsewhere', 'elsewhere@test.my', 'Petroleum Engineering')->id,
                'external_main_id' => $this->external('Prof. Nabila', 'nabila@um.test.my')->id,
            ])
            ->assertSessionHasErrors('internal_main_id');

        $this->assertSame(0, ExaminerNomination::count());
    }

    public function test_an_external_examiner_cannot_take_an_internal_seat(): void
    {
        [$supervisor, $student] = $this->cast();

        $this->actingAs($supervisor)
            ->post(route('examiner-nomination.store'), [
                'student_id' => $student->id,
                'thesis_title' => 'A Study of Something',
                'internal_main_id' => $this->external('Prof. Nabila', 'nabila@um.test.my')->id,
                'external_main_id' => $this->external('Dr. Chong', 'chong@usm.test.my')->id,
            ])
            ->assertSessionHasErrors('internal_main_id');
    }

    public function test_the_pool_is_only_tied_up_once_the_whole_chain_is_done(): void
    {
        [$supervisor, $student, $ae] = $this->cast();
        $panel = $this->panel();

        $this->actingAs($supervisor)->post(route('examiner-nomination.store'), [
            'student_id' => $student->id,
            'thesis_title' => 'A Study of Something',
        ] + $panel);

        $application = Application::sole();

        $this->actingAs($ae)->post(route('examiner-nomination.decide', $application), ['decision' => 'approve']);

        // Five stages still to go: nobody is tied up yet.
        $this->assertSame('cgs_compile', $application->fresh()->current_stage);
        $this->assertNull(Examiner::find($panel['internal_main_id'])->assigned_until);

        $this->advanceTo($application, 'done');

        $this->assertSame(Application::STATUS_APPROVED, $application->fresh()->status);

        foreach ($panel as $id) {
            $this->assertNotNull(
                Examiner::find($id)->assigned_until,
                'Every seat on the panel is tied up once the chain finishes.'
            );
        }
    }

    public function test_the_dean_sends_a_list_back_to_the_department_instead_of_rejecting_it(): void
    {
        [$supervisor, $student, $ae] = $this->cast();
        $panel = $this->panel();

        $this->actingAs($supervisor)->post(route('examiner-nomination.store'), [
            'student_id' => $student->id,
            'thesis_title' => 'A Study of Something',
        ] + $panel);

        $application = Application::sole();
        $this->actingAs($ae)->post(route('examiner-nomination.decide', $application), ['decision' => 'approve']);
        $this->advanceTo($application, 'dean');

        $this->assertSame('dean', $application->fresh()->current_stage);

        $this->actingAs($this->actorFor('dean'))
            ->post(route('examiner-nomination.return', $application), [
                'remarks' => 'The internal examiner supervised this candidate in 2024.',
            ]);

        $application->refresh();

        // Back with the department, still alive, and nobody tied up.
        $this->assertSame('academic_exec', $application->current_stage);
        $this->assertSame(Application::STATUS_PENDING, $application->status);
        $this->assertNull(Examiner::find($panel['internal_main_id'])->assigned_until);

        $this->assertDatabaseHas('approval_history', [
            'application_id' => $application->id,
            'stage_key' => 'dean',
            'decision' => 'returned',
        ]);
    }

    public function test_a_return_needs_a_reason(): void
    {
        [$supervisor, $student, $ae] = $this->cast();

        $this->actingAs($supervisor)->post(route('examiner-nomination.store'), [
            'student_id' => $student->id,
            'thesis_title' => 'A Study of Something',
        ] + $this->panel());

        $application = Application::sole();
        $this->actingAs($ae)->post(route('examiner-nomination.decide', $application), ['decision' => 'approve']);
        $this->advanceTo($application, 'senior_director');

        $this->actingAs($this->actorFor('senior_director'))
            ->post(route('examiner-nomination.return', $application), ['remarks' => ''])
            ->assertSessionHasErrors('remarks');

        $this->assertSame('senior_director', $application->fresh()->current_stage);
    }

    public function test_a_stage_that_is_not_allowed_to_send_back_cannot(): void
    {
        [$supervisor, $student, $ae] = $this->cast();

        $this->actingAs($supervisor)->post(route('examiner-nomination.store'), [
            'student_id' => $student->id,
            'thesis_title' => 'A Study of Something',
        ] + $this->panel());

        $application = Application::sole();

        // The Academic Executive owns the FIRST stage: there is nothing
        // behind them to send it back to.
        $this->actingAs($ae)
            ->post(route('examiner-nomination.return', $application), ['remarks' => 'Not for me to bounce.'])
            ->assertSessionHas('error');

        $this->assertSame('academic_exec', $application->fresh()->current_stage);
    }

    public function test_a_rejected_nomination_leaves_the_pool_untouched(): void
    {
        [$supervisor, $student, $ae] = $this->cast();
        $panel = $this->panel();

        $this->actingAs($supervisor)->post(route('examiner-nomination.store'), [
            'student_id' => $student->id,
            'thesis_title' => 'A Study of Something',
        ] + $panel);

        $this->actingAs($ae)->post(
            route('examiner-nomination.decide', Application::sole()),
            ['decision' => 'reject', 'remarks' => 'Conflict of interest.']
        );

        $this->assertNull(Examiner::find($panel['internal_main_id'])->assigned_until);
    }

    public function test_the_report_shows_an_academic_executive_only_their_own_department(): void
    {
        [$supervisor, $student, $ae] = $this->cast();

        $this->actingAs($supervisor)->post(route('examiner-nomination.store'), [
            'student_id' => $student->id,
            'thesis_title' => 'A Study of Something',
        ] + $this->panel());

        // Another department's candidate, filed by their own supervisor.
        $otherSupervisor = $this->supervisor('sup2@test.my');
        $other = $this->student('22009999', 'other@test.my', [
            'name' => 'Someone Elsewhere',
            'supervisor_id' => $otherSupervisor->id,
            'department' => 'Petroleum Engineering',
        ]);

        $application = Application::create([
            'student_id' => $other->id,
            'submitted_by_id' => $otherSupervisor->id,
            'module_type' => 'examiner_nomination',
            'status' => Application::STATUS_PENDING,
            'current_stage' => 'academic_exec',
            'submitted_at' => now(),
        ]);

        ExaminerNomination::create([
            'application_id' => $application->id,
            'internal_main_id' => $this->internal('Dr. Petroleum', 'pet@test.my', 'Petroleum Engineering')->id,
            'thesis_title' => 'Something Else Entirely',
        ]);

        $this->actingAs($ae)
            ->get(route('examiner-nomination.report'))
            ->assertOk()
            ->assertSee($student->name)
            ->assertDontSee('Someone Elsewhere');

        // CGS compiles every department, which is the whole point of the stage.
        $this->actingAs($this->actorFor('cgs_compile'))
            ->get(route('examiner-nomination.report'))
            ->assertOk()
            ->assertSee($student->name)
            ->assertSee('Someone Elsewhere');
    }

    public function test_a_student_cannot_read_the_compiled_list(): void
    {
        [, $student] = $this->cast();

        $this->actingAs($student)
            ->get(route('examiner-nomination.report'))
            ->assertForbidden();
    }
}
