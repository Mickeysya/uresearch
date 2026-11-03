<?php

namespace App\Modules\Chloe\Notifications;

use App\Modules\Chloe\Models\Workstation;
use Illuminate\Notifications\Notification;

/**
 * The bell notice a student gets when they select a seat (round 4). Bell only: the confirmation e-mail was removed,
 * the page itself shows the success message. Not queued, so the bell is there when the page reloads.
 */
class WorkstationSeatSelected extends Notification
{
    public function __construct(protected Workstation $workstation) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $location = $this->workstation->location;

        return ['title' => 'Workstation confirmed: Seat '.$this->workstation->seat_code.', Room '.$location->room_code.', Block '.$location->block];
    }
}
