<?php

namespace App\Modules\Chloe\Notifications;

use App\Modules\Chloe\Models\CandidacyAppealDetail;
use App\Modules\Core\Models\ApplicationDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Attachment;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent alongside (not instead of) the generic ApplicationDecided email
 * WorkflowEngine already sends on every approval — this one carries the
 * concrete outcome the generic email can't: the actual new expiry date, and
 * (same layering, same reasoning as Nureen's CertificationIssued) the signed
 * "Appeal for Extension of Candidacy" memo as a PDF attachment.
 */
class CandidacyAppealApproved extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected CandidacyAppealDetail $appeal,
        protected ?ApplicationDocument $memo = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Your study candidacy appeal was approved — new expiry date '.
                $this->appeal->new_expiry_date->format('j M Y').'.',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Study Candidacy Appeal Approved — New Expiry Date')
            ->greeting('Hi '.$notifiable->name.',')
            ->line('Your study candidacy appeal has been approved by the Dean of PGR.')
            ->line("Extension granted: {$this->appeal->requested_extension_months} month(s).")
            ->line('New candidacy expiry date: '.$this->appeal->new_expiry_date->format('j M Y').'.')
            ->line('The signed Appeal for Extension of Candidacy memo is attached to this email.')
            ->action('View my candidacy status', route('candidacy.status'))
            ->line('Thank you for using UResearch 2.0.');

        // attachFromStorageDisk() is a Mailable method, not a MailMessage
        // one -- MailMessage::attach() takes an Attachment instance instead
        // (see MailMessage::attach(), which special-cases it). Guarded the
        // same way CertificationIssued intends to: the queue may run this
        // after the row exists but while the file is unreadable, and a
        // missing attachment should not cost the student the notification.
        if ($this->memo && $this->memo->exists()) {
            $mail->attach(
                Attachment::fromStorageDisk('local', $this->memo->path)
                    ->as($this->memo->original_name)
                    ->withMime($this->memo->mime_type ?? 'application/pdf')
            );
        }

        return $mail;
    }
}
