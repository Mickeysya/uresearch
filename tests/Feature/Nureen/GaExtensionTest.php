<?php

namespace Tests\Feature\Nureen;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApprovalHistory;
use App\Modules\Nureen\Models\GaExtensionDetail;
use App\Modules\Nureen\Notifications\GaExtensionFinalDecisionCc;
use App\Modules\Nureen\Notifications\GaExtensionFinalDecisionInformational;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * GA Extension — `docs/scope/nureen.md` Module 2.
 *
 * The scope calls document completeness "the point of the module": an
 * extension may not reach CGS without its supporting paperwork and a real
 * justification. Four distinct types are each checked separately (the GA
 * extension form, an identity document, the supervisor's letter, and an
 * acceptance letter or published paper — either satisfies that last one)
 * rather than one undifferentiated upload, per
 * config('nureen.ga_extension.required_documents').
 *
 * The chain itself now has two shapes, chosen per application at submission
 * time and never revisited -- see GaExtensionDetail::final_approver_snapshot
 * and GaExtensionWorkflow::stages():
 *   'cgs'             DEFAULT. Supervisor endorses, Non-Executive CGS
 *                      decides and the application ends there.
 *   'senior_director' The original three-stage chain: Supervisor, then CGS
 *                      Staff as an intermediate review, then the Senior
 *                      Director's final approval.
 * CGS Staff can also send an incomplete application back to the supervisor
 * instead of ending it; see the "returning" tests at the bottom.
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
            'identity_document_documents' => [UploadedFile::fake()->create('ic.pdf', 40, 'application/pdf')],
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

    public function test_a_complete_request_attaches_all_four_document_types_under_their_own_labels(): void
    {
        Storage::fake('local');

        $this->actingAs($this->student())
            ->post(route('ga-extension.store'), $this->payload())
            ->assertRedirect(route('applications.index'));

        $application = Application::where('module_type', 'ga_extension')->sole();

        $this->assertSame(4, $application->documents()->count());
        $this->assertEqualsCanonicalizing(
            ['GA Extension Form', 'IC or Passport/Visa Copy', "Supervisor's Supporting Letter", 'Acceptance Letter or Published Paper'],
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

    public function test_the_default_cgs_chain_needs_only_two_approvals(): void
    {
        Storage::fake('local');
        Notification::fake();

        $supervisor = $this->supervisor();
        $student = $this->student(attributes: ['supervisor_id' => $supervisor->id]);

        $this->actingAs($student)->post(route('ga-extension.store'), $this->payload());

        $application = Application::where('module_type', 'ga_extension')->sole();
        $this->assertSame('cgs', GaExtensionDetail::where('application_id', $application->id)->sole()->final_approver_snapshot);

        // 1. Supervisor endorses.
        $this->actingAs($supervisor)
            ->post(route('ga-extension.decide', $application), ['decision' => 'approve']);

        $application->refresh();
        $this->assertSame(Application::STATUS_PENDING, $application->status);
        $this->assertSame('cgs_verify', $application->current_stage);

        // 2. Non-Executive CGS gives the final decision -- no Senior
        //    Director stage exists on this chain at all.
        $this->actingAs($this->cgs())
            ->post(route('ga-extension.decide', $application), ['decision' => 'approve']);

        $this->assertSame(Application::STATUS_APPROVED, $application->fresh()->status);

        // The supervisor is copied on the final decision, and the Senior
        // Director -- who has no stage in this mode -- is kept informed.
        Notification::assertSentTo($supervisor, GaExtensionFinalDecisionCc::class);
    }

    public function test_the_senior_director_mode_still_needs_all_three_approvals(): void
    {
        Storage::fake('local');
        Notification::fake();

        config(['nureen.ga_extension.final_approver' => 'senior_director']);

        $supervisor = $this->supervisor();
        $student = $this->student(attributes: ['supervisor_id' => $supervisor->id]);
        $director = $this->seniorDirector();

        $this->actingAs($student)->post(route('ga-extension.store'), $this->payload());

        $application = Application::where('module_type', 'ga_extension')->sole();
        $this->assertSame('senior_director', GaExtensionDetail::where('application_id', $application->id)->sole()->final_approver_snapshot);

        $this->actingAs($supervisor)
            ->post(route('ga-extension.decide', $application), ['decision' => 'approve']);

        $application->refresh();
        $this->assertSame(Application::STATUS_PENDING, $application->status);
        $this->assertSame('cgs_verify', $application->current_stage);

        $this->actingAs($this->cgs())
            ->post(route('ga-extension.decide', $application), ['decision' => 'approve']);

        $application->refresh();
        $this->assertSame(Application::STATUS_PENDING, $application->status);
        $this->assertSame('senior_director', $application->current_stage);

        // Only the Senior Director can finish it -- and they are the
        // decider here, not an informational bystander, so they get no
        // separate informational copy.
        $this->actingAs($director)
            ->post(route('ga-extension.decide', $application), ['decision' => 'approve']);

        $this->assertSame(Application::STATUS_APPROVED, $application->fresh()->status);
        Notification::assertSentTo($supervisor, GaExtensionFinalDecisionCc::class);
        Notification::assertNotSentTo($director, GaExtensionFinalDecisionInformational::class);
    }

    public function test_an_in_flight_application_submitted_before_this_change_keeps_its_senior_director_chain(): void
    {
        Storage::fake('local');

        // Simulate a pre-existing row: live config says 'cgs', but the
        // snapshot on the detail row (as a pre-migration application would
        // have been backfilled) says otherwise. stages() must honour the
        // snapshot, not the live config.
        config(['nureen.ga_extension.final_approver' => 'cgs']);

        $supervisor = $this->supervisor();
        $student = $this->student(attributes: ['supervisor_id' => $supervisor->id]);

        $application = Application::create([
            'student_id' => $student->id,
            'submitted_by_id' => $student->id,
            'module_type' => 'ga_extension',
            'status' => Application::STATUS_PENDING,
            'current_stage' => 'cgs_verify',
            'submitted_at' => now(),
        ]);

        GaExtensionDetail::create([
            'application_id' => $application->id,
            'current_end_date' => now()->addMonth(),
            'requested_new_end_date' => now()->addMonths(7),
            'reason_for_extension' => 'Pre-existing application from before the chain split.',
            'final_approver_snapshot' => 'senior_director',
        ]);

        $this->actingAs($this->cgs())
            ->post(route('ga-extension.decide', $application), ['decision' => 'approve']);

        // Still pending, now at the Senior Director -- a third stage the
        // live 'cgs' config does not even declare, proving the snapshot
        // (not the config) governs this specific application's chain.
        $application->refresh();
        $this->assertSame(Application::STATUS_PENDING, $application->status);
        $this->assertSame('senior_director', $application->current_stage);

        $this->actingAs($this->seniorDirector())
            ->post(route('ga-extension.decide', $application), ['decision' => 'approve']);

        $this->assertSame(Application::STATUS_APPROVED, $application->fresh()->status);
    }

    public function test_the_senior_director_is_informed_but_cannot_act_in_cgs_mode(): void
    {
        Storage::fake('local');
        Notification::fake();

        $supervisor = $this->supervisor();
        $student = $this->student(attributes: ['supervisor_id' => $supervisor->id]);
        $director = $this->seniorDirector();

        $this->actingAs($student)->post(route('ga-extension.store'), $this->payload());
        $application = Application::where('module_type', 'ga_extension')->sole();

        $this->actingAs($supervisor)
            ->post(route('ga-extension.decide', $application), ['decision' => 'approve']);

        // The Director holds no stage on this chain, so acting on it fails.
        $this->actingAs($director)
            ->post(route('ga-extension.decide', $application), ['decision' => 'approve'])
            ->assertRedirect();

        $this->assertSame('cgs_verify', $application->fresh()->current_stage);

        $this->actingAs($this->cgs())
            ->post(route('ga-extension.decide', $application), ['decision' => 'approve']);

        $this->assertSame(Application::STATUS_APPROVED, $application->fresh()->status);
        Notification::assertSentTo($director, GaExtensionFinalDecisionInformational::class);
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
            ->assertSee('IC or Passport/Visa Copy')
            ->assertSee("Supervisor's Supporting Letter")
            ->assertSee('Acceptance Letter or Published Paper')
            ->assertDontSee('— missing');
    }
}
