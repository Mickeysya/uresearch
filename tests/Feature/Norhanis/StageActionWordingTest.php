<?php

namespace Tests\Feature\Norhanis;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Core\Support\Role;
use App\Modules\Norhanis\Models\ClaimsDetail;
use App\Modules\Norhanis\Notifications\ApplicationAwaitingAction;
use App\Modules\Norhanis\Notifications\StalledApplicationReminder;
use App\Modules\Norhanis\Support\ApproverNotifier;
use App\Modules\Norhanis\Support\StageAction;
use App\Modules\Norhanis\Workflows\ClaimsWorkflow;
use App\Modules\Norhanis\Workflows\PublicationWorkflow;
use App\Modules\Norhanis\Workflows\TravelWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Norhanis\Concerns\HasApprovedPublication;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * Only the last approver approves (UAT, 11 Nov 2026): the Supervisor and the
 * Chair endorse, the Non-Executive CGS reviews, the final approver approves,
 * and Reject stays at every stage. One mapping per module (the workflow's
 * ACTIONS, Support\StageAction), read back by the button, the confirmation,
 * the flash message, the emails, the history, the stepper text, the queue,
 * the overview, the report and the summary PDF. The routing does not change.
 */
class StageActionWordingTest extends TestCase
{
    use HasApprovedPublication;
    use MakesUsers;
    use RefreshDatabase;

    protected User $supervisor;

    protected User $chair;

    protected User $officer;

    protected User $manager;

    protected User $student;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Notification::fake();

