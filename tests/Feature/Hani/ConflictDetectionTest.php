<?php

namespace Tests\Feature\Hani;

use Tests\Feature\Hani\Concerns\KeepsJobsOffTheDevQueue;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Hani\Models\Examiner;
use App\Modules\Hani\Models\ExaminerNomination;
use App\Modules\Hani\Notifications\NominationsNeedCompileReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * Touchpoint 2: the same examiner on two departments' lists. The test
 * TODO.md recorded as missing -- one two-department fixture covering the
 * Conflict Detection screen, the compiled report, the nomination form's
 * warning, the Academic Executive's card and the Senior Executive's
 * notification at cgs_compile. Never auto-rejects.
 */
class ConflictDetectionTest extends TestCase
{
    use KeepsJobsOffTheDevQueue;
    use MakesUsers;
    use RefreshDatabase;

    protected Examiner $shared;

    /** @var array<string, array{supervisor: User, student: User, ae: User, internal: Examiner}> */
    protected array $departments = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->shared = Examiner::create([
            'name' => 'Assoc Prof Dr Melissa Teoh', 'email' => 'melissa.teoh@example.org', 'department' => 'Computing',
            'type' => Examiner::TYPE_EXTERNAL, 'institution' => 'Universiti Malaya (UM)', 'is_active' => true,
        ]);

        foreach (['Computing' => 'c', 'Petroleum Engineering' => 'p'] as $department => $k) {
            $supervisor = $this->supervisor("sup.{$k}@test.my");
            $this->departments[$department] = [
                'supervisor' => $supervisor,
                'student' => $this->student("2200000{$k}", "student.{$k}@test.my", [
                    'name' => "Candidate {$department}", 'supervisor_id' => $supervisor->id, 'department' => $department,
                ]),
                'ae' => $this->user(Role::ACADEMIC_EXEC, ['email' => "ae.{$k}@test.my", 'name' => "AE {$department}", 'department' => $department]),
                'internal' => Examiner::create([
                    'name' => "Dr Internal {$department}", 'email' => "internal.{$k}@example.com", 'department' => $department,
                    'type' => Examiner::TYPE_INTERNAL, 'is_active' => true,
                ]),
            ];
        }
    }

    protected function nominate(string $department): \Illuminate\Testing\TestResponse
    {
        $cast = $this->departments[$department];

        return $this->actingAs($cast['supervisor'])->post(route('examiner-nomination.store'), [
            'student_id' => $cast['student']->id,
            'thesis_title' => "Thesis from {$department}",
            'internal_main_id' => $cast['internal']->id,
            'external_main_id' => $this->shared->id,
        ]);
    }

    protected function applicationFor(string $department): Application
    {
        return Application::where('student_id', $this->departments[$department]['student']->id)->sole();
    }

    public function test_the_nomination_form_warns_when_another_department_already_has_the_examiner(): void
    {
        $this->nominate('Computing')->assertSessionMissing('warning');

        $this->nominate('Petroleum Engineering')
            ->assertSessionHasNoErrors()
            ->assertSessionHas('warning', fn ($w) => str_contains($w, 'Also nominated by Computing'));

        // A warning, not a refusal: both lists stand.
        $this->assertSame(2, ExaminerNomination::count());
    }

    public function test_the_academic_executive_sees_their_own_departments_examiners_and_the_clash(): void
    {
        $this->nominate('Computing');
        $this->nominate('Petroleum Engineering');

        $this->actingAs($this->departments['Computing']['ae'])->get(route('conflict-detection.index'))
            ->assertOk()
            ->assertSee('Assoc Prof Dr Melissa Teoh')
            ->assertSee('Computing, Petroleum Engineering')
            ->assertSee('Conflict')
            ->assertSee('Dr Internal Computing')
            // Another department's own internal examiner is not this AE's business.
            ->assertDontSee('Dr Internal Petroleum Engineering');

        // Their stage card says the same about the seat.
        $this->actingAs($this->departments['Computing']['ae'])->get(route('examiner-nomination.queue'))
            ->assertOk()
            ->assertSee('Check: Also nominated by Petroleum Engineering (application #'.$this->applicationFor('Petroleum Engineering')->id);
    }

    public function test_the_senior_executive_sees_every_department_and_the_report_flags_the_clash(): void
    {
        $this->nominate('Computing');
        $this->nominate('Petroleum Engineering');
        $senior = $this->seniorExec();

        $this->actingAs($senior)->get(route('conflict-detection.index'))
            ->assertOk()
            ->assertSee('Dr Internal Computing')
            ->assertSee('Dr Internal Petroleum Engineering')
            ->assertSee('1 examiner is named by more than one department.');

        // The compiled report computes the same clash set.
        $this->actingAs($senior)->get(route('examiner-nomination.report'))
            ->assertOk()
            ->assertSee('also elsewhere');
    }

    public function test_reaching_compilation_with_a_clash_notifies_the_senior_executive(): void
    {
        Notification::fake();
        $senior = $this->seniorExec();

        $this->nominate('Computing');
        $this->nominate('Petroleum Engineering');

        // Computing's AE clears their list: it reaches cgs_compile with a clash.
        $this->actingAs($this->departments['Computing']['ae'])
            ->post(route('examiner-nomination.decide', $this->applicationFor('Computing')), ['decision' => 'approve'])
            ->assertSessionHas('status');

        $this->assertSame('cgs_compile', $this->applicationFor('Computing')->current_stage);

        Notification::assertSentTo($senior, NominationsNeedCompileReview::class, function ($notification) use ($senior) {
            $mail = $notification->toMail($senior)->render()->toHtml();

            return str_contains($mail, 'Assoc Prof Dr Melissa Teoh: Also nominated by Petroleum Engineering')
                && str_contains($mail, route('conflict-detection.index'));
        });
    }

    public function test_no_clash_means_no_notification(): void
    {
        Notification::fake();
        $senior = $this->seniorExec();

        $this->nominate('Computing');

        $this->actingAs($this->departments['Computing']['ae'])
            ->post(route('examiner-nomination.decide', $this->applicationFor('Computing')), ['decision' => 'approve']);

        Notification::assertNotSentTo($senior, NominationsNeedCompileReview::class);
    }

    public function test_a_rejected_list_no_longer_clashes(): void
    {
        $this->nominate('Computing');
        $this->nominate('Petroleum Engineering');

        $this->actingAs($this->departments['Petroleum Engineering']['ae'])
            ->post(route('examiner-nomination.decide', $this->applicationFor('Petroleum Engineering')), [
                'decision' => 'reject', 'remarks' => 'Choose again.',
            ]);

        $this->actingAs($this->seniorExec())->get(route('conflict-detection.index'))
            ->assertOk()
            ->assertDontSee('named by more than one department');
    }
}
