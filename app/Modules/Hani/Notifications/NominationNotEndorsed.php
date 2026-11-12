<?php

namespace App\Modules\Hani\Notifications;

use App\Modules\Core\Models\Application;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** To the supervisor: the DAC did not endorse their examiner nomination, with the reason. */
class NominationNotEndorsed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected Application $application, public readonly string $reason)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $student = $this->application->student?->name;

        return (new MailMessage)
            ->subject('Examiner nomination not endorsed by the DAC: '.$student)
            ->greeting('Dear '.$notifiable->name.',')
            ->line("The Departmental Academic Committee did not endorse your examiner nomination for {$student}.")
            ->line('Reason: '.$this->reason)
            ->line('You may submit a new nomination.')
            ->action('Nominate Examiners', route('examiner-nomination.create'))
            ->line('Sent by UResearch 2.0.');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'application_id' => $this->application->id,
            'module' => $this->application->module()->label(),
            'reference' => $this->application->reference(),
            'title' => 'Examiner nomination not endorsed by the DAC',
            'message' => 'Reason: '.$this->reason,
        ];
    }
}
