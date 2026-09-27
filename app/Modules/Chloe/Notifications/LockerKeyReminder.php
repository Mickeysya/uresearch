<?php

namespace App\Modules\Chloe\Notifications;

use App\Modules\Chloe\Models\LockerKey;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LockerKeyReminder extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected LockerKey $lockerKey) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $days = (int) $this->lockerKey->requested_at->diffInDays(now());

        return (new MailMessage)
            ->subject('Reminder: Your Locker Key Is Waiting for Collection')
            ->greeting('Hi '.$notifiable->name.',')
            ->line("You requested a locker key {$days} days ago and it has not yet been collected.")
            ->line('Please collect it from the CGS office at your earliest convenience.')
            ->line('Thank you for using UResearch 2.0.');
    }
}
