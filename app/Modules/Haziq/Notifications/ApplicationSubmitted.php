<?php

namespace App\Modules\Haziq\Notifications;

use App\Modules\Core\Models\Application;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Confirms submission and gives the student a way back to their own
 * read-only view of what they submitted — before this there was no
 * notification pointing at `gra-application.mine` at all, so the only way
 * back to it was knowing the URL.
 */
class ApplicationSubmitted extends Notification implements ShouldQueue
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
            'title' => $this->application->module()->label().' submitted',
            'body' => $this->application->module()->summary($this->application),
            'action_url' => route('gra-application.mine', $this->application),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->application->module()->label().' submitted — '.$this->application->reference())
            ->greeting('Hi '.$notifiable->name.',')
            ->line('Your application has been submitted and is now with Admin for document verification.')
            ->line($this->application->module()->summary($this->application))
            ->action('View my application', route('gra-application.mine', $this->application));
    }
}
