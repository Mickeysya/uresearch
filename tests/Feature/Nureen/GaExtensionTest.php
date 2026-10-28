<?php

namespace Tests\Feature\Nureen;

use App\Modules\Core\Models\Application;
use App\Modules\Nureen\Models\GaExtensionDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * GA Extension — `docs/scope/nureen.md` Module 2.
 *
 * The scope calls document completeness "the point of the module": an
 * extension may not reach CGS without its supporting paperwork and a real
 * justification. Three distinct types are each checked separately now (the
 * GA extension form, the supervisor's letter, and an acceptance letter or
 * published paper — either satisfies that third one) rather than one
 * undifferentiated upload, per config('nureen.ga_extension.required_documents').
 *
 * The chain itself is three fixed stages — Supervisor, CGS Staff, Senior
 * Director — and the middle one is what the legacy app got wrong, so the walk
 * below asserts the position after every decision rather than only the end.
 * CGS Staff can now also send an incomplete application back to the
 * supervisor instead of ending it; see the "returning" tests at the bottom.
 */
class GaExtensionTest extends TestCase
{
    use MakesUsers;
    use RefreshDatabase;

    /** @return array<string, mixed> */
    protected function payload(array $overrides = []): array
    {
        return $overrides + [
            'current_end_date' => now()->addMonth()->toDateString(),
            'requested_new_end_date' => now()->addMonths(7)->toDateString(),
            'reason_for_extension' => 'Fieldwork at the Gurun plant slipped two semesters '
                .'and the remaining analysis needs the assistantship to continue.',
            'ga_extension_form_documents' => [UploadedFile::fake()->create('ga-form.pdf', 50, 'application/pdf')],
            'supervisor_letter_documents' => [UploadedFile::fake()->create('supervisor-letter.pdf', 90, 'application/pdf')],
            'acceptance_or_paper_documents' => [UploadedFile::fake()->create('acceptance.pdf', 60, 'application/pdf')],
        ];
    }

    /* -----------------------------------------------------------------
     | Document completeness — one required type per config entry
     |------------------------------------------------------------------*/

    public function test_each_required_document_type_is_mandatory_on_its_own(): void
    {
        Storage::fake('local');

        $this->actingAs($this->student());

        foreach (array_keys(config('nureen.ga_extension.required_documents')) as $field) {
            $this->post(route('ga-extension.store'), $this->payload([$field => null]))
                ->assertSessionHasErrors($field);
        }

        $this->assertSame(0, Application::where('module_type', 'ga_extension')->count());
    }

    public function test_more_than_five_files_for_one_type_is_rejected(): void
    {
        Storage::fake('local');

        $files = array_map(
            fn ($i) => UploadedFile::fake()->create("doc-{$i}.pdf", 50, 'application/pdf'),
            range(1, 6),
        );

        $this->actingAs($this->student())
            ->post(route('ga-extension.store'), $this->payload(['ga_extension_form_documents' => $files]))
            ->assertSessionHasErrors('ga_extension_form_documents');

        $this->assertSame(0, Application::where('module_type', 'ga_extension')->count());
    }

    public function test_a_file_over_5mb_is_rejected(): void
    {
        Storage::fake('local');

        $this->actingAs($this->student())
            ->post(route('ga-extension.store'), $this->payload([
                'supervisor_letter_documents' => [UploadedFile::fake()->create('big.pdf', 6000, 'application/pdf')],
            ]))
            ->assertSessionHasErrors('supervisor_letter_documents.0');

        $this->assertSame(0, Application::where('module_type', 'ga_extension')->count());
    }

    public function test_files_totalling_over_20mb_for_one_type_are_rejected(): void
    {
        Storage::fake('local');

        // Four files at 4.5 MB each sit under the 5 MB per-file cap but add
        // up to 18 MB; a fifth pushes the combined total past 20 MB.
        $files = array_map(
            fn ($i) => UploadedFile::fake()->create("doc-{$i}.pdf", 4500, 'application/pdf'),
            range(1, 5),
        );

        $this->actingAs($this->student())
            ->post(route('ga-extension.store'), $this->payload(['acceptance_or_paper_documents' => $files]))
            ->assertSessionHasErrors('acceptance_or_paper_documents');

        $this->assertSame(0, Application::where('module_type', 'ga_extension')->count());
    }

