<?php

namespace App\Modules\Haziq\Notifications;

use App\Modules\Core\Models\Application;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The student-facing counterpart to GaInterviewFailed -- confirmed
 * explicitly 2026-11-10: a Pass used to only notify the next approver
 * (notifyNextApprover() in GaApplicationController::decide()), leaving
 * the student themselves to find out only by checking the page. A Fail
 * already told the student outright (ApplicationRejected); a Pass
 * deserves the same courtesy, worded for an outcome that is good news
 * and still has a stage left to go, not a final decision.
 */
class GaInterviewPassed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected Application $application) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'application_id' => $this->application->id,
            'title' => 'You passed your GA interview',
            'body' => 'Your Research Centre interview result is Pass. Your GA application has moved on to CGS for review.',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("You passed your GA interview — Application #{$this->application->id}")
            ->greeting('Hi '.$notifiable->name.',')
            ->line('Good news — you passed your Research Centre interview.')
            ->line('Your GA application has moved on to CGS for review.')
            ->action('View my application', route('ga-application.mine', $this->application));
    }
}