        $this->supervisor = $this->supervisor();
        $this->chair = $this->user(Role::CHAIR, ['email' => 'chair@test.my', 'department' => 'Computer & Information Sciences']);
        $this->officer = $this->cgs();
        $this->manager = $this->user(Role::MANAGER_CGS, ['email' => 'manager@test.my']);
        $this->student = $this->student(attributes: ['supervisor_id' => $this->supervisor->id, 'department' => 'Computer & Information Sciences']);
    }

    // ---- The mapping ---------------------------------------------------------

    public function test_each_module_maps_its_stages_once_and_only_the_last_approves(): void
    {
        $expected = [
            'supervisor' => StageAction::Endorse,
            'chair' => StageAction::Endorse,
            'cgs_review' => StageAction::Review,
        ];

        $this->assertSame($expected + ['manager' => StageAction::Approve], ClaimsWorkflow::ACTIONS);
        $this->assertSame($expected + ['senior_director' => StageAction::Approve], PublicationWorkflow::ACTIONS);
        $this->assertSame($expected + ['dean' => StageAction::Approve, 'senior_director' => StageAction::Approve], TravelWorkflow::ACTIONS);

        $chains = [
            'claims' => app(ClaimsWorkflow::class)->stages(),
            'publication' => app(PublicationWorkflow::class)->stages(),
            'travel local' => app(TravelWorkflow::class)->routes()['local'],
            'travel international' => app(TravelWorkflow::class)->routes()['international'],
        ];

        foreach ($chains as $name => $stages) {
            $words = array_map(fn ($stage) => $stage->decision, $stages);
            $this->assertSame(['endorsed', 'endorsed', 'reviewed', 'approved'], $words, $name);
        }

        // The routing is unchanged: same stages, same order, same final approvers.
        $this->assertSame(['supervisor', 'chair', 'cgs_review', 'senior_director'], array_map(fn ($s) => $s->key, $chains['travel local']));
        $this->assertSame(['supervisor', 'chair', 'cgs_review', 'dean'], array_map(fn ($s) => $s->key, $chains['travel international']));
        $this->assertSame(Role::DEAN_PGR, end($chains['travel international'])->role);
        $this->assertSame(Role::SENIOR_DIRECTOR_CGS, end($chains['travel local'])->role);
        $this->assertSame(Role::SENIOR_DIRECTOR_CGS, end($chains['publication'])->role);
        $this->assertSame(Role::MANAGER_CGS, end($chains['claims'])->role);
    }

    public function test_the_action_words_follow_one_pattern(): void
    {
        $this->assertSame(['Endorse', 'endorsed', 'Endorsed', 'endorsement'], [StageAction::Endorse->label(), StageAction::Endorse->past(), StageAction::Endorse->pastLabel(), StageAction::Endorse->noun()]);
        $this->assertSame(['Review', 'reviewed', 'Reviewed', 'review'], [StageAction::Review->label(), StageAction::Review->past(), StageAction::Review->pastLabel(), StageAction::Review->noun()]);
        $this->assertSame(['Approve', 'approved', 'Approved', 'approval'], [StageAction::Approve->label(), StageAction::Approve->past(), StageAction::Approve->pastLabel(), StageAction::Approve->noun()]);
        $this->assertSame('Rejected', StageAction::decisionLabel('rejected'));
        $this->assertSame('Reviewed', StageAction::decisionLabel('reviewed'));
    }

    // ---- Along the claims chain ----------------------------------------------

    public function test_each_stage_shows_its_own_button_and_confirmation_and_reject_stays(): void
    {
        $application = $this->claim();

        $steps = [
            [$this->supervisor, 'Endorse', 'It moves on to the Chair of Department.', 'Pending My Endorsement'],
            [$this->chair, 'Endorse', 'It moves on to the Non-Executive CGS.', 'Pending My Endorsement'],
            [$this->officer, 'Review', 'It moves on to the Manager CGS.', 'Pending My Review'],
            [$this->manager, 'Approve', 'This is the final approval.', 'Pending My Approval'],
        ];

        foreach ($steps as [$approver, $label, $then, $heading]) {
            $html = $this->actingAs($approver)->get(route('claims.queue'))->assertOk()->getContent();

            $this->assertStringContainsString('class="norhanis-decision"', $html);
            $this->assertMatchesRegularExpression('/data-confirm="'.$label.' Student Claims application #'.$application->id.'\? '.preg_quote($then, '/').'">'.$label.'<\/button>/', $html);
            $this->assertStringContainsString('data-confirm="Reject Student Claims application #'.$application->id.'? This ends the application.">Reject</button>', $html);
            // The queue heading names the action too.
            $this->assertStringContainsString('Student Claims: '.$heading, $html);

            $this->decide($approver, $application);
        }

        $this->assertSame(Application::STATUS_APPROVED, $application->fresh()->status);
    }

    public function test_the_flash_message_and_history_use_the_stage_word(): void
    {
        $application = $this->claim();

        $this->decide($this->supervisor, $application)
            ->assertSessionHas('status', "Application #{$application->id} endorsed. It now moves to the Chair of Department.");
        $this->decide($this->chair, $application)
            ->assertSessionHas('status', "Application #{$application->id} endorsed. It now moves to the Non-Executive CGS.");
        $this->decide($this->officer, $application)
            ->assertSessionHas('status', "Application #{$application->id} reviewed. It now moves to the Manager CGS.");
        $this->decide($this->manager, $application)
            ->assertSessionHas('status', "Application #{$application->id} approved. This was the final approval.");

        $this->assertSame(
            ['endorsed', 'endorsed', 'reviewed', 'approved'],
            $application->history()->orderBy('id')->pluck('decision')->all(),
        );
    }

    public function test_reject_keeps_its_word_at_an_intermediate_stage(): void
    {
        $application = $this->claim();

        $this->actingAs($this->supervisor)
            ->post(route('claims.decide', $application), ['decision' => 'reject', 'remarks' => 'Receipt unreadable.'])
            ->assertSessionHas('status', "Application #{$application->id} rejected.");

        $this->assertSame('rejected', $application->history()->sole()->decision);
    }

    public function test_the_approver_emails_name_the_action(): void
    {
        $application = $this->claim();

        $this->assertAwaitingSubject($this->supervisor, "Student Claims Application #{$application->id}: Awaiting your endorsement", 'waiting for your endorsement at the Lecturer/Supervisor stage');

        $this->decide($this->supervisor, $application);
        $this->assertAwaitingSubject($this->chair, "Student Claims Application #{$application->id}: Awaiting your endorsement", 'waiting for your endorsement at the Chair of Department stage');

        $this->decide($this->chair, $application);
        $this->assertAwaitingSubject($this->officer, "Student Claims Application #{$application->id}: Awaiting your review", 'waiting for your review at the Non-Executive CGS stage');

        $this->decide($this->officer, $application);
        $this->assertAwaitingSubject($this->manager, "Student Claims Application #{$application->id}: Awaiting your approval", 'waiting for your approval at the Manager CGS stage');

        $reminder = new StalledApplicationReminder($application->fresh(), $application->fresh()->currentStage(), 6);
        $this->assertSame("Student Claims Application #{$application->id}: Reminder, still awaiting your approval", $reminder->toMail($this->manager)->subject);
    }

    public function test_the_students_page_says_what_each_stage_does_next(): void
    {
        $application = $this->claim();

        $this->actingAs($this->student)->get(route('applications.show', $application))
            ->assertOk()
            ->assertSee('Lecturer/Supervisor will endorse it next.');

        $this->decide($this->supervisor, $application);
        $this->decide($this->chair, $application);

        $this->actingAs($this->student)->get(route('applications.show', $application))
            ->assertOk()
            ->assertSee('Non-Executive CGS will review it next.')
            ->assertSee('Endorsed by');

        $this->decide($this->officer, $application);

        $this->actingAs($this->student)->get(route('applications.show', $application))
            ->assertSee('Manager CGS will approve it next.')
            ->assertSee('Reviewed by');
    }

    public function test_the_summary_pdf_names_each_stages_action_and_decision(): void
    {
        $application = $this->claim();
        $this->decide($this->supervisor, $application);
        $this->decide($this->chair, $application);

        $application = $application->fresh(['history.approver', 'student', 'documents']);

        $html = view('norhanis::pack.summary_pdf', [
            'application' => $application,
            'student' => $application->student,
            'module' => $application->module(),
            'stage' => $application->currentStage(),
            'history' => $application->history->sortBy('created_at')->values(),
            'documents' => $application->documents,
            'included' => null,
            'travel' => null,
            'taPlan' => null,
            'claim' => ClaimsDetail::where('application_id', $application->id)->first(),
            'publication' => null,
            'generatedBy' => $this->officer,
        ])->render();

        $this->assertMatchesRegularExpression('/<td>Lecturer\/Supervisor<\/td>\s*<td>Endorse<\/td>\s*<td>Endorsed<\/td>/', $html);
        $this->assertMatchesRegularExpression('/<td>Chair of Department<\/td>\s*<td>Endorse<\/td>\s*<td>Endorsed<\/td>/', $html);
        $this->assertMatchesRegularExpression('/<td>Non-Executive CGS<\/td>\s*<td>Review<\/td>\s*<td>Awaiting review<\/td>/', $html);
        $this->assertMatchesRegularExpression('/<td>Manager CGS<\/td>\s*<td>Approve<\/td>\s*<td>Not reached<\/td>/', $html);
        // Two in the chain, two in the history.
        $this->assertSame(4, substr_count($html, '<td>Endorsed</td>'));
        $this->assertStringNotContainsString('<td>Approved</td>', $html);
    }

    public function test_the_overview_report_and_export_name_the_action(): void
    {
        $application = $this->claim();
        $this->decide($this->supervisor, $application);
        $this->decide($this->chair, $application);

        // Waiting on the CGS officer's own stage: the overview's button says Review.
        $this->actingAs($this->officer)->get(route('norhanis.overview', ['module' => 'claims']))
            ->assertOk()
            ->assertSee('Awaiting review')
            ->assertSee('class="btn-secondary">Review</a>', false);

        $this->actingAs($this->officer)->get(route('norhanis.report', ['module' => 'claims']))
            ->assertOk()
            ->assertSee('<span class="queue-meta">Endorse</span>', false)
            ->assertSee('<span class="queue-meta">Review</span>', false)
            ->assertSee('<span class="queue-meta">Approve</span>', false);

        $csv = $this->actingAs($this->officer)->get(route('norhanis.report.export', ['module' => 'claims']))->streamedContent();
        $this->assertStringContainsString('"Now With",Awaiting,Submitted', $csv);
        $this->assertStringContainsString('"Non-Executive CGS",Review,', $csv);
    }

    // ---- Travel and Publication queues ----------------------------------------

    public function test_travel_local_and_international_each_end_with_approve(): void
    {
        $dean = $this->dean();
        $director = $this->seniorDirector();

        foreach ([false => $director, true => $dean] as $international => $final) {
            $application = $this->travelApplication((bool) $international);

            $this->assertQueueButton($this->supervisor, 'travel.queue', 'Endorse', 'Travel', $application);
            $this->decideOn('travel.decide', $this->supervisor, $application);
            $this->assertQueueButton($this->chair, 'travel.queue', 'Endorse', 'Travel', $application);
            $this->decideOn('travel.decide', $this->chair, $application);
            $this->assertQueueButton($this->officer, 'travel.queue', 'Review', 'Travel', $application);
            $this->decideOn('travel.decide', $this->officer, $application);
            $this->assertQueueButton($final, 'travel.queue', 'Approve', 'Travel', $application);
            $this->decideOn('travel.decide', $final, $application)
                ->assertSessionHas('status', "Application #{$application->id} approved. This was the final approval.");

            $this->assertSame(['endorsed', 'endorsed', 'reviewed', 'approved'], $application->history()->orderBy('id')->pluck('decision')->all());
        }
    }

    public function test_publication_ends_with_the_senior_director_approving(): void
    {
        $director = $this->seniorDirector();

        $application = Application::create(['student_id' => $this->student->id, 'module_type' => 'publication', 'status' => Application::STATUS_DRAFT]);
        \App\Modules\Norhanis\Models\PublicationDetail::create([
            'application_id' => $application->id,
            'type_of_request' => 'publication_journal',
            'title_of_paper' => 'Reservoir Simulation with Graph Networks',
            'title_of_conference_journal' => 'Journal of Petroleum Science',
            'organizer_publisher' => 'Elsevier',
            'conference_journal_fee' => 2500,
            'currency_type' => 'MYR',
            'cost_centre' => 'cgs_pay_and_claims',
        ]);
        $application = app(WorkflowEngine::class)->submit($application);

        foreach ([[$this->supervisor, 'Endorse'], [$this->chair, 'Endorse'], [$this->officer, 'Review'], [$director, 'Approve']] as [$approver, $label]) {
            $this->assertQueueButton($approver, 'publication.queue', $label, 'Publication', $application);
            $this->decideOn('publication.decide', $approver, $application);
        }

        $this->assertSame(Application::STATUS_APPROVED, $application->fresh()->status);
        $this->assertSame(['endorsed', 'endorsed', 'reviewed', 'approved'], $application->history()->orderBy('id')->pluck('decision')->all());
    }

    // ---- Helpers ---------------------------------------------------------------

    protected function travelApplication(bool $international): Application
    {
        $application = Application::create(['student_id' => $this->student->id, 'module_type' => 'travel', 'status' => Application::STATUS_DRAFT]);

        \App\Modules\Norhanis\Models\TravelDetail::create([
            'application_id' => $application->id,
            'type_of_request' => 'meeting',
            'travel_start_date' => now()->addDays(40),
            'travel_end_date' => now()->addDays(42),
            'duration_days' => 3,
            'reason_for_travel' => 'Workshop',
            'destination_address' => $international ? 'Tokyo' : 'Kuala Lumpur',
            'is_international' => $international,
            'contact_person_email' => 'contact@utp.edu.my',
            'is_ga_gra' => false,
        ]);

        return app(WorkflowEngine::class)->submit($application);
    }

    protected function assertQueueButton(User $approver, string $queue, string $label, string $module, Application $application): void
    {
        $stage = $application->fresh()->currentStage();

        $html = $this->actingAs($approver)->get(route($queue, ['stage' => $stage->key]))->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/data-confirm="'.$label.' '.$module.' application #'.$application->id.'\?[^"]*">'.$label.'<\/button>/',
            $html,
            "{$module} at {$stage->label} should offer {$label}",
        );
        $this->assertStringContainsString('">Reject</button>', $html);
    }

    protected function decideOn(string $route, User $approver, Application $application)
    {
        return $this->actingAs($approver)
            ->from(route(str_replace('.decide', '.queue', $route)))
            ->post(route($route, $application), ['decision' => 'approve'])
            ->assertSessionHasNoErrors();
    }


    /** A submitted claim for an approved Pay and Claims journal, at the Supervisor. */
    protected function claim(): Application
    {
        $application = Application::create([
            'student_id' => $this->student->id,
            'module_type' => 'claims_student',
            'status' => Application::STATUS_DRAFT,
        ]);

        ClaimsDetail::create([
            'application_id' => $application->id,
            'publication_application_id' => $this->approvedPublication($this->student)->id,
            'bank_name' => 'Maybank',
            'bank_account_no' => '1234567890',
            'total_claims' => 900,
        ]);

        $application = app(WorkflowEngine::class)->submit($application);
        app(ApproverNotifier::class)->arrived($application);

        return $application;
    }

    protected function decide(User $approver, Application $application)
    {
        return $this->actingAs($approver)
            ->from(route('claims.queue'))
            ->post(route('claims.decide', $application), ['decision' => 'approve'])
            ->assertSessionHasNoErrors();
    }

    protected function assertAwaitingSubject(User $approver, string $subject, string $line): void
    {
        Notification::assertSentTo($approver, ApplicationAwaitingAction::class, function ($notification) use ($approver, $subject, $line) {
            $mail = $notification->toMail($approver);

            return $mail->subject === $subject
                && str_contains(implode(' ', $mail->introLines), $line)
                && $mail->actionText === 'Open the application';
        });
    }
}
