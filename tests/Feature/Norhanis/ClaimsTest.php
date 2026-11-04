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
                // Claims' own 5 MB cap (ClaimsController::FILE_RULES), tighter
                // than the portal-wide DocumentStore default.
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

        $student = $this->student();
        $application = $this->submitAs($student);
        $document = $application->documents()->where('doc_type', 'Receipt')->sole();

        $supervisor = $this->supervisor();
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

    public function test_with_no_approved_publication_there_is_nothing_to_claim_for(): void
    {
        $student = $this->student();
        $this->approvedPublication($student, Application::STATUS_PENDING);

        $this->actingAs($student)->get(route('claims.create'))
            ->assertOk()
            ->assertSee('You have no approved publication yet')
            ->assertDontSee('name="total_claims"', false);
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
