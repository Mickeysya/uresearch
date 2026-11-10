<?php

namespace App\Modules\Hani\Notifications;

use App\Modules\Hani\Models\ReVivaDetail;
use App\Modules\Hani\Support\ReVivaProcess;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A consolidation meeting or re-viva invitation, with a calendar file. Names no examiner. */
class ReVivaMeetingInvite extends Notification
{
    public function __construct(public ReVivaDetail $detail, public string $label) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $d = $this->detail;
        $name = $d->application->student->name;
        $ics = ReVivaProcess::ics('reviva-meeting-'.$d->id, "{$this->label}: {$name}", $d->meeting_at, $d->meeting_at->copy()->addHours(2), (string) $d->meeting_venue);

        return (new MailMessage)->subject("{$this->label}: {$name}")
            ->line("{$this->label} for {$name}, re-viva round {$d->cycle_number}.")
            ->line('Date and time: '.$d->meeting_at->format('j F Y, g:i a'))
            ->line('Venue: '.$d->meeting_venue)
            ->line('Sent by the Centre for Graduate Studies through UResearch 2.0.')
            ->attachData($ics, 'invite.ics', ['mime' => 'text/calendar; charset=utf-8']);
    }
}
