<?php

namespace App\Modules\Chloe\Notifications;

use App\Modules\Chloe\Models\CandidacyAppealDetail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent alongside (not instead of) the generic ApplicationDecided email
 * WorkflowEngine already sends on every approval — this one carries the
 * concrete outcome the generic email can't: the actual new expiry date.
 * Same layering Nureen's CertificationIssued uses on top of the engine's own
 * notification.
 */
class CandidacyAppealApproved extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected CandidacyAppealDetail $appeal) {}

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
        return (new MailMessage)
            ->subject('Study Candidacy Appeal Approved — New Expiry Date')
            ->greeting('Hi '.$notifiable->name.',')
            ->line('Your study candidacy appeal has been approved by the Dean of PGR.')
            ->line("Extension granted: {$this->appeal->requested_extension_months} month(s).")
            ->line('New candidacy expiry date: '.$this->appeal->new_expiry_date->format('j M Y').'.')
            ->action('View my candidacy status', route('candidacy.status'))
            ->line('Thank you for using UResearch 2.0.');
    }
}
