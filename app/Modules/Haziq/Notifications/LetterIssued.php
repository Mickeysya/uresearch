<?php

namespace App\Modules\Haziq\Notifications;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApplicationDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Attachment;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Generic "a generated letter is ready" notice, shared by GRA Offer,
 * GRA Admission, GRA Extension Approval, GA Offer and GA Program Offer
 * letters — one class rather than five near-identical ones, following the
 * shape of Nureen's CertificationIssued.
 */
class LetterIssued extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected Application $application,
        protected ApplicationDocument $letter,
        protected string $label,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->label.' — '.$this->application->reference())
            ->greeting('Hi '.$notifiable->name.',')
            ->line("Your {$this->label} is ready and attached to this email.")
            ->action('View it in UResearch', route('applications.show', $this->application));

        if ($this->letter->exists()) {
            $mail->attach(
                Attachment::fromStorageDisk('local', $this->letter->path)
                    ->as($this->letter->original_name)
                    ->withMime($this->letter->mime_type ?? 'application/pdf'),
            );
        }

        return $mail;
    }

    public function toArray(object $notifiable): array
    {
        return [
            'application_id' => $this->application->id,
            'title' => $this->label.' issued',
            'body' => "Your {$this->label} has been generated and emailed to you.",
        ];
    }
}
