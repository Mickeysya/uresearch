<?php

namespace Tests\Feature\Norhanis;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Core\Support\Role;
use App\Modules\Norhanis\Models\PublicationDetail;
use App\Modules\Norhanis\Notifications\SplitMemoInstructions;
use App\Modules\Norhanis\Support\PublicationFollowUp;
use App\Modules\Norhanis\Support\SplitMemo;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * The "CGS & Grant - Split Memo" email and the cost-centre lines on the
 * student's page (UAT, 11 Nov 2026). A journal on the Split Memo gets one
 * extra, queued email at final approval -- why, the template, and the CGS
 * contact -- and the same instruction on its View details page. Pay and
 * Claims, LOU, Others and conferences get no extra email; LOU gets a line
 * saying CGS processes it. See Support\SplitMemo.
 */
class SplitMemoTest extends TestCase
{
    use MakesUsers;
    use RefreshDatabase;

    protected User $student;

    /** @var array<int, User> in chain order */
    protected array $approvers;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Notification::fake();

        $supervisor = $this->supervisor();
        $this->student = $this->student(attributes: ['supervisor_id' => $supervisor->id, 'department' => 'Computer & Information Sciences']);
        $this->approvers = [
            $supervisor,
            $this->user(Role::CHAIR, ['email' => 'chair@test.my', 'department' => 'Computer & Information Sciences']),
            $this->cgs(),
            $this->seniorDirector(),
        ];
    }

    public function test_a_split_memo_journal_emails_the_student_once_at_final_approval_with_the_template(): void
    {
        $template = $this->templateFile('docx');
        $application = $this->publication('split_memo');

        // Nothing before the last approver decides.
        $this->decideUpTo($application, 3);
        Notification::assertNotSentTo($this->student, SplitMemoInstructions::class);

        $this->decide($this->approvers[3], $application);
        $this->assertSame(Application::STATUS_APPROVED, $application->fresh()->status);

        Notification::assertSentToTimes($this->student, SplitMemoInstructions::class, 1);
        Notification::assertSentTo($this->student, SplitMemoInstructions::class, function (SplitMemoInstructions $notification) use ($template) {
            $mail = $notification->toMail($this->student);
            $text = implode(' ', $mail->introLines);

            return $notification instanceof ShouldQueue
                && str_contains($text, 'Because this application is funded by CGS and a grant (CGS & Grant - Split Memo), you must submit a signed memo to CGS.')
                && str_contains($text, 'You can submit the signed memo to CGS at this email. norshahira@utp.edu.my')
                && str_contains($text, 'The memo template is attached to this email.')
                && count($mail->attachments) === 1
                && $mail->attachments[0]['file'] === $template
                && $mail->attachments[0]['options']['as'] === 'split-memo-template.docx';
        });

        $this->assertNotNull(PublicationDetail::sole()->follow_up_emailed_at);
    }

    public function test_it_is_sent_once_even_if_asked_again(): void
    {
        $application = $this->publication('split_memo');
        $this->decideUpTo($application, 4);

        // A repeated decision is refused by the engine, and a second call
        // finds the send already claimed.
        $this->decide($this->approvers[3], $application, expectChange: false);
        app(PublicationFollowUp::class)->afterFinalApproval($application->fresh());
        app(PublicationFollowUp::class)->afterFinalApproval($application->fresh());

        Notification::assertSentToTimes($this->student, SplitMemoInstructions::class, 1);
    }

    public function test_no_split_memo_email_for_the_other_cost_centres_or_a_conference(): void
    {
        $cases = [
            ['cgs_pay_and_claims', 'publication_journal'],
            ['cgs_letter_of_undertaking', 'publication_journal'],
            ['others', 'publication_journal'],
            // A conference is stored as "Others"; even a split_memo value
            // written straight to the row would not count, journals only.
            ['split_memo', 'publication_conference'],
        ];

        foreach ($cases as [$costCentre, $type]) {
            $application = $this->publication($costCentre, $type);
            $this->decideUpTo($application, 4);
            $this->assertSame(Application::STATUS_APPROVED, $application->fresh()->status, $costCentre.' '.$type);
        }

        Notification::assertNotSentTo($this->student, SplitMemoInstructions::class);
        // Pay and Claims and LOU get their own follow-up instead (see
        // ClaimsLessClickingTest); "Others" and the conference get none.
        $this->assertSame(2, PublicationDetail::whereNotNull('follow_up_emailed_at')->count());
    }

    public function test_a_rejection_at_the_last_stage_sends_nothing(): void
    {
        $application = $this->publication('split_memo');
        $this->decideUpTo($application, 3);

        $this->actingAs($this->approvers[3])
            ->post(route('publication.decide', $application), ['decision' => 'reject', 'remarks' => 'Out of budget.']);

        $this->assertSame(Application::STATUS_REJECTED, $application->fresh()->status);
        Notification::assertNotSentTo($this->student, SplitMemoInstructions::class);
    }

    public function test_a_missing_template_still_sends_the_email_and_logs_a_warning(): void
    {
        config(['norhanis.cgs.split_memo.templates' => [storage_path('framework/testing/no-such-template.docx')]]);
        Log::spy();

        $application = $this->publication('split_memo');
        $this->decideUpTo($application, 4);

        Notification::assertSentTo($this->student, SplitMemoInstructions::class, function (SplitMemoInstructions $notification) {
            $mail = $notification->toMail($this->student);

            return $mail->attachments === []
                && str_contains(implode(' ', $mail->introLines), 'You can submit the signed memo to CGS at this email. norshahira@utp.edu.my');
        });

        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'Split Memo template not found'))->once();
    }

    public function test_a_pdf_template_is_used_when_there_is_no_docx(): void
    {
        $pdf = $this->templateFile('pdf');
        config(['norhanis.cgs.split_memo.templates' => [
            storage_path('framework/testing/split-memo-template.docx'),
            $pdf,
        ]]);

        $this->assertSame($pdf, SplitMemo::template());
    }

    public function test_the_students_page_shows_the_split_memo_instruction_after_final_approval_only(): void
    {
        $application = $this->publication('split_memo');
        $this->decideUpTo($application, 3);

        $this->actingAs($this->student)->get(route('applications.show', $application))
            ->assertOk()
            ->assertDontSee('submit a signed memo to CGS')
            ->assertSee('Senior Director CGS will approve it next.');

        $this->decide($this->approvers[3], $application);

        $this->actingAs($this->student)->get(route('applications.show', $application))
            ->assertOk()
            ->assertSee('Because this application is funded by CGS and a grant, you must submit a signed memo to CGS.')
            ->assertSee('You can submit the signed memo to CGS at this email. norshahira@utp.edu.my');
    }

    public function test_the_students_page_says_cgs_pays_an_lou_and_gives_pay_and_claims_no_memo_line(): void
    {
        $lou = $this->publication('cgs_letter_of_undertaking');
        $this->decideUpTo($lou, 4);

        $this->actingAs($this->student)->get(route('applications.show', $lou))
            ->assertOk()
            ->assertSee('CGS will process the payment directly. No claim is needed.')
            ->assertDontSee('signed memo');

        $pay = $this->publication('cgs_pay_and_claims');
        $this->decideUpTo($pay, 4);

        $this->actingAs($this->student)->get(route('applications.show', $pay))
            ->assertOk()
            ->assertDontSee('signed memo')
            ->assertDontSee('No claim is needed');
    }

    public function test_the_migration_moves_old_conference_cost_centres_into_the_typed_field(): void
    {
        $listed = $this->detailRow('publication_conference', 'cgs_pay_and_claims', null);
        $freeText = $this->detailRow('publication_conference', 'CGS(015BD1-001)', null);
        $blank = $this->detailRow('publication_conference', 'others', null);
        $kept = $this->detailRow('publication_conference', 'others', 'FRG grant');
        $journal = $this->detailRow('publication_journal', 'split_memo', null);

        (require base_path('app/Modules/Norhanis/Database/Migrations/2026_11_11_000100_publication_conference_cost_centre_text_and_split_memo_email.php'))->up();

        $this->assertSame(['others', 'CGS – Pay and Claims'], $this->costCentreOf($listed));
        $this->assertSame(['others', 'CGS(015BD1-001)'], $this->costCentreOf($freeText));
        $this->assertSame(['others', 'Not stated'], $this->costCentreOf($blank));
        $this->assertSame(['others', 'FRG grant'], $this->costCentreOf($kept));
        // Journals are not touched.
        $this->assertSame(['split_memo', null], $this->costCentreOf($journal));

        $this->assertSame('CGS(015BD1-001)', PublicationDetail::find($freeText)->costCentreLabel());
    }

    // ---- Helpers ---------------------------------------------------------------

    /** A publication at the Supervisor's stage, already past its sign-offs. */
    protected function publication(string $costCentre, string $type = 'publication_journal'): Application
    {
        $application = Application::create([
            'student_id' => $this->student->id,
            'module_type' => 'publication',
            'status' => Application::STATUS_DRAFT,
        ]);

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

        return app(WorkflowEngine::class)->submit($application);
    }

    /** The first $count approvers in the chain approve. */
    protected function decideUpTo(Application $application, int $count): void
    {
        foreach (array_slice($this->approvers, 0, $count) as $approver) {
            $this->decide($approver, $application);
        }
    }

    protected function decide(User $approver, Application $application, bool $expectChange = true): void
    {
        $before = $application->fresh()->current_stage;

        $this->actingAs($approver)
            ->from(route('publication.queue'))
            ->post(route('publication.decide', $application), ['decision' => 'approve'])
            ->assertSessionHasNoErrors();

        if ($expectChange) {
            $this->assertNotSame([$before, Application::STATUS_PENDING], [$application->fresh()->current_stage, $application->fresh()->status]);
        }
    }

    protected function templateFile(string $extension): string
    {
        $path = storage_path('framework/testing/split-memo-template.'.$extension);
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, 'template');
        $this->beforeApplicationDestroyed(fn () => @unlink($path));

        config(['norhanis.cgs.split_memo.templates' => [$path]]);

        return $path;
    }

    protected function detailRow(string $type, string $costCentre, ?string $other): int
    {
        $application = Application::create(['student_id' => $this->student->id, 'module_type' => 'publication', 'status' => Application::STATUS_APPROVED]);

        return PublicationDetail::create([
            'application_id' => $application->id,
            'type_of_request' => $type,
            'title_of_paper' => 'Old paper',
            'title_of_conference_journal' => 'Old venue',
            'organizer_publisher' => 'Old publisher',
            'conference_journal_fee' => 100,
            'currency_type' => 'MYR',
            'cost_centre' => $costCentre,
            'cost_centre_other' => $other,
        ])->id;
    }

    /** @return array{0: string, 1: ?string} */
    protected function costCentreOf(int $id): array
    {
        $row = PublicationDetail::find($id);

        return [$row->cost_centre, $row->cost_centre_other];
    }
}
