<?php

namespace Tests\Feature\Norhanis;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Core\Support\Role;
use App\Modules\Norhanis\Models\ClaimsDetail;
use App\Modules\Norhanis\Models\PublicationDetail;
use App\Modules\Norhanis\Notifications\ClaimNextStep;
use App\Modules\Norhanis\Notifications\LetterOfUndertakingNotice;
use App\Modules\Norhanis\Notifications\SplitMemoInstructions;
use App\Modules\Norhanis\Support\ClaimablePublications;
use App\Modules\Norhanis\Support\PublicationFollowUp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Norhanis\Concerns\HasApprovedPublication;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * Student Claims with less clicking (UAT, 11 Nov 2026). The claim form
 * offers only the publications the student can still claim
 * (Support\ClaimablePublications): one is shown outright, several get a
 * short dropdown, none get the empty state. A deep link (?publication=)
 * preselects one; a publication that is not claimable is ignored there
 * and refused on submit. One claim per publication. On final approval a
 * journal's follow-up email and its View details page say what comes next
 * by cost centre, with "Start your claim" for Pay and Claims.
 */
class ClaimsLessClickingTest extends TestCase
{
    use HasApprovedPublication;
    use MakesUsers;
    use RefreshDatabase;

    protected User $student;

    protected User $supervisor;

    /** @var array<int, User>|null the chain's approvers, made on first use */
    protected ?array $approvers = null;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Notification::fake();

