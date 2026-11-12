<?php

namespace Tests\Feature\Hani;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\ApplicationDecided;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Core\Support\Role;
use App\Modules\Hani\Models\VivaDetail;
use App\Modules\Hani\Models\VivaEligibility;
use App\Modules\Hani\Notifications\VivaAwaitingAcademicExec;
use App\Modules\Hani\Notifications\VivaNoticeDecision;
use App\Modules\Hani\Notifications\VivaNoticeSubmitted;
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
 * The notice goes to the student's own supervisor first (UAT 11 Nov 2026,
 * item E): endorsed -> the AE; returned (comment required) -> the student
 * edits the same notice and resubmits it.
 */
class VivaSupervisorEndorsementTest extends TestCase
{
    use FilesVivaNotices;
    use KeepsJobsOffTheDevQueue;
    use MakesUsers;
    use RefreshDatabase;

    protected User $student;

    protected User $supervisor;

    protected User $ae;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Notification::fake();

        $this->supervisor = $this->user(Role::SUPERVISOR, ['name' => 'Dr Own Supervisor', 'email' => 'own.sv@test.my', 'department' => 'Computing']);
        $this->student = $this->student(attributes: ['programme' => 'MSc Full-Time', 'department' => 'Computing', 'supervisor_id' => $this->supervisor->id]);
        $this->ae = $this->academicExec();
        $this->ae->update(['department' => 'Computing']);
        VivaEligibility::create(['student_id' => $this->student->id, 'research_methodology_passed' => 1, 'publication_accepted' => 1,
            'registered_current_semester' => 1, 'no_outstanding_fees' => 1, 'within_candidacy_period' => 1]);
    }

    protected function file(array $overrides = []): Application
    {
        $this->actingAs($this->student)->post(route('viva.notice.store'), $this->noticeFields($overrides))->assertSessionHasNoErrors();

        return Application::where('module_type', 'viva_voce')->latest('id')->first();
    }

    public function test_a_new_notice_goes_to_the_supervisor_first_and_the_ae_is_told_only_on_endorsement(): void
    {
        $notice = $this->file();

        $this->assertSame('sv_endorsement', $notice->current_stage);
        Notification::assertSentTo($this->student, VivaNoticeSubmitted::class, fn ($n) => str_contains(implode(' ', $n->toMail($this->student)->introLines), 'With supervisor'));
        Notification::assertSentTo($this->supervisor, VivaAwaitingAcademicExec::class);
        Notification::assertNotSentTo($this->ae, VivaAwaitingAcademicExec::class);
        $this->actingAs($this->student)->get(route('viva.eligibility'))->assertSee('With supervisor');

        // The supervisor's card: the same sections, no per-item verification.
        $this->actingAs($this->supervisor)->get(route('viva.queue'))->assertOk()
            ->assertSeeInOrder(['Student Details', 'Request Summary', 'Documents', 'Document checks', 'Eligibility', 'Endorsement'])
            ->assertDontSee('name="items[', false);
        $this->actingAs($this->ae)->get(route('viva.queue'))->assertOk()->assertDontSee($this->student->name);

        $this->actingAs($this->supervisor)->post(route('viva.endorse', $notice), ['endorsement' => 'endorsed'])->assertSessionHasNoErrors();

        $this->assertSame('notice_received', $notice->fresh()->current_stage);
        $this->assertSame('endorsed', $notice->history()->where('stage_key', 'sv_endorsement')->value('decision'));
        Notification::assertSentTo($this->ae, VivaAwaitingAcademicExec::class);
        $this->actingAs($this->ae)->get(route('viva.queue'))->assertSee($this->student->name);
    }

    public function test_only_the_students_own_supervisor_sees_and_endorses_it(): void
    {
        $notice = $this->file();
        $other = $this->user(Role::SUPERVISOR, ['name' => 'Dr Someone Else', 'email' => 'other.sv@test.my', 'department' => 'Computing']);

        $this->actingAs($other)->get(route('viva.queue'))->assertOk()->assertDontSee($this->student->name);
        $this->actingAs($other)->post(route('viva.endorse', $notice), ['endorsement' => 'endorsed'])->assertForbidden();
        $this->actingAs($this->ae)->post(route('viva.decide', $notice), ['verification' => 'rejected', 'comment' => 'Too early.'])->assertSessionHas('error');
        $this->assertSame('sv_endorsement', $notice->fresh()->current_stage);
    }

    public function test_return_needs_a_comment_emails_the_student_and_the_edited_notice_goes_back_to_the_supervisor(): void
    {
        $notice = $this->file();

        $this->actingAs($this->supervisor)->post(route('viva.endorse', $notice), ['endorsement' => 'returned'])->assertSessionHasErrors('comment');

        $this->actingAs($this->supervisor)->post(route('viva.endorse', $notice), [
            'endorsement' => 'returned', 'comment' => 'Use the title we agreed in our last meeting.',
        ])->assertSessionHasNoErrors();

        $this->assertSame(Application::STATUS_RETURNED, $notice->fresh()->status);
        Notification::assertSentTo($this->student, VivaNoticeDecision::class,
            fn ($n) => $n->kind === VivaNoticeDecision::RETURNED && str_contains($n->comment, 'agreed'));
        $stage = app(\App\Modules\Hani\Workflows\VivaVoceWorkflow::class)->stages($notice->fresh())[0];
        $this->assertFalse(Event::until(new NotificationSending($this->student, new ApplicationDecided($notice->fresh(), $stage, false, 'returned'), 'mail')),
            'the engine\'s own return email is replaced by the one with the comment');

        // The page shows the comment and the notice filled in, to edit.
        $this->actingAs($this->student)->get(route('viva.eligibility'))->assertOk()
            ->assertSee('Your supervisor returned your notice for changes')
            ->assertSee('Use the title we agreed in our last meeting.')
            ->assertSee('value="Constraint-based examiner matching"', false);

        $again = $this->file(['thesis_title' => 'Agreed title for examiner matching']);

        $this->assertSame($notice->id, $again->id, 'the same notice, resubmitted');
        $this->assertSame(Application::STATUS_PENDING, $again->status);
        $this->assertSame('sv_endorsement', $again->current_stage);
        $this->assertSame('Agreed title for examiner matching', VivaDetail::where('application_id', $notice->id)->value('thesis_title'));
        $this->assertSame(2, $again->documents()->count(), 'the new files replace the old');
    }

    public function test_a_notice_already_with_the_ae_stays_there_and_a_student_without_a_supervisor_goes_straight_to_the_ae(): void
    {
        $orphan = $this->student('22009999', 'orphan@test.my', ['programme' => 'MSc Full-Time', 'department' => 'Computing']);
        VivaEligibility::create(['student_id' => $orphan->id, 'research_methodology_passed' => 1, 'publication_accepted' => 1,
            'registered_current_semester' => 1, 'no_outstanding_fees' => 1, 'within_candidacy_period' => 1]);

        $this->actingAs($orphan)->post(route('viva.notice.store'), $this->noticeFields())->assertSessionHasNoErrors();
        $notice = Application::where('student_id', $orphan->id)->sole();

        $this->assertSame('notice_received', $notice->current_stage);
        $this->verifyNotice($this->ae, $notice)->assertSessionHasNoErrors();
        $this->assertSame(Application::STATUS_APPROVED, $notice->fresh()->status);
    }

    public function test_bulk_endorsement_is_refused(): void
    {
        $notice = $this->file();
        $this->actingAs($this->supervisor)->post(route('queue.decide-bulk', 'viva_voce'), ['ids' => [$notice->id], 'decision' => 'approve'])->assertForbidden();
        $this->assertSame('sv_endorsement', $notice->fresh()->current_stage);
    }

    public function test_the_supervisor_sidebar_lists_the_notice_queue(): void
    {
        $this->actingAs($this->supervisor)->get(route('dashboard'))->assertOk()
            ->assertSee(route('viva.queue'), false)
            ->assertSee('Notice of Thesis Submission');
    }
}
