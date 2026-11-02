<?php

namespace App\Modules\Nureen\Notifications;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApplicationDocument;
use App\Modules\Core\Models\User;
use App\Modules\Nureen\Models\SupervisionDetail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Attachment;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The issued appointment letter, sent to the student AND, as a separate
 * message, to the appointed supervisor -- each with the letter attached and
 * a button to a page that recipient can open: the student's details page,
 * or the staff view (nureen.application) for the supervisor.
 *
 * It used to CC the supervisor on the student's message, so both got the
 * student's link, and the supervisor's click was refused (3 Nov 2026).
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

        $toStudent = $notifiable->is($this->application->student);
        $student = $this->application->student;

        $mail = $toStudent
            ? (new MailMessage)
                ->subject('Your Supervisor Appointment Letter: '.$this->detail->letter_reference)
                ->greeting('Hi '.$notifiable->name.',')
                ->line("Your {$roleLabel} appointment of {$this->supervisor->name} has been approved. ".
                    'The appointment letter is attached to this email.')
                ->line('Reference: '.$this->detail->letter_reference)
                ->action('View it in UResearch', route('applications.show', $this->application))
            : (new MailMessage)
                ->subject('Appointment Letter Issued: you are '.$roleLabel.' to '.($student?->name ?? 'a student'))
                ->greeting('Hi '.$notifiable->name.',')
                ->line("You have been appointed {$roleLabel} to {$student?->name}"
                    .($student?->matric_no ? " ({$student->matric_no})" : '').'. A copy of the appointment letter is attached.')
                ->line('Reference: '.$this->detail->letter_reference)
                ->action('View the application', route('nureen.application', $this->application));

        $mail = $mail->line('Thank you for using UResearch 2.0.');

        if ($this->letter->exists()) {
            // MailMessage has no attachFromStorageDisk(); this is the
            // Attachable form Laravel actually supports. The old call failed
            // every one of these jobs in the queue worker.
            $mail->attach(
                Attachment::fromStorageDisk('local', $this->letter->path)
                    ->as($this->letter->original_name)
                    ->withMime($this->letter->mime_type ?? 'application/pdf')
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
