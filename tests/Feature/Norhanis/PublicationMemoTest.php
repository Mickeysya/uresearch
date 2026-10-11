<?php

namespace Tests\Feature\Norhanis;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Norhanis\Models\PublicationMemo;
use App\Modules\Norhanis\Notifications\MemoSignatureRequested;
use App\Modules\Norhanis\Notifications\MemoSigned;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Norhanis\Concerns\FilesPublications;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * The online Memo Request Split Budget that replaced the downloaded template.
 *
 * The rule under test: a fee above the RM3000 the CGS fund covers needs a
 * memo, signed FROM the supervisor and then THROUGH the centre head before the
 * application is submitted, and approved by the Senior Director with it.
 */
class PublicationMemoTest extends TestCase
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

    public function test_a_fee_above_the_cap_needs_the_grant_details(): void
    {
        $student = $this->supervisedStudent();

        $this->actingAs($student)
            ->post(route('publication.store'), $this->payload(['conference_journal_fee' => '4800.00']))
            ->assertSessionHasErrors([
                'memo.grant_name', 'memo.grant_cost_centre', 'memo.centre_name', 'memo.head_name', 'memo.head_email',
            ])
            // The supervisor is on record, so the student is not asked for one.
            ->assertSessionDoesntHaveErrors(['memo.supervisor_name']);

        // Exactly at the cap is covered in full: no memo.
        $this->actingAs($student)
            ->post(route('publication.store'), $this->payload(['conference_journal_fee' => '3000.00']))
            ->assertSessionHasNoErrors();

        $this->assertNull(PublicationMemo::first());
    }

    public function test_the_split_is_worked_out_by_the_system_and_the_supervisor_is_asked_first(): void
    {
        $this->file($this->supervisedStudent(), $this->aboveCap());

        $memo = PublicationMemo::sole();

        $this->assertSame('4800.00', $memo->fee_myr);
        $this->assertSame('3000.00', $memo->cgs_amount);
        $this->assertSame('1800.00', $memo->grant_amount);
        $this->assertSame(62.5, $memo->percentOf('cgs_amount'));
        $this->assertSame(37.5, $memo->percentOf('grant_amount'));
        $this->assertSame('Dr. Aisyah Rahman', $memo->supervisor_name);
        $this->assertSame('sup@test.my', $memo->supervisor_email);
        $this->assertSame(PublicationMemo::SUPERVISOR, $memo->awaiting());

        Notification::assertSentOnDemandTimes(MemoSignatureRequested::class, 1);
        Notification::assertSentOnDemand(
            MemoSignatureRequested::class,
            fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'sup@test.my',
        );
    }

    public function test_a_fee_in_another_currency_is_judged_by_its_ringgit_amount(): void
    {
        $student = $this->supervisedStudent();
        $usd = ['conference_journal_fee' => '900.00', 'currency_type' => 'USD'];

        $this->actingAs($student)
            ->post(route('publication.store'), $this->payload($usd))
            ->assertSessionHasErrors('fee_in_myr');

        $this->file($student, $usd + ['fee_in_myr' => '4230.00'] + $this->aboveCap());

        $this->assertSame('1230.00', PublicationMemo::sole()->grant_amount);
    }

    public function test_a_student_with_no_supervisor_on_record_names_one(): void
    {
        $student = $this->student();

        $this->actingAs($student)
            ->post(route('publication.store'), $this->payload($this->aboveCap()))
            ->assertSessionHasErrors(['memo.supervisor_name', 'memo.supervisor_email']);

        $memo = $this->aboveCap()['memo'] + ['supervisor_name' => 'Dr. Named Here', 'supervisor_email' => 'named@utp.edu.my'];
        $this->file($student, ['memo' => $memo] + $this->aboveCap());

        $this->assertSame('named@utp.edu.my', PublicationMemo::sole()->supervisor_email);
    }

    public function test_the_memo_is_signed_in_order_and_the_last_signature_submits(): void
    {
        $student = $this->supervisedStudent();
        $application = $this->file($student, $this->aboveCap());
        $this->completeEvaluations($application);
        $memo = PublicationMemo::sole();

        $this->assertSame(Application::STATUS_DRAFT, $application->refresh()->status);

        // The centre head's link does nothing before the supervisor has signed.
        $this->get($this->memoUrl($memo, 'head'))->assertOk()->assertSee('to sign first');
        $this->post($this->memoUrl($memo, 'head', 'publication.memo.sign'), $this->signing());
        $this->assertFalse($memo->refresh()->hasSigned('head'));

        // The supervisor may put the grant details right while signing.
        $this->get($this->memoUrl($memo, 'supervisor'))->assertOk()->assertSee('1,800.00');
        $this->post($this->memoUrl($memo, 'supervisor', 'publication.memo.sign'), $this->signing([
            'grant_name' => 'YUTP-FRG 2/2026', 'grant_cost_centre' => '015LC0-999',
        ]))->assertSessionHasNoErrors();

        $memo->refresh();
        $this->assertTrue($memo->hasSigned('supervisor'));
        $this->assertSame('015LC0-999', $memo->grant_cost_centre);
        $this->assertSame(PublicationMemo::HEAD, $memo->awaiting());
        $this->assertSame(Application::STATUS_DRAFT, $application->refresh()->status);
        Notification::assertSentOnDemand(
            MemoSignatureRequested::class,
            fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'head@utp.edu.my',
        );

        // The centre head cannot rewrite the grant, only sign.
        $this->post($this->memoUrl($memo, 'head', 'publication.memo.sign'), $this->signing(['grant_cost_centre' => 'CHANGED']))
            ->assertSessionHasNoErrors();

        $memo->refresh();
        $application->refresh();
        $this->assertTrue($memo->isSigned());
        $this->assertSame('015LC0-999', $memo->grant_cost_centre);
        $this->assertSame(Application::STATUS_PENDING, $application->status);
        $this->assertSame(1, $application->documents()->where('doc_type', 'Memo Request Split Budget')->count());
        Notification::assertSentToTimes($student, MemoSigned::class, 2);
    }

    public function test_the_signing_pages_need_the_signed_link_for_that_signer(): void
    {
        $this->file($this->supervisedStudent(), $this->aboveCap());
        $memo = PublicationMemo::sole();

        $this->get(route('publication.memo', [$memo, 'supervisor']))->assertForbidden();
        $this->post(route('publication.memo.sign', [$memo, 'supervisor']), $this->signing())->assertForbidden();

        // The centre head's link cannot be turned into the supervisor's.
        $this->get(str_replace('/head?', '/supervisor?', $this->memoUrl($memo, 'head')))->assertForbidden();
        $this->get(str_replace('/head?', '/dean?', $this->memoUrl($memo, 'head')))->assertNotFound();

        $this->assertFalse($memo->refresh()->hasSigned('supervisor'));
    }

    public function test_a_memo_sent_back_is_corrected_and_signed_from_the_start(): void
    {
        $student = $this->supervisedStudent();
        $application = $this->file($student, $this->aboveCap());
        $memo = PublicationMemo::sole();

        $this->post($this->memoUrl($memo, 'supervisor', 'publication.memo.sign'), $this->signing([
            'grant_name' => 'YUTP-FRG 1/2026', 'grant_cost_centre' => '015LC0-312',
        ]));
        $this->post($this->memoUrl($memo, 'head', 'publication.memo.decline'), ['decline_reason' => 'Wrong centre.'])
            ->assertSessionHasNoErrors();

        $memo->refresh();
        $this->assertTrue($memo->isDeclined());
        $this->assertNull($memo->awaiting());

        // Nobody can sign a memo that has been sent back.
        $this->post($this->memoUrl($memo, 'head', 'publication.memo.sign'), $this->signing());
        $this->assertFalse($memo->refresh()->hasSigned('head'));

        $revised = ['head_name' => 'Dr. Right Head', 'head_email' => 'right.head@utp.edu.my', 'centre_name' => 'The Right Centre']
            + $this->aboveCap()['memo'];

        $this->actingAs($this->student('22001002', 'other@test.my'))
            ->post(route('publication.memo.revise', $application), $revised)
            ->assertForbidden();

        $this->actingAs($student)
            ->post(route('publication.memo.revise', $application), $revised)
            ->assertSessionHasNoErrors();

        $memo->refresh();
        $this->assertFalse($memo->isDeclined());
        $this->assertFalse($memo->hasSigned('supervisor'));
        $this->assertSame('right.head@utp.edu.my', $memo->head_email);
        $this->assertSame(PublicationMemo::SUPERVISOR, $memo->awaiting());
    }

    public function test_final_approval_files_the_memo_again_as_approved(): void
    {
        $student = $this->supervisedStudent();
        $application = $this->file($student, $this->aboveCap());
        $this->completeEvaluations($application);
        $memo = PublicationMemo::sole();

        $this->post($this->memoUrl($memo, 'supervisor', 'publication.memo.sign'), $this->signing([
            'grant_name' => 'YUTP-FRG 1/2026', 'grant_cost_centre' => '015LC0-312',
        ]));
        $this->post($this->memoUrl($memo, 'head', 'publication.memo.sign'), $this->signing());

        // Walk the chain to its last stage, then approve it through the
        // controller, which is where the approved memo is filed.
        $engine = app(WorkflowEngine::class);
        $engine->decide($application->refresh(), $student->supervisor, 'approve');
        $engine->decide($application->refresh(), $this->chair(), 'approve');
        $engine->decide($application->refresh(), $this->cgs(), 'approve');

        $this->assertSame(0, $application->documents()->where('doc_type', 'Approved Memo for Utilizing Grant')->count());

        $this->actingAs($this->seniorDirector())
            ->post(route('publication.decide', $application), ['decision' => 'approve'])
            ->assertSessionHasNoErrors();

        $application->refresh();
        $this->assertSame(Application::STATUS_APPROVED, $application->status);
        $this->assertSame(1, $application->documents()->where('doc_type', 'Approved Memo for Utilizing Grant')->count());
    }

    /** A student whose supervisor is on record, so the memo can name them. */
    protected function supervisedStudent()
    {
        return $this->student(attributes: ['supervisor_id' => $this->supervisor()->id]);
    }

    /** A RM4800 fee and the grant details it then needs. */
    protected function aboveCap(): array
    {
        return [
            'conference_journal_fee' => '4800.00',
            'memo' => [
                'grant_name' => 'YUTP-FRG 1/2026',
                'grant_cost_centre' => '015LC0-312',
                'centre_name' => 'Centre for Research in Data Science',
                'head_name' => 'Dr. Centre Head',
                'head_email' => 'Head@utp.edu.my',
            ],
        ];
    }

    protected function memoUrl(PublicationMemo $memo, string $signer, string $route = 'publication.memo'): string
    {
        return $this->signOffs()->signedUrl($route, ['memo' => $memo, 'signer' => $signer]);
    }

    /** What the signing form posts. */
    protected function signing(array $overrides = []): array
    {
        return $overrides + ['agrees' => '1', 'signature' => self::SIGNATURE];
    }
}
