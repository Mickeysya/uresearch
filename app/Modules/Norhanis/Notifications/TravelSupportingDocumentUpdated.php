<?php

namespace App\Modules\Norhanis\Notifications;

use App\Modules\Core\Models\Application;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to whoever currently holds the application's stage, when the student
 * replaces the supporting document after submission -- the approved memo for
 * international travel often only exists once the chain is already moving.
 */
class TravelSupportingDocumentUpdated extends Notification implements ShouldQueue
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
            'module' => 'Travel',
            'title' => "A new supporting document was uploaded for Travel Application #{$this->application->id}.",
            'url' => route('travel.show', $this->application),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Travel Application #{$this->application->id}: Supporting Document Updated")
            ->greeting('Hi '.$notifiable->name.',')
            ->line("The student has uploaded a new supporting document for Travel Application #{$this->application->id}.")
            ->action('View the application', route('travel.show', $this->application))
            ->line('Thank you for using UResearch 2.0.');
    }
}
