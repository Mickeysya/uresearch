<?php

namespace Tests\Feature\Hani;

use App\Modules\Core\Models\Application;
use App\Modules\Hani\Models\Examiner;
use App\Modules\Hani\Models\ExaminerNomination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * The internal/external split on the examiner pool — `docs/scope/hani.md`.
 *
 * CGS keeps two different records, not one list with a flag: the external
 * sheet carries the faculty approval, institution, expertise and supervision
 * history that justify appointing someone from outside UTP, and the internal
 * sheet has no equivalent of any of it. These cover the three places that
 * distinction has to hold — which columns each list shows, which fields are
 * accepted on the way in, and which examiners each list counts.
 */
class ExaminerPoolTest extends TestCase
{
    use MakesUsers;
    use RefreshDatabase;

    protected function internal(string $email = 'internal@utp.edu.my'): Examiner
    {
        return Examiner::create([
            'name' => 'AP Dr Low Tang Jung',
            'email' => $email,
            'department' => 'Computer & Information Sciences',
            'faculty' => 'FSMC',
            'type' => Examiner::TYPE_INTERNAL,
            'is_active' => true,
        ]);
    }

    protected function external(string $email = 'external@uthm.edu.my'): Examiner
    {
        return Examiner::create([
            'name' => 'AP Dr Hatijah Binti Basri',
            'email' => $email,
            'department' => 'Chemical Engineering',
            'type' => Examiner::TYPE_EXTERNAL,
            'is_active' => true,
            'institution' => 'Universiti Tun Hussein Onn Malaysia (UTHM)',
            'sector' => 'technical',
            'faculty_approval' => '2.2023',
            'utp_cluster' => 'Separation Technology',
            'expertise' => '1. Applied Chemical Sciences',
            'years_experience' => 11,
            'msc_graduated' => 5,
            'phd_graduated' => 5,
        ]);
    }

    public function test_external_list_shows_the_columns_the_internal_list_does_not(): void
    {
        $this->internal();
        $this->external();

        $this->actingAs($this->cgs())
            ->get(route('examiner-admin.index', ['type' => Examiner::TYPE_EXTERNAL]))
            ->assertOk()
            ->assertSee('Faculty Approval')
            ->assertSee('University / Industry')
            ->assertSee('UTP Acad Cluster')
            ->assertSee('Area of Expertise')
            ->assertSee('Universiti Tun Hussein Onn Malaysia (UTHM)')
            ->assertSee('Experience: 11 years')
            // and only the external examiner is on it
            ->assertDontSee('AP Dr Low Tang Jung');
    }

    public function test_internal_list_hides_the_external_record_entirely(): void
    {
        $this->internal();
        $this->external();

        $this->actingAs($this->cgs())
            ->get(route('examiner-admin.index', ['type' => Examiner::TYPE_INTERNAL]))
            ->assertOk()
            ->assertSee('Approved Internal Examiner')
            ->assertSee('AP Dr Low Tang Jung')
            ->assertDontSee('Faculty Approval')
            ->assertDontSee('UTP Acad Cluster')
            ->assertDontSee('Universiti Tun Hussein Onn Malaysia (UTHM)')
            ->assertDontSee('AP Dr Hatijah Binti Basri');
    }

    public function test_combined_list_shows_both_but_only_the_shared_columns(): void
    {
        $this->internal();
        $this->external();

        $this->actingAs($this->cgs())
            ->get(route('examiner-admin.index'))
            ->assertOk()
            ->assertSee('AP Dr Low Tang Jung')
            ->assertSee('AP Dr Hatijah Binti Basri')
            ->assertDontSee('UTP Acad Cluster');
    }

    public function test_state_counts_follow_the_selected_list(): void
    {
        $this->internal();                       // available
        $external = $this->external();           // assigned
        $external->update(['assigned_until' => now()->addDays(30)]);

        $cgs = $this->cgs();

        $internalCounts = $this->actingAs($cgs)
            ->get(route('examiner-admin.index', ['type' => Examiner::TYPE_INTERNAL]))
            ->viewData('stateCounts');

        $this->assertSame(1, $internalCounts[Examiner::STATE_AVAILABLE]);
        $this->assertSame(0, $internalCounts[Examiner::STATE_ASSIGNED]);

        $externalCounts = $this->actingAs($cgs)
            ->get(route('examiner-admin.index', ['type' => Examiner::TYPE_EXTERNAL]))
            ->viewData('stateCounts');

        $this->assertSame(0, $externalCounts[Examiner::STATE_AVAILABLE]);
        $this->assertSame(1, $externalCounts[Examiner::STATE_ASSIGNED]);
    }

