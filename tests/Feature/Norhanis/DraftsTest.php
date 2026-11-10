<?php

namespace Tests\Feature\Norhanis;

use App\Modules\Core\Models\Application;
use App\Modules\Norhanis\Models\SavedDraft;
use App\Modules\Norhanis\Models\TravelDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Norhanis\Concerns\FilesPublications;
use Tests\Feature\Norhanis\Concerns\HasApprovedPublication;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * "Save draft" on Travel, Publication and Claims -- see Concerns\SavesDrafts.
 * Drafts are listed on Nureen's My Drafts page alongside hers.
 */
class DraftsTest extends TestCase
{
    use FilesPublications;
    use HasApprovedPublication;
    use MakesUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_a_half_filled_travel_form_saves_as_a_draft_with_its_file(): void
    {
        $student = $this->student();

        $this->actingAs($student)
            ->post(route('travel.draft.save'), [
                'reason_for_travel' => 'Fieldwork in Perak',
                'destination_address' => 'Teluk Intan',
                'supporting_document' => $this->pdf('memo.pdf'),
                'draft_step' => 1,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $draft = Application::sole();
        $this->assertSame(Application::STATUS_DRAFT, $draft->status);
        $this->assertNull($draft->current_stage);
        $this->assertSame('Fieldwork in Perak', SavedDraft::sole()->payload['reason_for_travel']);
        $this->assertSame(1, SavedDraft::sole()->step);
        $this->assertSame(1, $draft->documents()->where('doc_type', 'Supporting Document')->count());

        // Continuing refills the form and shows the saved file.
        $this->get(route('travel.draft.edit', $draft))
            ->assertOk()
            ->assertSee('value="Fieldwork in Perak"', false)
            ->assertSee('memo.pdf')
            ->assertSee('Continuing your draft');

        // And it is on My Drafts, named from what was typed.
        $this->get(route('nureen.drafts'))
            ->assertOk()
            ->assertSee('Fieldwork in Perak, Teluk Intan')
            ->assertSee(route('travel.draft.edit', $draft), false);
    }

    public function test_submitting_a_travel_draft_finishes_the_same_application_without_uploading_again(): void
    {
        $student = $this->student(attributes: ['supervisor_id' => $this->supervisor()->id]);

        $this->actingAs($student)->post(route('travel.draft.save'), ['supporting_document' => $this->pdf('memo.pdf')]);
        $draft = Application::sole();

        $this->post(route('travel.store'), [
            'draft_id' => $draft->id,
            'type_of_request' => 'research_attachment',
            'travel_start_date' => now()->addDays(20)->toDateString(),
            'travel_end_date' => now()->addDays(22)->toDateString(),
            'reason_for_travel' => 'Fieldwork',
            'destination_address' => 'Teluk Intan',
            'is_international' => 0,
            'contact_person_email' => 'host@example.edu.my',
            'is_ga_gra' => 'no',
        ])->assertRedirect(route('applications.index'))->assertSessionHasNoErrors();

        $this->assertSame(1, Application::count(), 'The draft is finished, not copied.');
        $this->assertSame(Application::STATUS_PENDING, $draft->refresh()->status);
        $this->assertSame(0, SavedDraft::count());
        $this->assertSame(1, $draft->documents()->where('doc_type', 'Supporting Document')->count());
        $this->assertNotNull(TravelDetail::where('application_id', $draft->id)->first());

        // Gone from My Drafts once submitted.
        $this->get(route('nureen.drafts'))->assertDontSee(route('travel.draft.edit', $draft), false);
    }

    public function test_a_removed_file_must_be_uploaded_again_to_submit(): void
    {
        $student = $this->student();

        $this->actingAs($student)->post(route('travel.draft.save'), ['supporting_document' => $this->pdf('memo.pdf')]);
        $draft = Application::sole();
        $document = $draft->documents()->sole();

        $this->post(route('travel.store'), [
            'draft_id' => $draft->id,
            'remove_documents' => [$document->id],
        ])->assertSessionHasErrors('supporting_document');

        $this->assertSame(Application::STATUS_DRAFT, $draft->refresh()->status);
    }

    public function test_a_new_file_replaces_the_one_a_draft_already_holds(): void
    {
        $this->actingAs($this->student())->post(route('travel.draft.save'), ['supporting_document' => $this->pdf('first.pdf')]);
        $draft = Application::sole();

        $this->post(route('travel.draft.update', $draft), ['supporting_document' => $this->pdf('second.pdf')])
            ->assertSessionHasNoErrors();

        // Still one file for the document: the newer one.
        $this->assertSame(['second.pdf'], $draft->documents()->where('doc_type', 'Supporting Document')->pluck('original_name')->all());
    }

    public function test_only_the_owner_can_open_or_delete_a_draft(): void
    {
        $this->actingAs($this->student())->post(route('travel.draft.save'), ['reason_for_travel' => 'Mine']);
        $draft = Application::sole();

        $other = $this->student('22009999', 'other@test.my');

        $this->actingAs($other)->get(route('travel.draft.edit', $draft))->assertForbidden();
        $this->actingAs($other)->delete(route('travel.draft.destroy', $draft))->assertForbidden();
        $this->actingAs($other)->post(route('travel.draft.update', $draft), ['reason_for_travel' => 'Theirs'])->assertForbidden();

        $this->assertSame('Mine', SavedDraft::sole()->payload['reason_for_travel']);
    }

    public function test_deleting_a_draft_removes_it_and_its_files(): void
    {
        $student = $this->student();

        $this->actingAs($student)->post(route('claims-student.draft.save'), ['bank_name' => 'Maybank', 'receipt' => $this->pdf('receipt.pdf')]);
        $draft = Application::sole();
        $path = $draft->documents()->sole()->path;

        $this->delete(route('claims-student.draft.destroy', $draft))->assertRedirect(route('nureen.drafts'));

        $this->assertSame(0, Application::count());
        $this->assertSame(0, SavedDraft::count());
        Storage::disk('local')->assertMissing($path);
    }

    public function test_a_claims_draft_is_submitted_with_the_files_it_saved(): void
    {
        $student = $this->student(attributes: ['supervisor_id' => $this->supervisor()->id]);
        $publication = $this->approvedPublication($student);

        $this->actingAs($student)->post(route('claims-student.draft.save'), [
            'publication_application_id' => $publication->id,
            'receipt' => $this->pdf('receipt.pdf'),
            'proof_of_payment' => $this->pdf('payment.pdf'),
            'invoice' => $this->pdf('invoice.pdf'),
        ])->assertSessionHasNoErrors();
        $draft = Application::where('module_type', 'claims_student')->sole();

        $this->get(route('claims-student.draft.edit', $draft))->assertOk()->assertSee('receipt.pdf');

        $this->post(route('claims.store'), [
            'draft_id' => $draft->id,
            'publication_application_id' => $publication->id,
            'total_claims' => 850,
            'bank_name' => 'Maybank',
            'bank_account_no' => '1234567890',
        ])->assertSessionHasNoErrors();

        $this->assertSame(Application::STATUS_PENDING, $draft->refresh()->status);
        $this->assertSame(3, $draft->documents()->count());
    }

    public function test_a_publication_waiting_for_sign_offs_is_not_a_saved_draft(): void
    {
        $student = $this->student();

        // A saved publication draft...
        $this->actingAs($student)->post(route('publication.draft.save'), ['title_of_paper' => 'Graph Networks for Reservoirs']);
        $saved = Application::sole();

        // ...and one submitted and waiting for its co-authors and reviewers:
        // also status 'draft', but nothing to continue.
        $waiting = $this->file($student, ['title_of_paper' => 'Waiting For Sign-offs']);
        $this->assertSame(Application::STATUS_DRAFT, $waiting->refresh()->status);

        $this->actingAs($student)->get(route('nureen.drafts'))
            ->assertSee('Graph Networks for Reservoirs')
            ->assertDontSee('Waiting For Sign-offs');

        // The form's "waiting for sign-offs" notice is the other way round.
        $this->get(route('publication.create'))
            ->assertSee("Publication Application #{$waiting->id} is waiting for sign-offs", false)
            ->assertDontSee("Publication Application #{$saved->id} is waiting for sign-offs", false)
            ->assertSee('You have a saved draft of this form');
    }

    public function test_a_draft_input_does_not_spill_into_the_next_form(): void
    {
        $student = $this->student();
        $this->approvedPublication($student);

        $this->actingAs($student)->post(route('claims-student.draft.save'), ['bank_name' => 'Bank From Draft']);
        $draft = Application::where('module_type', 'claims_student')->sole();

        $this->get(route('claims-student.draft.edit', $draft))->assertSee('Bank From Draft');
        $this->get(route('travel.create'))->assertDontSee('Bank From Draft');
    }
}
