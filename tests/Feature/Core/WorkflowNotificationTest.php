<?php

namespace Tests\Feature\Core;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Norhanis\Models\TravelDetail;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * The decision is the durable thing; the email is best effort.
 *
 * WorkflowEngine::decide() used to notify the student inside its own
 * transaction, which failed in both directions: a queue broker that was down
 * threw and rolled back a valid approval, and a controller that wrapped
 * decide() in a transaction of its own sent the student an email about an
 * approval that outer transaction then rolled back.
 *
 * Both are one line from coming back -- moving the notify() call back inside
 * the transaction body. These two tests are what notices.
 */
class WorkflowNotificationTest extends TestCase
{
    use MakesUsers, RefreshDatabase;

    /** Local travel: Supervisor endorses, Chair approves. */
    protected function travelApplication(User $student): Application
    {
        $application = Application::create([
            'student_id' => $student->id,
            'submitted_by_id' => $student->id,
            'module_type' => 'travel',
            'status' => Application::STATUS_DRAFT,
        ]);

        TravelDetail::create([
            'application_id' => $application->id,
            'type_of_request' => 'conference',
            'travel_start_date' => now()->addWeek(),
            'travel_end_date' => now()->addWeeks(2),
            'duration_days' => 8,
            'reason_for_travel' => 'Present a paper',
            'destination_address' => 'Kuala Lumpur',
            'is_international' => false,
        ]);

        return app(WorkflowEngine::class)->submit($application);
    }

    /** A notification dispatcher standing in for a broker that is down. */
    protected function brokerOutage(): void
    {
        $this->app->instance(Dispatcher::class, new class implements Dispatcher
        {
            public function send($notifiables, $notification)
            {
                throw new \RuntimeException('Connection refused [tcp://redis:6379]');
            }

            public function sendNow($notifiables, $notification, ?array $channels = null)
            {
                $this->send($notifiables, $notification);
            }
        });
    }

    public function test_a_broker_outage_does_not_roll_back_the_approval(): void
    {
        $student = $this->student();
        $supervisor = $this->supervisor();
        $application = $this->travelApplication($student);

        Exceptions::fake();
        $this->brokerOutage();

        // No exception reaches the approver: their decision was accepted.
        app(WorkflowEngine::class)->decide($application, $supervisor, 'approve', 'Go ahead.');

        $this->assertSame('chair', $application->fresh()->current_stage);
        $this->assertDatabaseHas('approval_history', [
            'application_id' => $application->id,
            'approver_id' => $supervisor->id,
            'stage_key' => 'supervisor',
            'decision' => 'endorsed',
        ]);

        // Swallowed, but not silently -- it still lands in the log.
        Exceptions::assertReported(\RuntimeException::class);
    }

    public function test_a_rollback_in_the_calling_controller_sends_nothing(): void
    {
        $student = $this->student();
        $supervisor = $this->supervisor();
        $application = $this->travelApplication($student);

        Notification::fake();

        // RpdAppealController, RpdDismissalController and
        // AppointmentLetterController all wrap decide() like this.
        try {
            DB::transaction(function () use ($application, $supervisor) {
                app(WorkflowEngine::class)->decide($application, $supervisor, 'approve');

                throw new \RuntimeException('The step after the decision failed.');
            });
        } catch (\RuntimeException) {
            // Expected: the whole decision is rolled back.
        }

        Notification::assertNothingSent();
        $this->assertSame('supervisor', $application->fresh()->current_stage);
        $this->assertDatabaseCount('approval_history', 0);
    }
}
