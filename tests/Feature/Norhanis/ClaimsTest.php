<?php

namespace Tests\Feature\Norhanis;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Norhanis\Models\ClaimsDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Norhanis\Concerns\HasApprovedPublication;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * Student Claims — journal publication claims only (`docs/scope/norhanis.md`).
 *
 * No expense breakdown and no cash advance: total_claims is a direct student
 * input. There is nothing left to tamper with by recomputing a total, so the
 * trust boundary moved to the approver, who checks the figure against the
 * receipt, proof of payment and invoice attached on page 2 before endorsing.
 * See ClaimsController::store().
 */
class ClaimsTest extends TestCase
{
    use HasApprovedPublication;
    use MakesUsers;
    use RefreshDatabase;

    protected function manager(): User
    {
        return $this->user(Role::MANAGER_CGS, ['name' => 'En Rashid', 'email' => 'manager@test.my']);
    }

    /** A valid local-student claim: RM 900, every required attachment present. */
    protected function claim(array $overrides = []): array
    {
        return array_replace([
            'bank_name' => 'Maybank',
            'bank_account_no' => '1234567890',
            'total_claims' => 900,
            'receipt' => UploadedFile::fake()->create('receipt.pdf', 100, 'application/pdf'),
            'proof_of_payment' => UploadedFile::fake()->create('payment.pdf', 100, 'application/pdf'),
            'invoice' => UploadedFile::fake()->create('invoice.pdf', 100, 'application/pdf'),
        ], $overrides);
    }

