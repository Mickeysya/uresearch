<?php

namespace Tests\Feature\Hani;

use Tests\Feature\Hani\Concerns\KeepsJobsOffTheDevQueue;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Hani\Models\Examiner;
use App\Modules\Hani\Models\ExaminerNomination;
use App\Modules\Hani\Models\VivaDetail;
use App\Modules\Hani\Support\ConflictOfInterest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/** Support\ConflictOfInterest: the UTP/CGS/026 pre-check, rule by rule. */
class ConflictOfInterestTest extends TestCase
{
    use KeepsJobsOffTheDevQueue;
    use MakesUsers;
    use RefreshDatabase;

    protected User $supervisor;

    protected User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->supervisor = User::create([
            'name' => 'Assoc Prof Dr Nurul Aina binti Aziz', 'email' => 'nurul.aziz@example.com',
            'password' => 'password', 'role' => 'supervisor', 'department' => 'Computing',
        ]);
        $this->student = $this->student(attributes: [
            'name' => 'Aiman Hakim bin Roslan', 'supervisor_id' => $this->supervisor->id, 'department' => 'Computing',
        ]);
    }

    protected function examiner(array $attributes = []): Examiner
    {
        return Examiner::create($attributes + [
            'name' => 'Dr Somebody Independent',
            'email' => 'independent@example.org',
            'department' => 'Computing',
            'type' => Examiner::TYPE_EXTERNAL,
            'institution' => 'Universiti Malaya (UM)',
            'is_active' => true,
        ]);
    }

    protected function rules(Examiner $examiner): array
    {
        return array_column(app(ConflictOfInterest::class)->flagsFor($examiner, $this->student), 'level', 'rule');
    }

    public function test_an_independent_examiner_has_no_flags(): void
    {
        $this->assertSame([], $this->rules($this->examiner()));
    }

    public function test_the_supervisor_is_blocked_by_email_or_by_name(): void
    {
        $byEmail = $this->examiner(['name' => 'Dr N. Aziz', 'email' => 'nurul.aziz@example.com', 'type' => Examiner::TYPE_INTERNAL, 'institution' => null]);
        $byName = $this->examiner(['name' => 'AP Dr Nurul Aina Aziz', 'email' => null, 'type' => Examiner::TYPE_INTERNAL, 'institution' => null]);

        $this->assertSame(['supervisor' => 'block'], $this->rules($byEmail));
        $this->assertSame(['supervisor' => 'block'], $this->rules($byName));
    }

    public function test_the_co_supervisor_is_blocked(): void
    {
        $viva = Application::create([
            'student_id' => $this->student->id, 'module_type' => 'viva_voce',
            'status' => Application::STATUS_APPROVED, 'current_stage' => 'academic_exec',
        ]);
        VivaDetail::create([
            'application_id' => $viva->id, 'programme_level' => 'msc', 'thesis_title' => 'A thesis',
            'expected_softbound_date' => now()->addMonths(4),
            'co_supervisor_name' => 'Dr Co Supervisor', 'co_supervisor_email' => 'co.sup@example.com',
        ]);

        $this->assertSame(['co_supervisor' => 'block'], $this->rules($this->examiner(['email' => 'co.sup@example.com', 'name' => 'Dr C. Supervisor'])));
    }

    public function test_co_authoring_with_the_supervisor_or_the_candidate_is_a_warning_that_names_the_paper(): void
    {
        $priya = $this->examiner(['name' => 'Assoc Prof Dr Priya a/p Ramasamy', 'email' => 'priya.ramasamy@example.org']);
        $priya->publications()->create([
            'title' => 'Time-series anomaly detection for industrial sensor streams',
            'year' => 2024,
            'coauthors' => 'Priya Ramasamy; Nurul Aina Aziz',
        ]);

        $flags = app(ConflictOfInterest::class)->flagsFor($priya, $this->student);

        $this->assertSame(['coauthor' => 'warning'], array_column($flags, 'level', 'rule'));
        $this->assertStringContainsString('"Time-series anomaly detection for industrial sensor streams" (2024)', $flags[0]['message']);
        $this->assertStringContainsString('(the supervisor)', $flags[0]['message']);

        $other = $this->examiner(['name' => 'Dr Other', 'email' => 'other@example.org']);
        $other->publications()->create(['title' => 'Joint paper', 'coauthors' => 'Other; Aiman Hakim Roslan']);
        $this->assertSame(['coauthor' => 'warning'], $this->rules($other));
    }

    public function test_an_external_examiner_currently_appointed_by_utp_is_a_warning(): void
    {
        $this->assertSame(
            ['utp_appointment' => 'warning'],
            $this->rules($this->examiner(['current_utp_appointment' => 'Adjunct Professor']))
        );
    }

    public function test_leaving_utp_less_than_five_years_ago_is_a_warning_and_longer_ago_is_not(): void
    {
        $this->assertSame(['former_utp' => 'warning'], $this->rules($this->examiner(['former_utp_until' => now()->subYears(2)])));
        $this->assertSame([], $this->rules($this->examiner(['email' => 'long.ago@example.org', 'former_utp_until' => now()->subYears(6)])));
    }

    public function test_an_external_examiner_from_utp_is_a_warning(): void
    {
        $this->assertSame(
            ['utp_institution' => 'warning'],
            $this->rules($this->examiner(['institution' => 'Universiti Teknologi PETRONAS (UTP)']))
        );
        $this->assertSame([], $this->rules($this->examiner(['email' => 'utm@example.org', 'institution' => 'Universiti Teknologi Malaysia (UTM)'])));
    }

    public function test_external_only_rules_never_touch_an_internal_examiner(): void
    {
        $internal = $this->examiner(['type' => Examiner::TYPE_INTERNAL, 'institution' => null]);
        $internal->forceFill(['current_utp_appointment' => 'Lecturer'])->save();

        $this->assertSame([], $this->rules($internal));
    }

    public function test_a_supervisor_cannot_nominate_themselves(): void
    {
        $self = $this->examiner(['name' => 'Assoc Prof Dr Nurul Aina binti Aziz', 'email' => 'nurul.aziz@example.com',
            'type' => Examiner::TYPE_INTERNAL, 'institution' => null]);

        $this->actingAs($this->supervisor)->post(route('examiner-nomination.store'), [
            'student_id' => $this->student->id,
            'thesis_title' => 'A thesis',
            'internal_main_id' => $self->id,
            'external_main_id' => $this->examiner()->id,
        ])->assertSessionHasErrors(['internal_main_id' => "Assoc Prof Dr Nurul Aina binti Aziz is Aiman Hakim bin Roslan's supervisor and cannot examine them."]);

        $this->assertSame(0, ExaminerNomination::count());
    }

    public function test_warnings_do_not_stop_a_nomination_and_show_on_the_academic_executives_card_and_the_report(): void
    {
        $internal = $this->examiner(['name' => 'Dr Farah', 'email' => 'farah@example.com', 'type' => Examiner::TYPE_INTERNAL, 'institution' => null]);
        $adjunct = $this->examiner(['name' => 'Prof Noor Azlina', 'email' => 'azlina@example.org', 'current_utp_appointment' => 'Adjunct Professor']);

        $this->actingAs($this->supervisor)->post(route('examiner-nomination.store'), [
            'student_id' => $this->student->id,
            'thesis_title' => 'A thesis',
            'internal_main_id' => $internal->id,
            'external_main_id' => $adjunct->id,
        ])->assertSessionHasNoErrors();

        $ae = $this->academicExec();
        $ae->update(['department' => 'Computing']);

        $this->actingAs($ae)->get(route('examiner-nomination.queue'))
            ->assertOk()
            ->assertSee('Check: Currently appointed by UTP (Adjunct Professor)')
            ->assertSee(ConflictOfInterest::NOTE);

        $this->actingAs($ae)->get(route('examiner-nomination.report'))
            ->assertOk()
            ->assertSee('Check: Currently appointed by UTP (Adjunct Professor)');

        // The candidate never sees any of it.
        $this->actingAs($this->student)->get(route('applications.index'))
            ->assertOk()
            ->assertDontSee('Adjunct')
            ->assertDontSee('Prof Noor Azlina');
    }

    public function test_the_suggestions_carry_flags_and_check_hand_picked_seats(): void
    {
        $adjunct = $this->examiner(['name' => 'Prof Noor Azlina', 'email' => 'azlina@example.org', 'current_utp_appointment' => 'Adjunct Professor']);
        $adjunct->expertiseKeywords()->create(['keyword' => 'credit scoring']);

        $this->actingAs($this->supervisor)
            ->getJson(route('examiner-nomination.suggestions', [
                'student_id' => $this->student->id,
                'title' => 'Explainable credit scoring',
                'check' => [$adjunct->id],
            ]))
            ->assertOk()
            ->assertJsonPath('external.0.flags.0.rule', 'utp_appointment')
            ->assertJsonPath("checks.{$adjunct->id}.0.rule", 'utp_appointment')
            ->assertJsonPath('note', ConflictOfInterest::NOTE);
    }
}
