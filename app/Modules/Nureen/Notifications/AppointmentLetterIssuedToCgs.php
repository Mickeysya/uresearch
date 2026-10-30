<?php

namespace App\Modules\Nureen\Notifications;

use App\Modules\Core\Models\Application;
use App\Modules\Nureen\Models\SupervisionDetail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Non-Executive CGS is notified of every issued letter and can revoke it
 * with a mandatory reason." Two separate things: this is the notification
 * half -- being ABLE to find a letter on the "Issued Appointment Letters"
 * list is not the same as being told the moment one is issued. Sent
 * regardless of which cgs_stage mode issued it (in 'manual' mode CGS is
 * also the one who just approved it, but the rule says every issued
 * letter, not every letter CGS did not already know about).
 */
class AppointmentLetterIssuedToCgs extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected Application $application,
        protected SupervisionDetail $detail,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $roleLabel = $this->detail->appointment_role === SupervisionDetail::ROLE_CO
            ? 'Co-Supervisor'
            : 'Main Supervisor';

        return (new MailMessage)
            ->subject('Appointment Letter Issued: '.$this->detail->letter_reference)
            ->greeting('Hi '.$notifiable->name.',')
            ->line("A {$roleLabel} appointment letter has been issued for ".
                "{$this->application->student?->name}, appointing {$this->detail->requestedSupervisor->name}.")
            ->line('Reference: '.$this->detail->letter_reference)
            ->line('No action is needed from you -- this is for your information, and you can revoke it from the Issued Appointment Letters list if needed.')
            ->action('View Issued Appointment Letters', route('supervision.issued'))
            ->line('Thank you for using UResearch 2.0.');
    }
}
