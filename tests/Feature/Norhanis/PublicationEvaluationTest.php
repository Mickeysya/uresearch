<?php

namespace Tests\Feature\Norhanis;

use App\Modules\Core\Models\Application;
use App\Modules\Norhanis\Models\PublicationEvaluation;
use App\Modules\Norhanis\Notifications\AuthorshipCertificationSigned;
use App\Modules\Norhanis\Notifications\ApplicationAwaitingAction;
use App\Modules\Norhanis\Notifications\PaperEvaluationReceived;
use App\Modules\Norhanis\Notifications\PaperEvaluationRequested;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Norhanis\Concerns\FilesPublications;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * The online Paper Evaluation Sheet (UTP/CGS/040) that replaced the
 * downloaded one.
 *
 * The rule under test: a publication reaches the supervisor only once every
 * internal reviewer's sheet is settled -- recommended, or recommended with
 * corrections that the student has answered. A rejection stops it.
 */
class PublicationEvaluationTest extends TestCase
{
    use FilesPublications;
    use MakesUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Notification::fake();
    }

    public function test_each_reviewer_is_emailed_a_link_and_can_open_the_paper_from_it(): void
    {
        $this->file($this->student());
        $evaluation = PublicationEvaluation::where('position', 1)->sole();

        Notification::assertSentOnDemandTimes(PaperEvaluationRequested::class, 2);
        Notification::assertSentOnDemand(
            PaperEvaluationRequested::class,
            fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'siti.mariam@utp.edu.my',
        );

        $this->get($this->evaluationUrl($evaluation))
            ->assertOk()
            ->assertSee('Reservoir Simulation with Graph Networks')
            ->assertSee('Submit evaluation');

        $this->get($this->evaluationUrl($evaluation, 'publication.evaluate.paper'))->assertOk()->assertDownload('manuscript.pdf');
    }

    public function test_the_reviewer_pages_need_the_signed_link(): void
    {
        $this->file($this->student());
        $evaluation = PublicationEvaluation::where('position', 1)->sole();

        $this->get(route('publication.evaluate', $evaluation))->assertForbidden();
        $this->get(route('publication.evaluate.paper', $evaluation))->assertForbidden();
        $this->post(route('publication.evaluate.submit', $evaluation), $this->sheet())->assertForbidden();

        // A link signed for the page is not a link for the paper.
        $pageLink = $this->evaluationUrl($evaluation);
        $this->get(str_replace("/{$evaluation->id}?", "/{$evaluation->id}/paper?", $pageLink))->assertForbidden();

        $this->assertFalse($evaluation->refresh()->isSubmitted());
    }

    public function test_the_last_recommendation_files_the_sheets_and_submits_the_application(): void
    {
        $supervisor = $this->supervisor();
        $student = $this->student(attributes: ['supervisor_id' => $supervisor->id]);
        $application = $this->file($student);
        [$first, $second] = PublicationEvaluation::orderBy('position')->get()->all();

        $this->post($this->evaluationUrl($first, 'publication.evaluate.submit'), $this->sheet())
            ->assertSessionHasNoErrors();

        $first->refresh();
        $this->assertTrue($first->isSubmitted());
        $this->assertSame(3, $first->methodology_rating);
        $this->assertSame(Application::STATUS_DRAFT, $application->refresh()->status);
        $this->assertSame(1, $application->documents()->where('doc_type', 'Paper Evaluation Sheet (Reviewer 1)')->count());

        $this->post($this->evaluationUrl($second, 'publication.evaluate.submit'), $this->sheet())
            ->assertSessionHasNoErrors();

        $application->refresh();
        $this->assertSame(Application::STATUS_PENDING, $application->status);
        $this->assertSame('supervisor', $application->current_stage);
        $this->assertSame(2, $application->documents()->where('doc_type', 'like', 'Paper Evaluation Sheet%')->count());
        $this->assertSame(1, $application->documents()->where('doc_type', 'Authorship Certification Form')->count());
        Notification::assertSentToTimes($student, PaperEvaluationReceived::class, 2);
        // Submitted by the last sheet, so the supervisor is told it is waiting.
        Notification::assertSentToTimes($supervisor, ApplicationAwaitingAction::class, 1);
    }

    public function test_a_sheet_needs_every_rating_a_recommendation_and_a_signature(): void
    {
        $this->file($this->student());
        $evaluation = PublicationEvaluation::where('position', 1)->sole();
        $submit = fn (array $sheet) => $this->post($this->evaluationUrl($evaluation, 'publication.evaluate.submit'), $sheet);

        $submit([])->assertSessionHasErrors([
            'objective_rating', 'originality_rating', 'methodology_rating', 'results_rating',
            'recommendation', 'confirms', 'signature',
        ]);

        $submit($this->sheet(['results_rating' => 6]))->assertSessionHasErrors('results_rating');

        // Corrections or a rejection must say what is wrong.
        $submit($this->sheet(['recommendation' => PublicationEvaluation::CORRECTIONS]))
            ->assertSessionHasErrors('additional_comments');

        $this->assertFalse($evaluation->refresh()->isSubmitted());
    }

    public function test_a_sheet_cannot_be_submitted_twice(): void
    {
        $this->file($this->student());
        $evaluation = PublicationEvaluation::where('position', 1)->sole();
        $url = $this->evaluationUrl($evaluation, 'publication.evaluate.submit');

        $this->post($url, $this->sheet());
        $this->post($url, $this->sheet(['objective_rating' => 1, 'recommendation' => PublicationEvaluation::REJECTED, 'additional_comments' => 'Changed my mind.']))
            ->assertRedirect();

        $evaluation->refresh();
        $this->assertSame(4, $evaluation->objective_rating);
        $this->assertSame(PublicationEvaluation::RECOMMENDED, $evaluation->recommendation);
    }

    public function test_corrections_hold_the_application_until_the_student_responds(): void
    {
        $student = $this->student();
        $application = $this->file($student);
        [$first, $second] = PublicationEvaluation::orderBy('position')->get()->all();

        $this->post($this->evaluationUrl($first, 'publication.evaluate.submit'), $this->sheet());
        $this->post($this->evaluationUrl($second, 'publication.evaluate.submit'), $this->sheet([
            'recommendation' => PublicationEvaluation::CORRECTIONS,
            'additional_comments' => 'Justify the sampling interval.',
        ]));

        $this->assertSame(Application::STATUS_DRAFT, $application->refresh()->status);

        $respond = route('publication.evaluations.respond', [$application, $second]);

        $this->actingAs($student)->post($respond, ['response' => 'Added in section 3.'])
            ->assertSessionHasErrors('corrected_paper');

        $this->actingAs($student)->post($respond, [
            'response' => 'Added in section 3.',
            'corrected_paper' => $this->pdf('corrected.pdf'),
        ])->assertSessionHasNoErrors()->assertRedirect(route('publication.sign-offs', $application));

        $application->refresh();
        $this->assertSame('Added in section 3.', $second->refresh()->response);
        $this->assertSame(Application::STATUS_PENDING, $application->status);
        // One filed with the application (now required), and the one sent
        // in answer to this reviewer's corrections.
        $this->assertSame(2, $application->documents()->where('doc_type', 'Corrected Conference/Journal Paper')->count());
        $this->assertTrue($application->documents()->where('original_name', 'corrected.pdf')->exists());
    }

    public function test_a_rejection_stops_the_application_until_someone_else_recommends_it(): void
    {
        $student = $this->student();
        $application = $this->file($student);
        [$first, $second] = PublicationEvaluation::orderBy('position')->get()->all();

        $this->post($this->evaluationUrl($first, 'publication.evaluate.submit'), $this->sheet());
        $this->post($this->evaluationUrl($second, 'publication.evaluate.submit'), $this->sheet([
            'recommendation' => PublicationEvaluation::REJECTED,
            'additional_comments' => 'The method does not support the conclusion.',
        ]));

        $this->assertSame(Application::STATUS_DRAFT, $application->refresh()->status);

        // A favourable sheet cannot be swapped out; a rejected one can.
        $replacement = ['reviewer_name' => 'Dr. Tan Wei Ming', 'reviewer_email' => 'tan.weiming@utp.edu.my'];

        $this->actingAs($student)
            ->post(route('publication.evaluations.replace', [$application, $first]), $replacement)
            ->assertForbidden();

        $this->actingAs($student)
            ->post(route('publication.evaluations.replace', [$application, $second]), $replacement)
            ->assertSessionHasNoErrors();

        auth()->logout();

        // The rejection is kept, and its link no longer opens the paper.
        $this->assertTrue($second->refresh()->isReplaced());
        $this->assertSame(PublicationEvaluation::REJECTED, $second->recommendation);
        $this->get($this->evaluationUrl($second, 'publication.evaluate.paper'))->assertForbidden();

        $third = PublicationEvaluation::where('reviewer_email', 'tan.weiming@utp.edu.my')->sole();
        $this->assertSame(2, $third->position);

        $this->post($this->evaluationUrl($third, 'publication.evaluate.submit'), $this->sheet())
            ->assertSessionHasNoErrors();

        $this->assertSame(Application::STATUS_PENDING, $application->refresh()->status);
    }

    /**
     * Application #949, replayed: one reviewer asks for corrections, another
     * rejects. Replying to the corrections is not enough on its own, so the
     * student is told what is still holding it, and once the rejected
     * reviewer is replaced by one who recommends, it goes to the supervisor.
     */
    public function test_a_reply_says_what_still_holds_the_application_until_a_rejection_is_replaced(): void
    {
        $student = $this->student();
        $application = $this->file($student);
        [$first, $second] = PublicationEvaluation::orderBy('position')->get()->all();

        $this->post($this->evaluationUrl($first, 'publication.evaluate.submit'), $this->sheet([
            'recommendation' => PublicationEvaluation::CORRECTIONS,
            'additional_comments' => 'Justify the sampling interval.',
        ]));
        $this->post($this->evaluationUrl($second, 'publication.evaluate.submit'), $this->sheet([
            'recommendation' => PublicationEvaluation::REJECTED,
            'additional_comments' => 'The method does not support the conclusion.',
        ]));

        $rejected = "Reviewer 2 ({$second->reviewer_name}) rejected the paper. Ask another reviewer.";

        // Before replying, the sign-offs page names both.
        $this->actingAs($student)->get(route('publication.sign-offs', $application))
            ->assertOk()
            ->assertSee('Not sent to your supervisor yet, because:')
            ->assertSee("Reviewer 1 ({$first->reviewer_name}) asked for corrections.", false)
            ->assertSee($rejected, false);

        // The reply is saved, but the rejection still holds it -- and the student is told so.
        $this->from(route('publication.sign-offs', $application))
            ->post(route('publication.evaluations.respond', [$application, $first]), [
                'response' => 'Added in section 3.',
                'corrected_paper' => $this->pdf('corrected.pdf'),
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Response saved. Not sent to your supervisor yet: '.$rejected);

        $this->assertSame(Application::STATUS_DRAFT, $application->refresh()->status);
        $this->get(route('publication.sign-offs', $application))
            ->assertSee($rejected, false)
            ->assertDontSee('asked for corrections.');

        // Replace the rejecting reviewer; the new one recommends.
        $this->post(route('publication.evaluations.replace', [$application, $second]), [
            'reviewer_name' => 'Dr. Tan Wei Ming', 'reviewer_email' => 'tan.weiming@utp.edu.my',
        ])->assertSessionHasNoErrors();

        $this->get(route('publication.sign-offs', $application))
            ->assertSee('Reviewer 2 (Dr. Tan Wei Ming) has not returned the Paper Evaluation Sheet yet.', false);

        auth()->logout();
        $third = PublicationEvaluation::where('reviewer_email', 'tan.weiming@utp.edu.my')->sole();
        $this->post($this->evaluationUrl($third, 'publication.evaluate.submit'), $this->sheet())
            ->assertSessionHasNoErrors();

        // Now every sheet is complete: submitted to the supervisor, nothing left to list.
        $this->assertSame(Application::STATUS_PENDING, $application->refresh()->status);
        $this->assertSame('supervisor', $application->current_stage);
        $this->actingAs($student)->get(route('publication.sign-offs', $application))
            ->assertSee('Every sign-off is in')
            ->assertDontSee('Not sent to your supervisor yet');
    }

    public function test_the_outstanding_list_names_unsigned_co_authors_and_unreturned_sheets(): void
    {
        $student = $this->student();
        $application = $this->file($student, ['authors' => [
            ['author_name' => 'Ahmad Danial', 'email' => 'student@test.my'],
            ['author_name' => 'Prof. Li Wen', 'email' => 'li.wen@example.edu'],
        ]]);

        $this->actingAs($student)->get(route('publication.sign-offs', $application))
            ->assertSee('Prof. Li Wen has not signed the Authorship Certification yet.')
            ->assertSee('Reviewer 1 (Dr. Siti Mariam) has not returned the Paper Evaluation Sheet yet.')
            ->assertSee('Reviewer 2 (Dr. Faridah Osman) has not returned the Paper Evaluation Sheet yet.')
            ->assertDontSee('Ahmad Danial has not signed');
    }

    public function test_a_reviewer_can_decline_and_the_student_asks_someone_else(): void
    {
        $student = $this->student();
        $application = $this->file($student);
        $evaluation = PublicationEvaluation::where('position', 1)->sole();

        $this->post($this->evaluationUrl($evaluation, 'publication.evaluate.decline'), [])
            ->assertSessionHasErrors('decline_reason');

        $this->post($this->evaluationUrl($evaluation, 'publication.evaluate.decline'), ['decline_reason' => 'On sabbatical.'])
            ->assertSessionHasNoErrors();

        $this->assertTrue($evaluation->refresh()->isDeclined());
        Notification::assertSentTo($student, PaperEvaluationReceived::class);

        // Having declined, the same link cannot then submit a sheet.
        $this->post($this->evaluationUrl($evaluation, 'publication.evaluate.submit'), $this->sheet());
        $this->assertFalse($evaluation->refresh()->isSubmitted());

        $replace = route('publication.evaluations.replace', [$application, $evaluation]);

        // Not an author, not someone already reviewing, and UTP staff.
        $this->actingAs($student)->post($replace, ['reviewer_name' => 'Dr. Faridah Osman', 'reviewer_email' => 'faridah.osman@utp.edu.my'])
            ->assertSessionHasErrors('reviewer_email');
        $this->actingAs($student)->post($replace, ['reviewer_name' => 'Prof. Outside', 'reviewer_email' => 'outside@example.edu'])
            ->assertSessionHasErrors('reviewer_email');

        $this->actingAs($student)->post($replace, ['reviewer_name' => 'Dr. Tan Wei Ming', 'reviewer_email' => 'tan.weiming@utp.edu.my'])
            ->assertSessionHasNoErrors();

        Notification::assertSentOnDemand(
            PaperEvaluationRequested::class,
            fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'tan.weiming@utp.edu.my',
        );
    }

    public function test_the_emails_to_the_student_carry_the_generated_pdfs(): void
    {
        // Notification::fake() never builds the message, so a broken
        // attachment call would go unnoticed everywhere else in this file.
        $student = $this->student();
        $application = $this->file($student);
        $this->completeEvaluations($application);

        $evaluation = PublicationEvaluation::where('position', 1)->sole();
        $sheet = $application->documents()->where('doc_type', $evaluation->sheetLabel())->sole();
        $form = $application->documents()->where('doc_type', 'Authorship Certification Form')->sole();

        $received = (new PaperEvaluationReceived($application, $evaluation, $sheet, true))->toMail($student);
        $signed = (new AuthorshipCertificationSigned($application, 'Prof. Li Wen', 2, 2, $form))->toMail($student);

        $this->assertCount(1, $received->rawAttachments);
        $this->assertCount(1, $signed->rawAttachments);
        $this->assertStringContainsString('Recommended', $received->subject);
    }

    public function test_only_the_applicant_can_remind_replace_or_respond(): void
    {
        $application = $this->file($this->student());
        $evaluation = PublicationEvaluation::where('position', 1)->sole();
        $other = $this->student('22001002', 'other@test.my');

        foreach (['remind', 'replace', 'respond'] as $action) {
            $this->actingAs($other)
                ->post(route("publication.evaluations.{$action}", [$application, $evaluation]))
                ->assertForbidden();
        }

        // And an evaluation cannot be reached through someone else's application.
        $otherApplication = $this->file($other, [
            'authors' => [['author_name' => 'Other Student', 'email' => 'other@test.my']],
        ]);

        $this->actingAs($other)
            ->post(route('publication.evaluations.remind', [$otherApplication, $evaluation]))
            ->assertNotFound();
    }
}
