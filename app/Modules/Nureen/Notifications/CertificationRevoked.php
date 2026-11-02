<?php

namespace App\Modules\Nureen\Notifications;

use App\Modules\Nureen\Models\GaCertificationDetail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A GA/GRA certification letter was revoked: emailed to the student and to
 * the supervisor named on the request, with the reason -- the same as
 * AppointmentLetterRevoked, so the revoke form's hint ("shown to the student
 * and their supervisor") holds for both letters. The application itself
 * stays approved.
 */
class CertificationRevoked extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected GaCertificationDetail $detail) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('GA/GRA Certification Letter Revoked: '.$this->detail->reference_number)
            ->greeting('Hi '.$notifiable->name.',')
            ->line("The certification letter {$this->detail->reference_number} has been revoked.")
            ->line('Reason given: '.$this->detail->revoked_reason)
            ->line('The application itself remains approved -- this only means the letter already issued is no longer valid.');

        // Only the student can open the tracking page.
        if ($notifiable->isStudent()) {
            $mail = $mail->action('View the application', route('applications.show', $this->detail->application_id));
        }

        return $mail->line('Thank you for using UResearch 2.0.');
    }
}
