<?php

namespace App\Modules\Nureen\Notifications;

use App\Modules\Nureen\Models\SupervisionDetail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Revoking emails the student and supervisor, and never changes
 * applications.status." Sent to both, same content -- the application
 * itself stays approved, this is only about whether the letter already
 * issued is still trusted.
 */
class AppointmentLetterRevoked extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected SupervisionDetail $detail) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Supervisor Appointment Letter Revoked: '.$this->detail->letter_reference)
            ->greeting('Hi '.$notifiable->name.',')
            ->line("The appointment letter {$this->detail->letter_reference} has been revoked.")
            ->line('Reason given: '.$this->detail->letter_revoked_reason)
            ->line('The application itself remains approved -- this only means the letter already issued is no longer valid.')
            // Sent to the student AND the supervisor: each gets a page they can open.
            ->action('View the application', $notifiable->isStudent()
                ? route('applications.show', $this->detail->application_id)
                : route('nureen.application', $this->detail->application_id))
            ->line('Thank you for using UResearch 2.0.');
    }
}
