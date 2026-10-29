<?php

namespace App\Modules\Chloe\Notifications;

use App\Modules\Chloe\Models\Workstation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Email 2 — sent once the confirm link is clicked and the seat is actually theirs. */
class WorkstationSelectionConfirmed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected Workstation $workstation) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $location = $this->workstation->location;

        return (new MailMessage)
            ->subject('Workstation Confirmed')
            ->greeting('Dear '.$notifiable->name.',')
            ->line('Your workstation has been confirmed. The details are as follows:')
            ->line('Block: '.$location->block)
            ->line('Room: '.$location->room_code)
            ->line('Seat: '.$this->workstation->seat_code)
            ->line('Please refer to the seat layout displayed in the room to locate your seat. For any changes, please contact the Centre for Graduate Studies (CGS).')
            ->salutation("Best regards,\nCentre for Graduate Studies (CGS)\nUniversiti Teknologi PETRONAS");
    }
}
