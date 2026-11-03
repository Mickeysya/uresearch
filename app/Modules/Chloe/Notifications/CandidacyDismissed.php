<?php

namespace App\Modules\Chloe\Notifications;

use App\Modules\Chloe\Models\CandidacyDismissal;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CandidacyDismissed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected CandidacyDismissal $dismissal) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['title' => 'Your study candidacy has been dismissed.'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Study Candidacy — Dismissal Confirmed')
            ->greeting('Hi '.$notifiable->name.',')
            ->line('This confirms that your study candidacy has been dismissed, and the Registry has processed it.')
            ->line('Reason: '.CandidacyDismissal::reasonLabel($this->dismissal->reason).'.')
            ->line('If you believe this is a mistake, please contact CGS.')
            ->line('Thank you for using UResearch 2.0.');
    }
}
