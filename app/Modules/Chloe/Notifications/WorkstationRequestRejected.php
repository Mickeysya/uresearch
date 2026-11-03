<?php

namespace App\Modules\Chloe\Notifications;

use App\Modules\Chloe\Models\Workstation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The "lost the race" email — another student's request for the same seat
 * committed first. See Services\WorkstationAllocator.
 */
class WorkstationRequestRejected extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected Workstation $seat) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Workstation Unavailable — Seat '.$this->seat->seat_code)
            ->greeting('Hi '.$notifiable->name.',')
            ->line('Seat '.$this->seat->seat_code.' was taken by another student a moment before your request.')
            ->line('No charge or record was created against your account — please pick another seat.')
            ->action('Choose another seat', route('workstation.select'))
            ->line('Thank you for using UResearch 2.0.');
    }
}
