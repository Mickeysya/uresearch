<?php

namespace Tests\Feature\Norhanis;

use App\Modules\Core\Models\Application;
use App\Modules\Norhanis\Models\PublicationAuthor;
use App\Modules\Norhanis\Notifications\AuthorshipCertificationRequested;
use App\Modules\Norhanis\Notifications\AuthorshipCertificationSigned;
use App\Modules\Norhanis\Support\PublicationSignOffs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * The online Authorship Certification that replaced the downloaded form.
 *
 * The rule under test: a publication stays a draft, out of every approver's
 * queue, until each listed author has signed -- the student on the form, the
 * co-authors from a signed link that needs no account.
 */
class PublicationCertificationTest extends TestCase
{
    use MakesUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Notification::fake();
    }

    public function test_a_sole_author_signs_on_the_form_and_is_submitted_at_once(): void
    {
        $this->actingAs($this->student())
            ->post(route('publication.store'), $this->payload())
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('applications.index'));

        $application = Application::where('module_type', 'publication')->sole();

        $this->assertSame(Application::STATUS_PENDING, $application->status);
        $this->assertSame('supervisor', $application->current_stage);
        $this->assertSame(1, $application->documents()->where('doc_type', 'Authorship Certification Form')->count());
        Notification::assertNothingSent();
    }

    public function test_with_a_co_author_it_waits_as_a_draft_and_emails_them_a_link(): void
    {
        $this->actingAs($this->student())
            ->post(route('publication.store'), $this->payload($this->withCoAuthor()))
            ->assertSessionHasNoErrors();

        $application = Application::where('module_type', 'publication')->sole();

        $this->assertSame(Application::STATUS_DRAFT, $application->status);
        $this->assertNull($application->current_stage);
        $this->assertSame(0, $application->documents()->where('doc_type', 'Authorship Certification Form')->count());

        Notification::assertSentOnDemand(
            AuthorshipCertificationRequested::class,
            fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'li.wen@example.edu',
        );
        Notification::assertSentOnDemandTimes(AuthorshipCertificationRequested::class, 1);
    }

    public function test_the_last_signature_generates_the_form_and_submits_the_application(): void
    {
        $student = $this->student();
        $coAuthor = $this->fileWithCoAuthor($student);

        $this->get($this->signingUrl($coAuthor))->assertOk()->assertSee('Reservoir Simulation with Graph Networks');

        $this->post($this->signingUrl($coAuthor, 'publication.certify.sign'), [
            'certifies' => '1',
            'has_conflict' => 'yes',
            'conflict_disclosure' => 'Consultant to the sponsor.',
            'signature' => PublicationTest::SIGNATURE,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $coAuthor->refresh();
        $application = Application::where('module_type', 'publication')->sole();

        $this->assertTrue($coAuthor->hasSigned());
        $this->assertSame('Consultant to the sponsor.', $coAuthor->conflict_disclosure);
        $this->assertSame(Application::STATUS_PENDING, $application->status);
        $this->assertSame(1, $application->documents()->where('doc_type', 'Authorship Certification Form')->count());
        Notification::assertSentTo($student, AuthorshipCertificationSigned::class);
    }

    public function test_the_signing_page_needs_the_signed_link(): void
    {
        $coAuthor = $this->fileWithCoAuthor($this->student());

        $this->get(route('publication.certify', $coAuthor))->assertForbidden();
        $this->post(route('publication.certify.sign', $coAuthor), [
            'certifies' => '1', 'has_conflict' => 'no', 'signature' => PublicationTest::SIGNATURE,
        ])->assertForbidden();

        $this->assertFalse($coAuthor->refresh()->hasSigned());
    }

    public function test_a_signature_must_be_a_real_png_and_the_box_must_be_ticked(): void
    {
        $coAuthor = $this->fileWithCoAuthor($this->student());

        $this->post($this->signingUrl($coAuthor, 'publication.certify.sign'), [
            'has_conflict' => 'no',
            'signature' => 'data:image/png;base64,'.base64_encode('<script>alert(1)</script>'),
        ])->assertSessionHasErrors(['certifies', 'signature']);

        $this->assertFalse($coAuthor->refresh()->hasSigned());
    }

    public function test_a_link_cannot_be_used_to_sign_twice(): void
    {
        $coAuthor = $this->fileWithCoAuthor($this->student());
        $sign = fn (string $has) => $this->post($this->signingUrl($coAuthor, 'publication.certify.sign'), [
            'certifies' => '1', 'has_conflict' => $has, 'conflict_disclosure' => 'Changed later.',
            'signature' => PublicationTest::SIGNATURE,
        ]);

        $sign('no');
        $signedAt = $coAuthor->refresh()->signed_at;

        $sign('yes')->assertRedirect();

        $this->assertNull($coAuthor->refresh()->conflict_disclosure);
        $this->assertEquals($signedAt, $coAuthor->signed_at);
        $this->assertSame(1, Application::sole()->documents()->where('doc_type', 'Authorship Certification Form')->count());
    }

    public function test_the_student_must_list_themselves_and_give_each_author_their_own_email(): void
    {
        $student = $this->student();

        $this->actingAs($student)
            ->post(route('publication.store'), $this->payload([
                'authors' => [['author_name' => 'Prof. Li Wen', 'email' => 'li.wen@example.edu']],
            ]))
            ->assertSessionHasErrors('authors');

        $this->actingAs($student)
            ->post(route('publication.store'), $this->payload([
                'authors' => [
                    ['author_name' => 'Ahmad Danial', 'email' => 'student@test.my'],
                    ['author_name' => 'Prof. Li Wen', 'email' => 'STUDENT@test.my'],
                ],
            ]))
            ->assertSessionHasErrors('authors.0.email');

        $this->actingAs($student)
            ->post(route('publication.store'), $this->payload(['applicant_signature' => 'not-an-image']))
            ->assertSessionHasErrors('applicant_signature');

        $this->assertSame(0, Application::count());
    }

    public function test_only_the_applicant_sees_the_sign_offs_and_can_send_a_reminder(): void
    {
        $student = $this->student();
        $coAuthor = $this->fileWithCoAuthor($student);
        $application = Application::sole();
        $other = $this->student('22001002', 'other@test.my');

        $this->actingAs($other)->get(route('publication.sign-offs', $application))->assertForbidden();
        $this->actingAs($other)
            ->post(route('publication.authors.remind', [$application, $coAuthor]))
            ->assertForbidden();

        $this->actingAs($student)->get(route('publication.sign-offs', $application))
            ->assertOk()
            ->assertSee('1 of 2 authors have signed')
            ->assertSee('li.wen@example.edu');

        $this->actingAs($student)
            ->post(route('publication.authors.remind', [$application, $coAuthor]))
            ->assertSessionHas('status');

        // The first invitation, then the reminder.
        Notification::assertSentOnDemandTimes(AuthorshipCertificationRequested::class, 2);
    }

    /** File an application with one co-author and return that co-author's row. */
    protected function fileWithCoAuthor($student): PublicationAuthor
    {
        $this->actingAs($student)
            ->post(route('publication.store'), $this->payload($this->withCoAuthor()))
            ->assertSessionHasNoErrors();

        // The co-author has no account, so the rest of the test is a guest.
        auth()->logout();

        return PublicationAuthor::where('email', 'li.wen@example.edu')->sole();
    }

    protected function signingUrl(PublicationAuthor $author, string $route = 'publication.certify'): string
    {
        return $route === 'publication.certify'
            ? app(PublicationSignOffs::class)->signingUrl($author)
            : URL::signedRoute($route, ['author' => $author], absolute: false);
    }

    protected function withCoAuthor(): array
    {
        return ['authors' => [
            ['author_name' => 'Ahmad Danial', 'email' => 'student@test.my'],
            ['author_name' => 'Prof. Li Wen', 'email' => 'li.wen@example.edu', 'organisation' => 'Partner University'],
        ]];
    }

    /** A complete, valid journal application. */
    protected function payload(array $overrides = []): array
    {
        $documents = [
            'invoice', 'index_proof', 'turnitin_report', 'letter_of_acceptance',
            'conference_journal_details', 'response_to_reviewer_comment',
            'corrected_conference_journal_paper', 'paper_evaluation_sheet_1', 'paper_evaluation_sheet_2',
        ];

        return $overrides + [
            'type_of_request' => 'publication_journal',
            'title_of_paper' => 'Reservoir Simulation with Graph Networks',
            'title_of_conference_journal' => 'Journal of Petroleum Science',
            'organizer_publisher' => 'Elsevier',
            'conference_journal_fee' => '2500.00',
            'currency_type' => 'MYR',
            'cost_centre' => 'CGS(015BD1-001)',
            'conference_start_date' => now()->addDays(30)->toDateString(),
            'conference_end_date' => now()->addDays(32)->toDateString(),
            'authors' => [['author_name' => 'Ahmad Danial', 'email' => 'student@test.my']],
            'applicant_certifies' => '1',
            'applicant_signature' => PublicationTest::SIGNATURE,
        ] + array_combine($documents, array_map(
            fn ($field) => UploadedFile::fake()->create($field.'.pdf', 40, 'application/pdf'),
            $documents,
        ));
    }
}
