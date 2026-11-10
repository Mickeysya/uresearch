<?php

namespace App\Modules\Hani\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The one-time code an examiner signs in to their re-viva link with. Sent
 * straight away, not queued: they are waiting on the page for it.
 */
class ReVivaPanelCode extends Notification
{
    public function __construct(public string $code, public int $minutes) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your UResearch sign-in code: '.$this->code)
            ->line('Use this code to open the corrected thesis:')
            ->line('**'.$this->code.'**')
            ->line("It works for {$this->minutes} minutes. If you did not ask for it, you can ignore this email.");
    }
}
