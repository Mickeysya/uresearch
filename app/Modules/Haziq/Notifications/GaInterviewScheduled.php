<?php

namespace App\Modules\Haziq\Notifications;

use App\Modules\Core\Models\Application;
use App\Modules\Haziq\Models\GaApplicationDetail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Sent to the student the moment Research Centre sets an interview date — see GaApplicationController::scheduleInterview(). */
class GaInterviewScheduled extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected Application $application, protected GaApplicationDetail $detail) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'application_id' => $this->application->id,
            'title' => 'GA interview scheduled',
            'body' => 'Your GA application interview is scheduled for '.$this->detail->interview_date->format('j M Y, g:ia').'.',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Your GA interview is scheduled — Application #{$this->application->id}")
            ->greeting('Hi '.$notifiable->name.',')
            ->line('Your interview for your GA application has been scheduled.')
            ->line('Date: '.$this->detail->interview_date->format('j M Y, g:ia'))
            ->action('View my application', route('ga-application.mine', $this->application));
    }
}
