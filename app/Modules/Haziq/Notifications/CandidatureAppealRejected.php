<?php

namespace App\Modules\Haziq\Notifications;

use App\Modules\Core\Models\Application;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CandidatureAppealRejected extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected Application $application, protected string $reason) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'application_id' => $this->application->id,
            'action_url' => route('stage-gate.index'),
            'title' => 'Your candidature appeal was rejected',
            'body' => $this->reason,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your candidature appeal was rejected')
            ->greeting('Hi '.$notifiable->name.',')
            ->line("Your appeal against the Stage Gate termination on {$this->application->reference()} was rejected by a Senior Director.")
            ->line("Reason: {$this->reason}")
            ->action('View your Stage Gate progress', route('stage-gate.index'));
    }
}