        $this->supervisor = $this->supervisor();
        $this->student = $this->student(attributes: ['supervisor_id' => $this->supervisor->id, 'department' => 'Computer & Information Sciences']);
    }

    // ---- The form ----------------------------------------------------------------

    public function test_a_single_claimable_publication_is_shown_without_a_dropdown(): void
    {
        $publication = $this->approvedPublication($this->student, detail: ['currency_type' => 'MYR', 'conference_journal_fee' => 1800]);

        $html = $this->actingAs($this->student)->get(route('claims.create'))->assertOk()->getContent();

        $this->assertStringNotContainsString('<select name="publication_application_id"', $html);
        $this->assertMatchesRegularExpression('/<input type="hidden" name="publication_application_id" id="publication_application_id" value="'.$publication->id.'"/', $html);
        // The read-only box, already filled in: title, journal / publisher, fee, cost centre.
        $this->assertMatchesRegularExpression('/<div class="claim-publication" data-publication-summary\s*>/', $html);
        $this->assertStringContainsString('<dd data-summary="title">Reservoir Simulation with Graph Networks</dd>', $html);
        $this->assertStringContainsString('<dd data-summary="venue">Journal of Petroleum Science / Elsevier</dd>', $html);
        $this->assertStringContainsString('<dd data-summary="fee">MYR 1,800.00</dd>', $html);
        $this->assertStringContainsString('<dd data-summary="costCentre">CGS – Pay and Claims</dd>', $html);
    }

    public function test_several_get_a_short_dropdown(): void
    {
        $first = $this->approvedPublication($this->student, detail: ['title_of_paper' => 'First Paper']);
        $second = $this->approvedPublication($this->student, detail: ['title_of_paper' => 'Second Paper']);

        $html = $this->actingAs($this->student)->get(route('claims.create'))->assertOk()->getContent();

        $this->assertStringContainsString('<select name="publication_application_id"', $html);
        $this->assertMatchesRegularExpression('/>#'.$first->id.' · First Paper<\/option>/', $html);
        $this->assertMatchesRegularExpression('/>#'.$second->id.' · Second Paper<\/option>/', $html);
        // Nothing chosen yet: the box waits for a choice.
        $this->assertMatchesRegularExpression('/<div class="claim-publication" data-publication-summary\s+hidden\s*>/', $html);
    }

    public function test_none_shows_the_empty_state(): void
    {
        $this->approvedPublication($this->student, detail: ['cost_centre' => 'cgs_letter_of_undertaking']);

        $this->actingAs($this->student)->get(route('claims.create'))
            ->assertOk()
            ->assertSee('You have no publication you can claim for yet.')
            ->assertDontSee('name="publication_application_id"', false);
    }

    public function test_a_claimed_publication_leaves_the_list_and_a_rejected_claim_frees_it(): void
    {
        $claimed = $this->approvedPublication($this->student, detail: ['title_of_paper' => 'Claimed Paper']);
        $open = $this->approvedPublication($this->student, detail: ['title_of_paper' => 'Open Paper']);

        $claim = $this->claim($claimed);
        $this->assertTrue(ClaimablePublications::isClaimed($claimed->id));

        // Only one left, so it is shown outright; the claimed one is gone.
        $this->actingAs($this->student)->get(route('claims.create'))
            ->assertOk()
            ->assertDontSee('Claimed Paper')
            ->assertSee('value="'.$open->id.'"', false);

        $this->actingAs($this->supervisor)
            ->post(route('claims.decide', $claim), ['decision' => 'reject', 'remarks' => 'Wrong receipt.']);
        $this->assertSame(Application::STATUS_REJECTED, $claim->fresh()->status);

        $this->assertFalse(ClaimablePublications::isClaimed($claimed->id));
        $this->actingAs($this->student)->get(route('claims.create'))->assertSee('Claimed Paper');
    }

    public function test_one_claim_per_publication_on_the_server(): void
    {
        $publication = $this->approvedPublication($this->student);
        $this->claim($publication);

        $this->actingAs($this->student)
            ->post(route('claims.store'), $this->claimPayload($publication))
            ->assertSessionHasErrors('publication_application_id');

        $this->assertSame(1, ClaimsDetail::where('publication_application_id', $publication->id)->count());
    }

    // ---- The deep link -----------------------------------------------------------

    public function test_the_deep_link_preselects_a_claimable_publication(): void
    {
        $this->approvedPublication($this->student, detail: ['title_of_paper' => 'Other Paper']);
        $wanted = $this->approvedPublication($this->student, detail: ['title_of_paper' => 'Wanted Paper']);

        $this->assertSame(route('claims.create').'?publication='.$wanted->id, ClaimablePublications::claimUrl($wanted->id));

        $html = $this->actingAs($this->student)->get(ClaimablePublications::claimUrl($wanted->id))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<option value="'.$wanted->id.'"\s+selected/', $html);
        $this->assertStringContainsString('<dd data-summary="title">Wanted Paper</dd>', $html);
        $this->assertStringNotContainsString('That publication cannot be claimed', $html);
    }

    public function test_the_deep_link_ignores_a_publication_that_is_not_claimable(): void
    {
        $mine = $this->approvedPublication($this->student, detail: ['title_of_paper' => 'Mine A']);
        $this->approvedPublication($this->student, detail: ['title_of_paper' => 'Mine B']);

        $theirs = $this->approvedPublication($this->student('22009999', 'other@test.my'));
        $lou = $this->approvedPublication($this->student, detail: ['cost_centre' => 'cgs_letter_of_undertaking']);
        $conference = $this->approvedPublication($this->student, detail: ['type_of_request' => 'publication_conference', 'conference_scope' => 'local', 'cost_centre' => 'others', 'cost_centre_other' => 'Grant']);
        $pending = $this->approvedPublication($this->student, Application::STATUS_PENDING);

        foreach ([$theirs, $lou, $conference, $pending] as $notClaimable) {
            $html = $this->actingAs($this->student)->get(ClaimablePublications::claimUrl($notClaimable->id))->assertOk()->getContent();

            $this->assertStringContainsString('That publication cannot be claimed', $html);
            $this->assertStringNotContainsString('value="'.$notClaimable->id.'"', $html);
            $this->assertDoesNotMatchRegularExpression('/<option value="\d+"\s+selected/', $html);

            // And submitting it anyway is refused.
            $this->actingAs($this->student)
                ->post(route('claims.store'), $this->claimPayload($notClaimable))
                ->assertSessionHasErrors('publication_application_id');
        }

        $this->assertSame(0, ClaimsDetail::count());
        $this->assertTrue(app(ClaimablePublications::class)->isClaimable($this->student, $mine->id));
    }

    // ---- Final approval: the follow-up by cost centre -------------------------------

    public function test_pay_and_claims_sends_the_next_step_with_the_claim_button(): void
    {
        $application = $this->approveThroughChain('cgs_pay_and_claims');

        Notification::assertSentToTimes($this->student, ClaimNextStep::class, 1);
        Notification::assertSentTo($this->student, ClaimNextStep::class, function (ClaimNextStep $notification) use ($application) {
            $mail = $notification->toMail($this->student);

            return in_array('Next step: you can now submit your claim for this publication in the Student Claims application in UResearch 2.0.', $mail->introLines, true)
                && $mail->actionText === 'Start your claim'
                && $mail->actionUrl === route('claims.create', ['publication' => $application->id]);
        });
        Notification::assertNotSentTo($this->student, LetterOfUndertakingNotice::class);
        Notification::assertNotSentTo($this->student, SplitMemoInstructions::class);

        // The button lands on the claim form with it chosen.
        $this->actingAs($this->student)->get(route('claims.create', ['publication' => $application->id]))
            ->assertOk()
            ->assertSee('value="'.$application->id.'"', false)
            ->assertDontSee('That publication cannot be claimed');
    }

    public function test_lou_says_cgs_pays_directly(): void
    {
        $this->approveThroughChain('cgs_letter_of_undertaking');

        Notification::assertSentTo($this->student, LetterOfUndertakingNotice::class, function (LetterOfUndertakingNotice $notification) {
            return in_array('CGS will process the payment directly. No claim is needed.', $notification->toMail($this->student)->introLines, true);
        });
        Notification::assertNotSentTo($this->student, ClaimNextStep::class);
        Notification::assertNotSentTo($this->student, SplitMemoInstructions::class);
    }

    public function test_split_memo_sends_only_the_memo_email(): void
    {
        $this->approveThroughChain('split_memo');

        Notification::assertSentToTimes($this->student, SplitMemoInstructions::class, 1);
        Notification::assertNotSentTo($this->student, ClaimNextStep::class);
        Notification::assertNotSentTo($this->student, LetterOfUndertakingNotice::class);
    }

    public function test_a_conference_and_others_get_no_extra_text(): void
    {
        $this->approveThroughChain('others', 'publication_conference');
        $this->approveThroughChain('others');

        foreach ([ClaimNextStep::class, LetterOfUndertakingNotice::class, SplitMemoInstructions::class] as $notification) {
            Notification::assertNotSentTo($this->student, $notification);
        }
    }

    public function test_the_follow_up_goes_once(): void
    {
        $application = $this->approveThroughChain('cgs_pay_and_claims');

        app(PublicationFollowUp::class)->afterFinalApproval($application->fresh());
        app(PublicationFollowUp::class)->afterFinalApproval($application->fresh());

        Notification::assertSentToTimes($this->student, ClaimNextStep::class, 1);
    }

    // ---- The application page ------------------------------------------------------

    public function test_the_application_page_offers_start_your_claim_until_it_is_claimed(): void
    {
        $application = $this->approveThroughChain('cgs_pay_and_claims');
        $url = route('claims.create', ['publication' => $application->id]);

        $this->actingAs($this->student)->get(route('applications.show', $application))
            ->assertOk()
            ->assertSee('Next step: you can now submit your claim for this publication in the Student Claims application in UResearch 2.0.')
            ->assertSee('href="'.e($url).'" class="btn">Start your claim</a>', false);

        $this->claim($application);

        $this->actingAs($this->student)->get(route('applications.show', $application))
            ->assertOk()
            ->assertDontSee('Start your claim')
            ->assertSee('Your claim for this publication has been submitted.');
    }

    public function test_the_application_page_says_no_claim_is_needed_for_an_lou(): void
    {
        $application = $this->approveThroughChain('cgs_letter_of_undertaking');

        $this->actingAs($this->student)->get(route('applications.show', $application))
            ->assertOk()
            ->assertSee('CGS will process the payment directly. No claim is needed.')
            ->assertDontSee('Start your claim');
    }

    // ---- Helpers -------------------------------------------------------------------

    /** A journal (or conference) publication walked through the whole chain by its approvers. */
    protected function approveThroughChain(string $costCentre, string $type = 'publication_journal'): Application
    {
        $approvers = $this->approvers ??= [
            $this->supervisor,
            $this->user(Role::CHAIR, ['email' => 'chair@test.my', 'department' => 'Computer & Information Sciences']),
            $this->cgs(),
            $this->seniorDirector(),
        ];

        $application = Application::create(['student_id' => $this->student->id, 'module_type' => 'publication', 'status' => Application::STATUS_DRAFT]);

        PublicationDetail::create([
            'application_id' => $application->id,
            'type_of_request' => $type,
            'conference_scope' => $type === 'publication_conference' ? 'local' : null,
            'title_of_paper' => 'Reservoir Simulation with Graph Networks',
            'title_of_conference_journal' => 'Journal of Petroleum Science',
            'organizer_publisher' => 'Elsevier',
            'conference_journal_fee' => 2500,
            'currency_type' => 'MYR',
            'cost_centre' => $costCentre,
            'cost_centre_other' => $costCentre === 'others' ? 'Department fund' : null,
            'wants_letter_of_undertaking' => $costCentre === 'cgs_letter_of_undertaking',
        ]);

        $application = app(WorkflowEngine::class)->submit($application);

        foreach ($approvers as $approver) {
            $this->actingAs($approver)
                ->from(route('publication.queue'))
                ->post(route('publication.decide', $application), ['decision' => 'approve'])
                ->assertSessionHasNoErrors();
        }

        $this->assertSame(Application::STATUS_APPROVED, $application->fresh()->status);

        return $application->fresh();
    }

    protected function claim(Application $publication): Application
    {
        $this->actingAs($this->student)
            ->post(route('claims.store'), $this->claimPayload($publication))
            ->assertSessionHasNoErrors();

        return Application::where('module_type', 'claims_student')->latest('id')->firstOrFail();
    }

    protected function claimPayload(Application $publication): array
    {
        return [
            'publication_application_id' => $publication->id,
            'bank_name' => 'Maybank',
            'bank_account_no' => '1234567890',
            'total_claims' => 900,
            'receipt' => UploadedFile::fake()->create('receipt.pdf', 100, 'application/pdf'),
            'proof_of_payment' => UploadedFile::fake()->create('payment.pdf', 100, 'application/pdf'),
            'invoice' => UploadedFile::fake()->create('invoice.pdf', 100, 'application/pdf'),
        ];
    }
}
