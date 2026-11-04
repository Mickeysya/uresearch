<?php

namespace Tests\Feature\Norhanis;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\ApplicationDecided;
use App\Modules\Core\Support\Role;
use App\Modules\Norhanis\Notifications\ApplicationAwaitingAction;
use App\Modules\Norhanis\Notifications\ApplicationSubmitted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * Everyone in the chain is emailed: the student when they submit and after
 * every decision (Core), and each approver when it reaches their stage --
 * the student's own supervisor, their department's Chair, and everyone in
 * the CGS role after that. See ApproverNotifier.
 */
class ApproverNotificationTest extends TestCase
{
    use MakesUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Notification::fake();
    }

    public function test_every_student_and_approver_is_emailed_along_the_claims_chain(): void
    {
        $supervisor = $this->supervisor();
        $otherSupervisor = $this->supervisor('other.sup@test.my');
        $chair = $this->user(Role::CHAIR, ['email' => 'chair@test.my', 'department' => 'Computer & Information Sciences']);
        $otherChair = $this->user(Role::CHAIR, ['email' => 'chair2@test.my', 'department' => 'Chemical Engineering']);
        $cgs = $this->cgs();
        $manager = $this->user(Role::MANAGER_CGS, ['email' => 'manager@test.my']);
        $student = $this->student(attributes: ['supervisor_id' => $supervisor->id, 'department' => 'Computer & Information Sciences']);

        $this->actingAs($student)->post(route('claims.store'), [
            'bank_name' => 'Maybank',
            'bank_account_no' => '1234567890',
            'total_claims' => 900,
            'receipt' => UploadedFile::fake()->create('receipt.pdf', 100, 'application/pdf'),
            'proof_of_payment' => UploadedFile::fake()->create('payment.pdf', 100, 'application/pdf'),
            'invoice' => UploadedFile::fake()->create('invoice.pdf', 100, 'application/pdf'),
        ])->assertRedirect();
        $application = Application::sole();

        // Submitted: the student's receipt, and their own supervisor only.
        Notification::assertSentTo($student, ApplicationSubmitted::class);
        $this->assertAwaiting($supervisor, 1);
        Notification::assertNotSentTo($otherSupervisor, ApplicationAwaitingAction::class);

        // Each decision: the student hears (Core), the next stage is told.
        $this->decide($supervisor, $application);
        Notification::assertSentTo($student, ApplicationDecided::class);
        $this->assertAwaiting($chair, 1);
        Notification::assertNotSentTo($otherChair, ApplicationAwaitingAction::class);

        $this->decide($chair, $application);
        $this->assertAwaiting($cgs, 1);

        $this->decide($cgs, $application);
        $this->assertAwaiting($manager, 1);

        // The last approval: nobody is left to tell but the student.
        $this->decide($manager, $application);
        $this->assertSame(Application::STATUS_APPROVED, $application->fresh()->status);
        Notification::assertSentToTimes($student, ApplicationDecided::class, 4);
        Notification::assertCount(1 + 4 + 4);
    }

    public function test_a_rejection_tells_the_student_and_no_approver(): void
    {
        $supervisor = $this->supervisor();
        $chair = $this->user(Role::CHAIR, ['email' => 'chair@test.my', 'department' => 'Computer & Information Sciences']);
        $student = $this->student(attributes: ['supervisor_id' => $supervisor->id, 'department' => 'Computer & Information Sciences']);

        $this->actingAs($student)->post(route('claims.store'), [
            'bank_name' => 'Maybank',
            'bank_account_no' => '1234567890',
            'total_claims' => 900,
            'receipt' => UploadedFile::fake()->create('receipt.pdf', 100, 'application/pdf'),
            'proof_of_payment' => UploadedFile::fake()->create('payment.pdf', 100, 'application/pdf'),
            'invoice' => UploadedFile::fake()->create('invoice.pdf', 100, 'application/pdf'),
        ]);

        $this->actingAs($supervisor)
            ->post(route('claims.decide', Application::sole()), ['decision' => 'reject', 'remarks' => 'Receipt unreadable.']);

        Notification::assertSentTo($student, ApplicationDecided::class);
        Notification::assertNotSentTo($chair, ApplicationAwaitingAction::class);
    }

    protected function decide(User $approver, Application $application): void
    {
        $this->actingAs($approver)
            ->post(route('claims.decide', $application), ['decision' => 'approve'])
            ->assertSessionHasNoErrors();
    }

    protected function assertAwaiting(User $approver, int $times): void
    {
        Notification::assertSentToTimes($approver, ApplicationAwaitingAction::class, $times);
    }
}
