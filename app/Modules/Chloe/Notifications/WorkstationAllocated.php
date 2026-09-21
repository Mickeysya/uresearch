<?php

namespace App\Modules\Chloe\Notifications;

use App\Modules\Chloe\Models\WorkstationRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WorkstationAllocated extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected WorkstationRequest $request) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $seat = $this->request->workstation;

        return (new MailMessage)
            ->subject('Workstation Confirmed — Seat '.$seat->seat_code)
            ->greeting('Hi '.$notifiable->name.',')
            ->line('Your workstation request has been confirmed.')
            ->line('Seat: '.$seat->seat_code.' — '.$seat->location->name)
            ->action('View my workstation', route('workstation.select'))
            ->line('Thank you for using UResearch 2.0.');
    }
}
