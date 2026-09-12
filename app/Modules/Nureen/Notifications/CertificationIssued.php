<?php

namespace App\Modules\Nureen\Notifications;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApplicationDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The issued GA/GRA certification letter, delivered to the student.
 *
 * The scope asks for the system to "generate, format, and dispatch" the letter
 * on final approval. Generation and storage already happened in
 * CertificationController; this is the dispatch. The student previously got
 * only the generic ApplicationDecided mail, which announced the approval but
 * carried nothing, leaving them to find the download themselves.
 *
 * The PDF is attached from the private disk rather than linked, so the letter
 * arrives in the inbox the way a signed letter would. The in-app copy stays
 * the record: it is still an ApplicationDocument behind the authorised
 * download route, so a student who loses the email can fetch it again and
 * nobody else can fetch it at all.
 */
class CertificationIssued extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected Application $application,
        protected ApplicationDocument $certificate,
    ) {}

    public function via(object $notifiable): array
    {
        // Mail carries the attachment; database puts it in the in-app feed
        // next to every other decision the student has had.
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Your GA/GRA Certification Letter — '.$this->application->reference())
            ->greeting('Hi '.$notifiable->name.',')
            ->line('Your certification letter has been endorsed and is attached to this email.')
            ->line('Reference: '.$this->application->reference())
            ->action('View it in UResearch', route('applications.show', $this->application))
            ->line('Thank you for using UResearch 2.0.');

        // Guarded: the queue may run this after the row exists but while the
        // file is unreadable (a wiped storage volume in development, say).
        // A missing attachment should not cost the student the notification.
        if ($this->certificate->exists()) {
            $mail->attachFromStorageDisk(
                'local',
                $this->certificate->path,
                $this->certificate->original_name,
                ['mime' => $this->certificate->mime_type ?? 'application/pdf'],
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
            'reference' => $this->application->reference(),
            'title' => 'Certification letter issued',
            'message' => 'Your GA/GRA certification letter has been endorsed and emailed to you.',
        ];
    }
}
