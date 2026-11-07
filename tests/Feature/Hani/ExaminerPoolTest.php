<?php

namespace Tests\Feature\Hani;

use Tests\Feature\Hani\Concerns\KeepsJobsOffTheDevQueue;
use App\Modules\Core\Models\Application;
use App\Modules\Hani\Models\Examiner;
use App\Modules\Hani\Models\ExaminerImportBatch;
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
    use KeepsJobsOffTheDevQueue;
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

        $this->actingAs($this->seniorExec())
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

        $this->actingAs($this->seniorExec())
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

        $this->actingAs($this->seniorExec())
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

        $cgs = $this->seniorExec();

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
            'external_main_id' => $examiner->id,
            'thesis_title' => 'A Study of Something',
        ]);

        $this->actingAs($this->seniorExec())
            ->get(route('examiner-admin.index', ['type' => Examiner::TYPE_EXTERNAL]))
            ->assertOk()
            ->assertSee($student->name.'_'.$student->matric_no);
    }

    public function test_an_internal_examiner_cannot_be_given_an_external_record(): void
    {
        $this->actingAs($this->seniorExec())
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
        $this->actingAs($this->seniorExec())
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
        $this->actingAs($this->seniorExec())
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
        $html = $this->actingAs($this->seniorExec())
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
        $html = $this->actingAs($this->seniorExec())
            ->get(route('examiner-admin.create'))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/id="external-fields"[^>]*hidden disabled/s', $html);
    }

    /** @return array<string, mixed> A valid new examiner. */
    protected function newExaminer(): array
    {
        return [
            'name' => 'Dr Added By Non Exec',
            'email' => 'added@utp.edu.my',
            'department' => 'Computing',
            'type' => Examiner::TYPE_INTERNAL,
        ];
    }

    public function test_the_non_executive_can_add_an_examiner_with_the_same_wizard(): void
    {
        $nonExec = $this->cgs();

        $html = $this->actingAs($nonExec)->get(route('examiner-admin.create'))->assertOk()->getContent();
        $this->assertStringContainsString('data-stepper', $html);
        $this->assertSame(2, substr_count($html, 'class="fstep"'));

        $this->actingAs($nonExec)->post(route('examiner-admin.store'), $this->newExaminer())
            ->assertRedirect(route('examiner-admin.index'))
            ->assertSessionHasNoErrors();

        $this->assertSame('added@utp.edu.my', Examiner::sole()->email);
        // Same rules as the Senior Executive's route.
        $this->actingAs($nonExec)->post(route('examiner-admin.store'), ['name' => 'No Department'])
            ->assertSessionHasErrors('department');
    }

    public function test_the_non_executive_can_mark_unavailable_with_a_reason_and_back_to_active(): void
    {
        $examiner = $this->internal();
        $nonExec = $this->cgs();

        $this->actingAs($nonExec)->post(route('examiner-admin.toggle-active', $examiner), [])
            ->assertSessionHasErrors('unavailable_reason');
        $this->assertTrue($examiner->fresh()->is_active);

        $this->actingAs($nonExec)->post(route('examiner-admin.toggle-active', $examiner), ['unavailable_reason' => 'Retired'])
            ->assertSessionHasNoErrors();
        $this->assertFalse($examiner->fresh()->is_active);
        $this->assertSame('Retired', $examiner->fresh()->unavailable_reason);

        $this->actingAs($nonExec)->post(route('examiner-admin.toggle-active', $examiner))
            ->assertSessionHasNoErrors();
        $this->assertTrue($examiner->fresh()->is_active);
        $this->assertNull($examiner->fresh()->unavailable_reason);
    }

    public function test_the_non_executive_can_update_an_email(): void
    {
        $examiner = $this->internal();

        $this->actingAs($this->cgs())->put(route('examiner-admin.email.update', $examiner), [
            'email' => 'new.address@utp.edu.my',
            'reason' => 'University directory',
        ])->assertSessionHasNoErrors();

        $this->assertSame('new.address@utp.edu.my', $examiner->fresh()->email);
    }

    public function test_the_non_executive_cannot_import_full_edit_or_dismiss_duplicates(): void
    {
        $examiner = $this->internal();
        $other = $this->external();
        $nonExec = $this->cgs();

        $this->actingAs($nonExec)->get(route('examiner-admin.edit', $examiner))->assertForbidden();
        $this->actingAs($nonExec)->put(route('examiner-admin.update', $examiner), $this->newExaminer())->assertForbidden();
        $this->actingAs($nonExec)->get(route('examiner-import.create'))->assertForbidden();
        $this->actingAs($nonExec)->post(route('examiner-import.store'))->assertForbidden();
        $this->actingAs($nonExec)->get(route('examiner-import.review'))->assertForbidden();
        $this->actingAs($nonExec)->post(route('examiner-admin.dismiss-duplicate'), [
            'examiner_id' => $examiner->id,
            'other_id' => $other->id,
        ])->assertForbidden();
    }

    public function test_the_pool_page_offers_each_cgs_desk_its_own_actions(): void
    {
        $examiner = $this->internal();
        $unavailable = $this->external();
        $unavailable->update(['is_active' => false, 'unavailable_reason' => 'Retired']);
        $edit = route('examiner-admin.edit', $examiner);

        $this->actingAs($this->cgs())->get(route('examiner-admin.index'))->assertOk()
            ->assertSee('Add an examiner')
            ->assertSee('data-unavailable="'.$examiner->id.'"', false)
            ->assertSee('id="unavailable-modal"', false)
            ->assertSee('Reactivate')
            ->assertSee('Update email')
            ->assertDontSee('Import from Excel')
            ->assertDontSee($edit, false);

        $this->actingAs($this->seniorExec())->get(route('examiner-admin.index'))->assertOk()
            ->assertSee('Add an examiner')
            ->assertSee('Import from Excel')
            ->assertSee($edit, false)
            ->assertSee('data-unavailable="'.$examiner->id.'"', false);
    }

    /**
     * "Email not working" (examiner-admin.report-email) is not here: it flags
     * an address for CGS rather than changing the pool, and the AE keeps it.
     */
    public function test_the_academic_executive_and_supervisor_cannot_write_to_the_pool(): void
    {
        $examiner = $this->internal();
        $other = $this->external();
        $batch = ExaminerImportBatch::create([
            'user_id' => $this->seniorExec()->id,
            'layout' => 'template',
            'filename' => 'pool.xlsx',
            'rows' => [],
            'messages' => [],
            'summary' => [],
        ]);

        foreach ([$this->academicExec(), $this->supervisor()] as $user) {
            $this->actingAs($user)->get(route('examiner-admin.index'))->assertForbidden();
            $this->actingAs($user)->get(route('examiner-admin.create'))->assertForbidden();
            $this->actingAs($user)->post(route('examiner-admin.store'), $this->newExaminer())->assertForbidden();
            $this->actingAs($user)->post(route('examiner-admin.toggle-active', $examiner), ['unavailable_reason' => 'x'])->assertForbidden();
            $this->actingAs($user)->get(route('examiner-admin.edit', $examiner))->assertForbidden();
            $this->actingAs($user)->put(route('examiner-admin.update', $examiner), $this->newExaminer())->assertForbidden();
            $this->actingAs($user)->put(route('examiner-admin.email.update', $examiner), ['email' => 'x@example.com', 'reason' => 'x'])->assertForbidden();
            $this->actingAs($user)->post(route('examiner-admin.email.confirm', $examiner))->assertForbidden();
            $this->actingAs($user)->post(route('examiner-admin.dismiss-duplicate'), ['examiner_id' => $examiner->id, 'other_id' => $other->id])->assertForbidden();
            $this->actingAs($user)->post(route('examiner-import.store'))->assertForbidden();
            $this->actingAs($user)->post(route('examiner-import.confirm', $batch))->assertForbidden();
        }

        $this->assertSame(2, Examiner::count());
        $this->assertTrue($examiner->fresh()->is_active);
        $this->assertSame('internal@utp.edu.my', $examiner->fresh()->email);
    }

    public function test_an_examiner_keeps_expertise_keywords_and_publications(): void
    {
        $examiner = $this->external();
        $examiner->forceFill(['updated_at' => now()->subDay()])->saveQuietly();
        $before = $examiner->fresh()->updated_at;

        $examiner->expertiseKeywords()->create(['keyword' => 'anomaly detection']);
        $examiner->publications()->create([
            'title' => 'Time-series anomaly detection for sensor streams',
            'year' => 2024,
            'coauthors' => 'Priya Ramasamy; Nurul Aina Aziz',
        ]);

        $examiner->refresh();
        $this->assertSame(['anomaly detection'], $examiner->expertiseKeywords->pluck('keyword')->all());
        $this->assertSame(['Priya Ramasamy', 'Nurul Aina Aziz'], $examiner->publications->first()->coauthorList());

        // Touching the examiner is what invalidates the matcher's cached statistics.
        $this->assertTrue($examiner->updated_at->gt($before));
    }

    public function test_an_examiner_may_be_in_the_pool_without_an_email(): void
    {
        $examiner = Examiner::create([
            'name' => 'Dr No Email',
            'department' => 'Computing',
            'type' => Examiner::TYPE_INTERNAL,
            'is_active' => true,
        ]);

        $this->assertTrue($examiner->needsEmail());
        $this->assertFalse($this->internal()->needsEmail());
    }

    public function test_utp_links_are_part_of_the_external_record_only(): void
    {
        $this->actingAs($this->seniorExec())
            ->post(route('examiner-admin.store'), [
                'name' => 'Dr Internal',
                'email' => 'dr.internal2@utp.edu.my',
                'department' => 'Computing',
                'type' => Examiner::TYPE_INTERNAL,
                'current_utp_appointment' => 'Adjunct Professor',
                'former_utp_until' => '2024-01-01',
            ])
            ->assertSessionHasNoErrors();

        $examiner = Examiner::sole();
        $this->assertNull($examiner->current_utp_appointment);
        $this->assertNull($examiner->former_utp_until);
    }
}