    /** File a valid claim as $student, linked to a publication of their own. */
    protected function submitAs(User $student, array $overrides = []): Application
    {
        $publication = $this->approvedPublication($student);

        $this->actingAs($student)
            ->post(route('claims.store'), $this->claim($overrides + ['publication_application_id' => $publication->id]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        return Application::where('module_type', 'claims_student')->sole();
    }

    public function test_a_missing_required_attachment_is_refused(): void
    {
        Storage::fake('local');

        $this->actingAs($this->student())
            ->post(route('claims.store'), $this->claim(['receipt' => null]))
            ->assertSessionHasErrors('receipt');

        $this->assertSame(0, ClaimsDetail::count());
    }

    public function test_a_disallowed_file_type_is_refused(): void
    {
        Storage::fake('local');

        $this->actingAs($this->student())
            ->post(route('claims.store'), $this->claim([
                'invoice' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
            ]))
            ->assertSessionHasErrors('invoice');

        $this->assertSame(0, ClaimsDetail::count());
    }

    public function test_a_file_over_5mb_is_refused(): void
    {
        Storage::fake('local');

        $this->actingAs($this->student())
            ->post(route('claims.store'), $this->claim([
                // 5 MB a file (DocumentStore::MULTI_MAX_FILE_KB), checked per
                // file, unchanged by the one-file rule.
                'proof_of_payment' => UploadedFile::fake()->create('payment.pdf', 5121, 'application/pdf'),
            ]))
            ->assertSessionHasErrors('proof_of_payment');

        $this->assertSame(0, ClaimsDetail::count());
    }

    public function test_total_claims_of_zero_or_negative_is_refused(): void
    {
        Storage::fake('local');

        $student = $this->student();

        $this->actingAs($student)
            ->post(route('claims.store'), $this->claim(['total_claims' => 0]))
            ->assertSessionHasErrors('total_claims');

        $this->actingAs($student)
            ->post(route('claims.store'), $this->claim(['total_claims' => -50]))
            ->assertSessionHasErrors('total_claims');

        $this->assertSame(0, ClaimsDetail::count());
    }

    public function test_passport_is_optional(): void
    {
        Storage::fake('local');

        // No passport, no error, and none stored.
        $application = $this->submitAs($this->student());

        $this->assertFalse(
            $application->documents()->where('doc_type', 'Passport')->exists()
        );
    }

    public function test_a_user_outside_the_approval_chain_gets_403_on_the_attachment(): void
    {
        Storage::fake('local');

        $application = $this->submitAs($this->student());
        $document = $application->documents()->where('doc_type', 'Receipt')->sole();

        $outsider = $this->student('22009999', 'other-student@test.my');

        $this->actingAs($outsider)
            ->get(route('documents.show', $document))
            ->assertForbidden();
    }

    public function test_the_owner_and_each_approver_can_view_the_attachment(): void
    {
        Storage::fake('local');

        // The student's own supervisor: only they may decide the supervisor stage.
        $supervisor = $this->supervisor();
        $student = $this->student(attributes: ['supervisor_id' => $supervisor->id]);
        $application = $this->submitAs($student);
        $document = $application->documents()->where('doc_type', 'Receipt')->sole();

        $chair = $this->chair();
        $cgs = $this->cgs();
        $manager = $this->manager();

        $this->actingAs($supervisor)->post(route('claims.decide', $application), ['decision' => 'approve']);
        $this->actingAs($chair)->post(route('claims.decide', $application), ['decision' => 'approve']);
        $this->actingAs($cgs)->post(route('claims.decide', $application), ['decision' => 'approve']);
        $this->actingAs($manager)->post(route('claims.decide', $application), ['decision' => 'approve']);

        $application->refresh();
        $this->assertSame(Application::STATUS_APPROVED, $application->status);

        foreach ([$student, $supervisor, $chair, $cgs, $manager] as $viewer) {
            $this->actingAs($viewer)
                ->get(route('documents.show', $document))
                ->assertOk();
        }
    }

    // ---- Linked to an approved publication -------------------------------

    public function test_the_form_offers_only_the_students_own_approved_publications(): void
    {
        $student = $this->student();
        $mine = $this->approvedPublication($student);
        $pending = $this->approvedPublication($student, Application::STATUS_PENDING);
        $theirs = $this->approvedPublication($this->student('22009999', 'other-student@test.my'));

        $this->actingAs($student)->get(route('claims.create'))
            ->assertOk()
            ->assertSee('value="'.$mine->id.'"', false)
            ->assertDontSee('value="'.$pending->id.'"', false)
            ->assertDontSee('value="'.$theirs->id.'"', false);
    }

    public function test_each_claims_document_takes_exactly_one_file(): void
    {
        Storage::fake('local');

        $student = $this->student();
        $publication = $this->approvedPublication($student);
        $pdf = fn (string $name) => UploadedFile::fake()->create($name, 100, 'application/pdf');

        foreach (['receipt' => 'Receipt', 'proof_of_payment' => 'Proof of Payment', 'invoice' => 'Invoice', 'passport' => 'Passport'] as $field => $label) {
            $this->actingAs($student)
                ->post(route('claims.store'), $this->claim([
                    'publication_application_id' => $publication->id,
                    $field => [$pdf('one.pdf'), $pdf('two.pdf')],
                ]))
                ->assertSessionHasErrors([$field => "Attach one file only for the {$label}."]);
        }
        $this->assertSame(0, ClaimsDetail::count());

        // Plain single-file inputs, the same PDF/JPG/PNG limit, no compile note.
        $this->get(route('claims.create'))->assertOk()
            ->assertSee('<input type="file" name="receipt" id="receipt"', false)
            ->assertSee('One file, PDF, JPG, JPEG or PNG, 5&nbsp;MB max.', false)
            ->assertDontSee('multiple', false)
            ->assertDontSee('Compile the file into 1');
    }

    public function test_a_claim_stores_one_file_per_document(): void
    {
        Storage::fake('local');

        $application = $this->submitAs($this->student(attributes: ['supervisor_id' => $this->supervisor()->id]));

        $this->assertEquals(
            ['Receipt' => 1, 'Proof of Payment' => 1, 'Invoice' => 1],
            $application->documents()->get()->countBy('doc_type')->all(),
        );
    }

    public function test_a_conference_publication_is_not_listed_but_a_journal_is(): void
    {
        $student = $this->student();
        $journal = $this->approvedPublication($student);
        $conference = $this->approvedPublication($student, detail: [
            'type_of_request' => 'publication_conference',
            'conference_scope' => 'local',
            'title_of_paper' => 'A Conference Paper',
        ]);

        $this->actingAs($student)->get(route('claims.create'))
            ->assertOk()
            ->assertSee('value="'.$journal->id.'"', false)
            ->assertDontSee('value="'.$conference->id.'"', false)
            ->assertDontSee('A Conference Paper');
    }

    public function test_a_conference_publication_is_refused_even_from_an_edited_request(): void
    {
        Storage::fake('local');

        $student = $this->student();
        $conference = $this->approvedPublication($student, detail: [
            'type_of_request' => 'publication_conference',
            'conference_scope' => 'international',
        ]);

        $this->actingAs($student)
            ->post(route('claims.store'), $this->claim(['publication_application_id' => $conference->id]))
            ->assertSessionHasErrors(['publication_application_id' => 'Choose one of your own approved journal publications that used the Pay and Claims cost centre and has not been claimed yet.']);

        $this->assertSame(0, ClaimsDetail::count());
    }

    public function test_an_approved_journal_of_the_students_own_is_accepted(): void
    {
        Storage::fake('local');

        $student = $this->student(attributes: ['supervisor_id' => $this->supervisor()->id]);
        $application = $this->submitAs($student);

        $this->assertSame(Application::STATUS_PENDING, $application->status);
        $this->assertSame('publication_journal', ClaimsDetail::sole()->publication->type_of_request);
    }

    public function test_another_students_approved_journal_is_refused(): void
    {
        Storage::fake('local');

        $student = $this->student();
        $theirs = $this->approvedPublication($this->student('22009999', 'other-student@test.my'));

        $this->actingAs($student)->get(route('claims.create'))
            ->assertDontSee('value="'.$theirs->id.'"', false);

        $this->actingAs($student)
            ->post(route('claims.store'), $this->claim(['publication_application_id' => $theirs->id]))
            ->assertSessionHasErrors('publication_application_id');

        $this->assertSame(0, ClaimsDetail::count());
    }

    public function test_the_chosen_publication_carries_what_the_summary_box_and_rm_prefill_need(): void
    {
        $student = $this->student();
        $this->approvedPublication($student, detail: ['currency_type' => 'USD', 'conference_journal_fee' => 1200]);

        $this->actingAs($student)->get(route('claims.create'))
            ->assertOk()
            ->assertSee('data-publication-summary', false)
            ->assertSee('data-title="Reservoir Simulation with Graph Networks"', false)
            ->assertSee('data-journal="Journal of Petroleum Science"', false)
            ->assertSee('data-publisher="Elsevier"', false)
            ->assertSee('data-currency="USD"', false)
            ->assertSee('data-fee="1200.00"', false)
            ->assertSee('data-cost-centre="CGS – Pay and Claims"', false)
            // The amount stays the student's to type: never readonly.
            ->assertDontSee('readonly', false);
    }

    public function test_with_no_approved_publication_there_is_nothing_to_claim_for(): void
    {
        $student = $this->student();
        $this->approvedPublication($student, Application::STATUS_PENDING);

        $this->actingAs($student)->get(route('claims.create'))
            ->assertOk()
            ->assertSee('You have no publication you can claim for yet')
            ->assertSee('Only approved journal publications that used the Pay and Claims cost centre can be claimed, once each')
            ->assertDontSee('name="total_claims"', false);
    }

    // ---- Pay and Claims only (UAT, 11 Nov 2026) ---------------------------

    public function test_only_pay_and_claims_journals_are_listed(): void
    {
        $student = $this->student();
        $pay = $this->approvedPublication($student, detail: ['title_of_paper' => 'Paid By Claim']);
        $lou = $this->approvedPublication($student, detail: ['cost_centre' => 'cgs_letter_of_undertaking', 'title_of_paper' => 'LOU Paper']);
        $split = $this->approvedPublication($student, detail: ['cost_centre' => 'split_memo', 'title_of_paper' => 'Split Paper']);
        $others = $this->approvedPublication($student, detail: ['cost_centre' => 'others', 'cost_centre_other' => 'Dept fund', 'title_of_paper' => 'Others Paper']);

        $this->actingAs($student)->get(route('claims.create'))
            ->assertOk()
            ->assertSee('value="'.$pay->id.'"', false)
            ->assertDontSee('value="'.$lou->id.'"', false)
            ->assertDontSee('value="'.$split->id.'"', false)
            ->assertDontSee('value="'.$others->id.'"', false)
            ->assertDontSee('LOU Paper')
            ->assertSee('Only approved journal publications that used the Pay and Claims cost centre can be claimed.');
    }

    public function test_a_journal_on_another_cost_centre_is_refused_even_from_an_edited_request(): void
    {
        Storage::fake('local');

        $student = $this->student();

        foreach (['cgs_letter_of_undertaking', 'split_memo', 'others'] as $costCentre) {
            $publication = $this->approvedPublication($student, detail: ['cost_centre' => $costCentre, 'cost_centre_other' => 'Dept fund']);

            $this->actingAs($student)
                ->post(route('claims.store'), $this->claim(['publication_application_id' => $publication->id]))
                ->assertSessionHasErrors(['publication_application_id' => 'Choose one of your own approved journal publications that used the Pay and Claims cost centre and has not been claimed yet.']);
        }

        $this->assertSame(0, ClaimsDetail::count());
    }

    public function test_a_pay_and_claims_publication_not_yet_finally_approved_is_refused(): void
    {
        Storage::fake('local');

        $student = $this->student();

        foreach ([Application::STATUS_PENDING, Application::STATUS_REJECTED, Application::STATUS_DRAFT] as $status) {
            $publication = $this->approvedPublication($student, $status);

            $this->actingAs($student)
                ->post(route('claims.store'), $this->claim(['publication_application_id' => $publication->id]))
                ->assertSessionHasErrors('publication_application_id');
        }

        $this->assertSame(0, ClaimsDetail::count());
    }

    public function test_a_claim_must_name_one_of_the_students_own_approved_publications(): void
    {
        Storage::fake('local');

        $student = $this->student();
        $pending = $this->approvedPublication($student, Application::STATUS_PENDING);
        $theirs = $this->approvedPublication($this->student('22009999', 'other-student@test.my'));

        foreach ([null, $pending->id, $theirs->id] as $publication) {
            $this->actingAs($student)
                ->post(route('claims.store'), $this->claim(['publication_application_id' => $publication]))
                ->assertSessionHasErrors('publication_application_id');
        }

        $this->assertSame(0, ClaimsDetail::count());
    }

    public function test_the_approver_sees_the_publication_the_claim_is_for(): void
    {
        Storage::fake('local');

        $supervisor = $this->supervisor();
        $application = $this->submitAs($this->student(attributes: ['supervisor_id' => $supervisor->id]));

        $this->assertSame(
            Application::where('module_type', 'publication')->value('id'),
            ClaimsDetail::where('application_id', $application->id)->value('publication_application_id'),
        );

        $this->actingAs($supervisor)->get(route('claims.queue'))
            ->assertOk()
            ->assertSee('Reservoir Simulation with Graph Networks')
            ->assertSee('MYR 2,500.00');
    }
}