    /** The "Student Name" column is derived, so it can never contradict the nominations table. */
    public function test_student_column_comes_from_the_nominations(): void
    {
        $supervisor = $this->supervisor();
        $student = $this->student(attributes: ['supervisor_id' => $supervisor->id]);
        $examiner = $this->external();

        $application = Application::create([
            'student_id' => $student->id,
            'module_type' => 'examiner_nomination',
            'status' => Application::STATUS_DRAFT,
        ]);

        ExaminerNomination::create([
            'application_id' => $application->id,
            'main_examiner_id' => $examiner->id,
            'thesis_title' => 'A Study of Something',
        ]);

        $this->actingAs($this->cgs())
            ->get(route('examiner-admin.index', ['type' => Examiner::TYPE_EXTERNAL]))
            ->assertOk()
            ->assertSee($student->name.'_'.$student->matric_no);
    }

    public function test_an_internal_examiner_cannot_be_given_an_external_record(): void
    {
        $this->actingAs($this->cgs())
            ->post(route('examiner-admin.store'), [
                'name' => 'Dr Internal',
                'email' => 'dr.internal@utp.edu.my',
                'department' => 'Computer & Information Sciences',
                'type' => Examiner::TYPE_INTERNAL,
                // posted anyway, e.g. from a stale form
                'institution' => 'Somewhere Else',
                'utp_cluster' => 'Separation Technology',
                'years_experience' => 11,
            ])
            ->assertSessionHasNoErrors();

        $examiner = Examiner::sole();

        $this->assertNull($examiner->institution);
        $this->assertNull($examiner->utp_cluster);
        $this->assertNull($examiner->years_experience);
    }

    public function test_an_external_examiner_must_say_where_they_are_from(): void
    {
        $this->actingAs($this->cgs())
            ->post(route('examiner-admin.store'), [
                'name' => 'Dr External',
                'email' => 'dr.external@elsewhere.edu.my',
                'department' => 'Chemical Engineering',
                'type' => Examiner::TYPE_EXTERNAL,
            ])
            ->assertSessionHasErrors('institution');

        $this->assertSame(0, Examiner::count());
    }

    public function test_an_external_examiner_keeps_their_record(): void
    {
        $this->actingAs($this->cgs())
            ->post(route('examiner-admin.store'), [
                'name' => 'Dr External',
                'email' => 'dr.external@elsewhere.edu.my',
                'department' => 'Chemical Engineering',
                'type' => Examiner::TYPE_EXTERNAL,
                'institution' => 'Universiti Sains Malaysia (USM)',
                'sector' => 'research',
                'faculty_approval' => '1.2024',
                'years_experience' => 23,
                'msc_graduated' => 8,
                'phd_graduated' => 4,
            ])
            ->assertSessionHasNoErrors();

        $examiner = Examiner::sole();

        $this->assertTrue($examiner->isExternal());
        $this->assertSame('Universiti Sains Malaysia (USM)', $examiner->institution);
        $this->assertSame('Research', $examiner->sectorLabel());
        $this->assertStringContainsString('Experience: 23 years', $examiner->experienceSummary());
        $this->assertStringContainsString('PhD Grad: 4', $examiner->experienceSummary());
    }

    public function test_experience_summary_is_null_when_nothing_is_on_file(): void
    {
        $this->assertNull($this->internal()->experienceSummary());
    }

    /**
     * The external sheet took this form to thirteen fields, past the eight
     * `docs/conventions.md` puts the wizard line at. A render test, because
     * the stepper is client-side: what breaks server-side is the markup
     * contract, and a missing `data-stepper` renders perfectly and produces
     * no wizard at all.
     */
    public function test_the_add_examiner_form_is_a_stepper(): void
    {
        $html = $this->actingAs($this->cgs())
            ->get(route('examiner-admin.create'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-stepper', $html, 'The form is not marked as a stepper.');
        $this->assertSame(2, substr_count($html, 'class="fstep"'), 'Expected two steps: the examiner, then the external record.');
        $this->assertStringContainsString('form[data-stepper]', $html, 'core::partials.form-stepper was not included.');

        // Nothing here files an application, so the generated review must not
        // claim it goes to an approver.
        $this->assertStringNotContainsString('it goes to the first approver', $html);
    }

    /**
     * Internal is the default, and the external block is disabled as well as
     * hidden for it — a disabled fieldset submits none of its controls, so a
     * half-typed external record cannot reach the controller at all, and the
     * wizard's generated review cannot list values that are not going to be
     * saved. `Arr::except` in the controller stays as the real guard.
     */
    public function test_the_external_record_is_disabled_for_an_internal_examiner(): void
    {
        $html = $this->actingAs($this->cgs())
            ->get(route('examiner-admin.create'))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/id="external-fields"[^>]*hidden disabled/s', $html);
    }
}