    public function test_a_disallowed_file_type_is_rejected(): void
    {
        Storage::fake('local');

        $this->actingAs($this->student())
            ->post(route('ga-extension.store'), $this->payload([
                'ga_extension_form_documents' => [UploadedFile::fake()->create('macro.xlsx', 90, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')],
            ]))
            ->assertSessionHasErrors('ga_extension_form_documents.0');

        $this->assertSame(0, Application::where('module_type', 'ga_extension')->count());
    }

    public function test_a_complete_request_attaches_all_three_document_types_under_their_own_labels(): void
    {
        Storage::fake('local');

        $this->actingAs($this->student())
            ->post(route('ga-extension.store'), $this->payload())
            ->assertRedirect(route('applications.index'));

        $application = Application::where('module_type', 'ga_extension')->sole();

        $this->assertSame(3, $application->documents()->count());
        $this->assertEqualsCanonicalizing(
            ['GA Extension Form', "Supervisor's Supporting Letter", 'Acceptance Letter or Published Paper'],
            $application->documents()->pluck('doc_type')->all(),
        );

        $document = $application->documents()->where('doc_type', 'GA Extension Form')->sole();
        $this->assertSame('ga-form.pdf', $document->original_name);
        $this->assertStringStartsWith("applications/{$application->id}/", $document->path);
        $this->assertStringNotContainsString('ga-form', $document->path);
    }

    /* -----------------------------------------------------------------
     | The rest of the form
     |------------------------------------------------------------------*/

    public function test_the_new_end_date_must_be_after_the_current_one(): void
    {
        Storage::fake('local');

        $this->actingAs($this->student())
            ->post(route('ga-extension.store'), $this->payload([
                'current_end_date' => now()->addMonths(7)->toDateString(),
                'requested_new_end_date' => now()->addMonth()->toDateString(),
            ]))
            ->assertSessionHasErrors('requested_new_end_date');

        $this->assertSame(0, Application::where('module_type', 'ga_extension')->count());
    }

    public function test_a_one_line_reason_is_not_a_justification(): void
    {
        Storage::fake('local');

        $this->actingAs($this->student())
            ->post(route('ga-extension.store'), $this->payload(['reason_for_extension' => 'Need more time.']))
            ->assertSessionHasErrors('reason_for_extension');

        $this->assertSame(0, Application::where('module_type', 'ga_extension')->count());
    }

    public function test_a_complete_request_is_submitted_and_pending_at_the_supervisor(): void
    {
        Storage::fake('local');

        $this->actingAs($this->student())
            ->post(route('ga-extension.store'), $this->payload())
            ->assertRedirect(route('applications.index'));

        $application = Application::where('module_type', 'ga_extension')->sole();

        $this->assertSame(Application::STATUS_PENDING, $application->status);
        $this->assertSame('supervisor', $application->current_stage);

        $detail = GaExtensionDetail::where('application_id', $application->id)->sole();
        $this->assertTrue($detail->requested_new_end_date->greaterThan($detail->current_end_date));
    }

    public function test_the_form_shows_the_logged_in_students_own_profile_details(): void
    {
        $student = $this->student(attributes: ['programme' => 'PhD in Chemical Engineering']);
        $supervisor = $this->supervisor();
        $student->update(['supervisor_id' => $supervisor->id]);

        $this->actingAs($student)
            ->get(route('ga-extension.create'))
            ->assertOk()
            ->assertSee($student->name)
            ->assertSee($student->matric_no)
            ->assertSee('PhD in Chemical Engineering')
            ->assertSee($supervisor->name);
    }

    public function test_the_chain_needs_all_three_approvals(): void
    {
        Storage::fake('local');

        $supervisor = $this->supervisor();
        $student = $this->student(attributes: ['supervisor_id' => $supervisor->id]);

        $this->actingAs($student)->post(route('ga-extension.store'), $this->payload());

        $application = Application::where('module_type', 'ga_extension')->sole();

        // 1. Supervisor endorses -- this is the step the legacy app treated as
        //    the end of the chain.
        $this->actingAs($supervisor)
            ->post(route('ga-extension.decide', $application), ['decision' => 'approve']);

        $application->refresh();
        $this->assertSame(Application::STATUS_PENDING, $application->status);
        $this->assertSame('cgs_verify', $application->current_stage);

        // 2. CGS verifies.
        $this->actingAs($this->cgs())
            ->post(route('ga-extension.decide', $application), ['decision' => 'approve']);

        $application->refresh();
        $this->assertSame(Application::STATUS_PENDING, $application->status);
        $this->assertSame('senior_director', $application->current_stage);

        // 3. Only the Senior Director can finish it.
        $this->actingAs($this->seniorDirector())
            ->post(route('ga-extension.decide', $application), ['decision' => 'approve']);

        $this->assertSame(Application::STATUS_APPROVED, $application->fresh()->status);
    }

    public function test_a_rejection_anywhere_in_the_chain_closes_it(): void
    {
        Storage::fake('local');

        $supervisor = $this->supervisor();
        $student = $this->student(attributes: ['supervisor_id' => $supervisor->id]);

        $this->actingAs($student)->post(route('ga-extension.store'), $this->payload());

        $application = Application::where('module_type', 'ga_extension')->sole();

        $this->actingAs($supervisor)
            ->post(route('ga-extension.decide', $application), [
                'decision' => 'reject',
                'remarks' => 'The department cannot fund a further seven months.',
            ]);

        $application->refresh();
        $this->assertSame(Application::STATUS_REJECTED, $application->status);
        // The stage it died on is held, so the trail says who stopped it.
        $this->assertSame('supervisor', $application->current_stage);
    }

    /* -----------------------------------------------------------------
     | Sending an incomplete application back, instead of rejecting it
     |------------------------------------------------------------------*/

    public function test_cgs_staff_can_return_an_application_to_the_supervisor_with_a_reason(): void
    {
        Storage::fake('local');

        $supervisor = $this->supervisor();
        $student = $this->student(attributes: ['supervisor_id' => $supervisor->id]);

        $this->actingAs($student)->post(route('ga-extension.store'), $this->payload());
        $application = Application::where('module_type', 'ga_extension')->sole();

        $this->actingAs($supervisor)
            ->post(route('ga-extension.decide', $application), ['decision' => 'approve']);

        $application->refresh();
        $this->assertSame('cgs_verify', $application->current_stage);

        $this->actingAs($this->cgs())
            ->post(route('ga-extension.return', $application), [
                'remarks' => 'The supervisor letter is unsigned — please have it re-issued.',
            ])
            ->assertRedirect();

        $application->refresh();
        $this->assertSame(Application::STATUS_PENDING, $application->status);
        // Sent back, not rejected: the application is still open, and back
        // at the stage that has to redo its part.
        $this->assertSame('supervisor', $application->current_stage);
    }

    public function test_returning_an_application_requires_a_reason(): void
    {
        Storage::fake('local');

        $supervisor = $this->supervisor();
        $student = $this->student(attributes: ['supervisor_id' => $supervisor->id]);

        $this->actingAs($student)->post(route('ga-extension.store'), $this->payload());
        $application = Application::where('module_type', 'ga_extension')->sole();

        $this->actingAs($supervisor)
            ->post(route('ga-extension.decide', $application), ['decision' => 'approve']);

        $this->actingAs($this->cgs())
            ->post(route('ga-extension.return', $application->fresh()), ['remarks' => ''])
            ->assertSessionHasErrors('remarks');
    }

    public function test_a_role_not_holding_the_applications_current_stage_cannot_return_it(): void
    {
        Storage::fake('local');

        $supervisor = $this->supervisor();
        $student = $this->student(attributes: ['supervisor_id' => $supervisor->id]);

        $this->actingAs($student)->post(route('ga-extension.store'), $this->payload());
        $application = Application::where('module_type', 'ga_extension')->sole();

        // Still sitting at the supervisor's own stage -- CGS Staff holds
        // cgs_verify, not supervisor, so the engine refuses it regardless of
        // what the route itself allows through.
        $this->actingAs($this->cgs())
            ->post(route('ga-extension.return', $application), ['remarks' => 'Needs more detail.'])
            ->assertRedirect();

        $this->assertSame('supervisor', $application->fresh()->current_stage);
        $this->assertNotNull(session('error'));
    }

    /* -----------------------------------------------------------------
     | The System checks panel approvers see
     |------------------------------------------------------------------*/

    public function test_the_system_checks_panel_shows_every_required_type_as_present(): void
    {
        Storage::fake('local');

        $supervisor = $this->supervisor();
        $student = $this->student(attributes: ['supervisor_id' => $supervisor->id]);

        $this->actingAs($student)->post(route('ga-extension.store'), $this->payload());

        $this->actingAs($supervisor)
            ->get(route('ga-extension.queue'))
            ->assertOk()
            ->assertSee('System checks')
            ->assertSee('GA Extension Form')
            ->assertSee("Supervisor's Supporting Letter")
            ->assertSee('Acceptance Letter or Published Paper')
            ->assertDontSee('— missing');
    }
}
