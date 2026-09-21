<?php

namespace App\Modules\Chloe\Notifications;

use App\Modules\Chloe\Models\StudyCandidacy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CandidacyReminder extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected StudyCandidacy $candidacy, protected int $reminderNumber) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => "Reminder #{$this->reminderNumber}: your study candidacy expires ".
                $this->candidacy->candidacy_expiry_date->format('j M Y').'.',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $expiry = $this->candidacy->candidacy_expiry_date;

        return (new MailMessage)
            ->subject('Study Candidacy Expiry Reminder — '.$expiry->format('j M Y'))
            ->greeting('Hi '.$notifiable->name.',')
            ->line("Your study candidacy is due to expire on {$expiry->format('j M Y')} (in ".
                (int) now()->diffInDays($expiry, false).' days).')
            ->line('If you need more time, submit a Study Candidacy Appeal before this date.')
            ->action('View my candidacy status', route('candidacy.status'))
            ->line('Thank you for using UResearch 2.0.');
    }
}
