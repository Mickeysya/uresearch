<?php

namespace Tests\Feature\Norhanis;

use App\Modules\Core\Models\Application;
use App\Modules\Norhanis\Models\PublicationAuthor;
use App\Modules\Norhanis\Notifications\AuthorshipCertificationRequested;
use App\Modules\Norhanis\Notifications\AuthorshipCertificationSigned;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Norhanis\Concerns\FilesPublications;
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
    use FilesPublications;
    use MakesUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Notification::fake();
    }

    public function test_the_student_signs_on_the_form_and_co_authors_are_emailed_a_link(): void
    {
        $application = $this->file($this->student(), $this->withCoAuthor());

        $this->assertSame(Application::STATUS_DRAFT, $application->status);
        $this->assertNull($application->current_stage);
        $this->assertTrue(PublicationAuthor::where('email', 'student@test.my')->sole()->hasSigned());
        $this->assertSame(0, $application->documents()->where('doc_type', 'Authorship Certification Form')->count());

        // Only the co-author is asked: the student has already signed.
        Notification::assertSentOnDemand(
            AuthorshipCertificationRequested::class,
            fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'li.wen@example.edu',
        );
        Notification::assertSentOnDemandTimes(AuthorshipCertificationRequested::class, 1);
    }

    public function test_the_last_signature_generates_the_form_and_submits_the_application(): void
    {
        $student = $this->student();
        $application = $this->file($student, $this->withCoAuthor());
        $coAuthor = PublicationAuthor::where('email', 'li.wen@example.edu')->sole();

        $this->completeEvaluations($application);
        $this->assertSame(Application::STATUS_DRAFT, $application->refresh()->status);

        $this->get($this->certifyUrl($coAuthor))->assertOk()->assertSee('Reservoir Simulation with Graph Networks');

        $this->post($this->certifyUrl($coAuthor, 'publication.certify.sign'), [
            'certifies' => '1',
            'has_conflict' => 'yes',
            'conflict_disclosure' => 'Consultant to the sponsor.',
            'signature' => self::SIGNATURE,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $coAuthor->refresh();
        $application->refresh();

        $this->assertTrue($coAuthor->hasSigned());
        $this->assertSame('Consultant to the sponsor.', $coAuthor->conflict_disclosure);
        $this->assertSame(Application::STATUS_PENDING, $application->status);
        $this->assertSame('supervisor', $application->current_stage);
        $this->assertSame(1, $application->documents()->where('doc_type', 'Authorship Certification Form')->count());
        Notification::assertSentTo($student, AuthorshipCertificationSigned::class);
    }

    public function test_signatures_alone_do_not_submit_while_an_evaluation_is_outstanding(): void
    {
        $application = $this->file($this->student(), $this->withCoAuthor());
        $coAuthor = PublicationAuthor::where('email', 'li.wen@example.edu')->sole();

        $this->post($this->certifyUrl($coAuthor, 'publication.certify.sign'), [
            'certifies' => '1', 'has_conflict' => 'no', 'signature' => self::SIGNATURE,
        ])->assertSessionHasNoErrors();

        $this->assertTrue($coAuthor->refresh()->hasSigned());
        $this->assertSame(Application::STATUS_DRAFT, $application->refresh()->status);
    }

    public function test_the_signing_page_needs_the_signed_link(): void
    {
        $this->file($this->student(), $this->withCoAuthor());
        $coAuthor = PublicationAuthor::where('email', 'li.wen@example.edu')->sole();

        $this->get(route('publication.certify', $coAuthor))->assertForbidden();
        $this->post(route('publication.certify.sign', $coAuthor), [
            'certifies' => '1', 'has_conflict' => 'no', 'signature' => self::SIGNATURE,
        ])->assertForbidden();

        $this->assertFalse($coAuthor->refresh()->hasSigned());
    }

    public function test_a_signature_must_be_a_real_png_and_the_box_must_be_ticked(): void
    {
        $this->file($this->student(), $this->withCoAuthor());
        $coAuthor = PublicationAuthor::where('email', 'li.wen@example.edu')->sole();

        $this->post($this->certifyUrl($coAuthor, 'publication.certify.sign'), [
            'has_conflict' => 'no',
            'signature' => 'data:image/png;base64,'.base64_encode('<script>alert(1)</script>'),
        ])->assertSessionHasErrors(['certifies', 'signature']);

        $this->assertFalse($coAuthor->refresh()->hasSigned());
    }

    public function test_a_link_cannot_be_used_to_sign_twice(): void
    {
        $this->file($this->student(), $this->withCoAuthor());
        $coAuthor = PublicationAuthor::where('email', 'li.wen@example.edu')->sole();
        $sign = fn (string $has) => $this->post($this->certifyUrl($coAuthor, 'publication.certify.sign'), [
            'certifies' => '1', 'has_conflict' => $has, 'conflict_disclosure' => 'Changed later.',
            'signature' => self::SIGNATURE,
        ]);

        $sign('no');
        $signedAt = $coAuthor->refresh()->signed_at;

        $sign('yes')->assertRedirect();

        $this->assertNull($coAuthor->refresh()->conflict_disclosure);
        $this->assertEquals($signedAt, $coAuthor->signed_at);
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
        $application = $this->file($student, $this->withCoAuthor());
        $coAuthor = PublicationAuthor::where('email', 'li.wen@example.edu')->sole();
        $other = $this->student('22001002', 'other@test.my');

        $this->actingAs($other)->get(route('publication.sign-offs', $application))->assertForbidden();
        $this->actingAs($other)
            ->post(route('publication.authors.remind', [$application, $coAuthor]))
            ->assertForbidden();

        $this->actingAs($student)->get(route('publication.sign-offs', $application))
            ->assertOk()
            ->assertSee('1 of 2 authors have signed')
            ->assertSee('0 of 2 paper evaluations are complete')
            ->assertSee('li.wen@example.edu');

        $this->actingAs($student)
            ->post(route('publication.authors.remind', [$application, $coAuthor]))
            ->assertSessionHas('status');

        // The first invitation, then the reminder.
        Notification::assertSentOnDemandTimes(AuthorshipCertificationRequested::class, 2);
    }
}
