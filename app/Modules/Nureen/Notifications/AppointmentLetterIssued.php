<?php

namespace App\Modules\Nureen\Notifications;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApplicationDocument;
use App\Modules\Core\Models\User;
use App\Modules\Nureen\Models\SupervisionDetail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The issued appointment letter, delivered to the student with the
 * appointed supervisor CC'd -- same shape as CertificationIssued, but with a
 * literal mail CC rather than a second notification: this class belongs to
 * Nureen, not Core, so there is nothing stopping MailMessage::cc() here.
 */
class AppointmentLetterIssued extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected Application $application,
        protected SupervisionDetail $detail,
        protected ApplicationDocument $letter,
        protected User $supervisor,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $roleLabel = $this->detail->appointment_role === SupervisionDetail::ROLE_CO
            ? 'Co-Supervisor'
            : 'Main Supervisor';

        $mail = (new MailMessage)
            ->subject('Your Supervisor Appointment Letter: '.$this->detail->letter_reference)
            ->greeting('Hi '.$notifiable->name.',')
            ->line("Your {$roleLabel} appointment of {$this->supervisor->name} has been approved. ".
                'The appointment letter is attached to this email.')
            ->line('Reference: '.$this->detail->letter_reference)
            ->cc($this->supervisor->email, $this->supervisor->name)
            ->action('View it in UResearch', route('applications.show', $this->application))
            ->line('Thank you for using UResearch 2.0.');

        if ($this->letter->exists()) {
            $mail->attachFromStorageDisk(
                'local',
                $this->letter->path,
                $this->letter->original_name,
                ['mime' => $this->letter->mime_type ?? 'application/pdf'],
            );
        }

        return $mail;
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'application_id' => $this->application->id,
            'module' => $this->application->module_type,
            'reference' => $this->detail->letter_reference,
            'title' => 'Supervisor appointment letter issued',
            'message' => "Your appointment letter for {$this->supervisor->name} has been issued and emailed to you.",
        ];
    }
}
