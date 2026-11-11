<?php

namespace Tests\Feature\Hani;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\ApplicationDecided;
use App\Modules\Core\Support\Role;
use App\Modules\Hani\Models\VivaDetail;
use App\Modules\Hani\Models\VivaEligibility;
use App\Modules\Hani\Notifications\VivaNominateExaminers;
use App\Modules\Hani\Notifications\VivaNoticeDecision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Hani\Concerns\FilesVivaNotices;
use Tests\Feature\Hani\Concerns\KeepsJobsOffTheDevQueue;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * The AE's Verification of a Notice of Thesis Submission, matching UCampus
 * (UAT 11 Nov 2026): each eligibility item Verified / Not met, then
 * Verified (every item verified) or Rejected (comment required, emailed).
 */
class VivaNoticeVerificationTest extends TestCase
{
    use FilesVivaNotices;
    use KeepsJobsOffTheDevQueue;
    use MakesUsers;
    use RefreshDatabase;

    protected const MSC_CHECKS = [
        'research_methodology_passed' => 1, 'publication_accepted' => 1,
        'registered_current_semester' => 1, 'no_outstanding_fees' => 1, 'within_candidacy_period' => 1,
    ];

    protected User $student;

    protected User $ae;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Notification::fake();

        $supervisor = $this->user(Role::SUPERVISOR, ['name' => 'Dr Supervisor', 'email' => 'sv@test.my', 'department' => 'Computing']);
        $this->student = $this->student(attributes: ['programme' => 'MSc Full-Time', 'department' => 'Computing', 'supervisor_id' => $supervisor->id]);
        $this->ae = $this->academicExec();
        $this->ae->update(['department' => 'Computing']);
        VivaEligibility::create(['student_id' => $this->student->id] + self::MSC_CHECKS);
    }

    protected function file(): Application
    {
        $this->actingAs($this->student)->post(route('viva.notice.store'), $this->noticeFields())->assertSessionHasNoErrors();
        $notice = Application::where('module_type', 'viva_voce')->latest('id')->first();

        // The supervisor endorses it first (item E); then it is the AE's.
        $this->actingAs($this->student->supervisor)->post(route('viva.endorse', $notice), ['endorsement' => 'endorsed'])->assertSessionHasNoErrors();

        return $notice->fresh();
    }

    public function test_the_card_shows_the_ucampus_sections_and_the_verification(): void
    {
        $notice = $this->file();

        $this->actingAs($this->ae)->get(route('viva.queue'))->assertOk()
            ->assertSeeInOrder(['Student Details', 'Request Summary', 'Documents', 'Eligibility', 'Academic Executive', 'Verification'])
            ->assertSee('Date of registration')
            ->assertSee('Dr Supervisor')
            ->assertSee('Full Time')
            ->assertSee('Notice thesis submission date')
            ->assertSee('accepted.pdf')
            ->assertSee('rm-result.pdf')
            ->assertSee('I am an active student for this semester')
            ->assertSee('name="items[registered_current_semester]"', false)
            ->assertSee('value="rejected"', false)
            ->assertDontSee('Acknowledge');
    }

    public function test_verified_needs_every_item_marked_verified(): void
    {
        $notice = $this->file();
        $items = array_fill_keys(array_keys(self::MSC_CHECKS), 'verified');

        $this->verifyNotice($this->ae, $notice, ['items' => ['no_outstanding_fees' => 'not_met'] + $items])->assertSessionHasErrors('items');
        $this->verifyNotice($this->ae, $notice, ['items' => array_slice($items, 0, 3, true)])->assertSessionHasErrors('items');
        $this->assertSame(Application::STATUS_PENDING, $notice->fresh()->status);

        $this->verifyNotice($this->ae, $notice)->assertSessionHasNoErrors();
        $this->assertSame(Application::STATUS_APPROVED, $notice->fresh()->status);
        $this->assertSame('verified', $notice->history()->latest('id')->value('decision'));
        $this->assertSame($items, VivaDetail::where('application_id', $notice->id)->value('ae_item_checks'));
        $this->assertSame($this->ae->id, VivaEligibility::sole()->verified_by_id);
        Notification::assertSentTo($this->student->supervisor, VivaNominateExaminers::class);
    }

    public function test_rejected_needs_a_comment_emails_it_and_lets_the_student_file_again(): void
    {
        $notice = $this->file();

        $this->actingAs($this->ae)->post(route('viva.decide', $notice), ['verification' => 'rejected', 'items' => []])
            ->assertSessionHasErrors('comment');
        $this->assertSame(Application::STATUS_PENDING, $notice->fresh()->status);

        $this->actingAs($this->ae)->post(route('viva.decide', $notice), [
            'verification' => 'rejected', 'items' => ['no_outstanding_fees' => 'not_met'], 'comment' => 'The fee statement shows RM 450 outstanding.',
        ])->assertSessionHasNoErrors();

        $this->assertSame(Application::STATUS_REJECTED, $notice->fresh()->status);
        $this->assertSame('not_met', VivaDetail::where('application_id', $notice->id)->value('ae_item_checks')['no_outstanding_fees']);
        Notification::assertSentTo($this->student, VivaNoticeDecision::class,
            fn ($n) => $n->kind === VivaNoticeDecision::REJECTED && $n->comment === 'The fee statement shows RM 450 outstanding.');
        Notification::assertNotSentTo($this->student->supervisor, VivaNominateExaminers::class);

        // The engine's "not approved, this is final" email is held back.
        $stage = app(\App\Modules\Hani\Workflows\VivaVoceWorkflow::class)->stages()[0];
        $this->assertFalse(Event::until(new NotificationSending($this->student, new ApplicationDecided($notice->fresh(), $stage, false), 'mail')));

        // The checklist unlocks and a new notice can be filed.
        $this->actingAs($this->student)->get(route('viva.eligibility'))->assertOk()->assertSee('name="thesis_title"', false);
        $second = $this->file();
        $this->assertNotSame($notice->id, $second->id);
        $this->assertNull(VivaDetail::where('application_id', $second->id)->value('ae_item_checks'), 'a new notice starts unmarked');
    }

    public function test_bulk_verification_is_still_refused(): void
    {
        $notice = $this->file();
        $this->actingAs($this->ae)->post(route('queue.decide-bulk', 'viva_voce'), ['ids' => [$notice->id], 'decision' => 'approve'])->assertForbidden();
    }

    public function test_the_notice_form_shows_the_title_counter_and_the_two_document_fields(): void
    {
        $this->actingAs($this->student)->get(route('viva.eligibility'))->assertOk()
            ->assertSee('data-title-words', false)
            ->assertSee('At most 15 words.')
            ->assertSee('name="publication_papers[]"', false)
            ->assertSee('name="research_methodology_result"', false)
            ->assertDontSee('publication_proof');
    }
}
