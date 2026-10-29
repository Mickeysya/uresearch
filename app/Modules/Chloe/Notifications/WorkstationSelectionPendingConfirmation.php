<?php

namespace App\Modules\Chloe\Notifications;

use App\Modules\Chloe\Models\WorkstationPendingSelection;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Email 1 — sent the moment a student clicks Select, before the seat is
 * actually theirs. Exact subject/body wording is the spec, not a
 * paraphrase: this is the confirmation request a student has 5 minutes to
 * act on, so it says exactly what happens and by when.
 */
class WorkstationSelectionPendingConfirmation extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected WorkstationPendingSelection $selection) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $seat = $this->selection->workstation;
        $location = $seat->location;
        $confirmLink = route('workstation.confirm', ['token' => $this->selection->token]);

        // app.timezone is already Asia/Kuala_Lumpur, so this is already
        // Malaysia time with no extra conversion.
        $expiryTime = $this->selection->expires_at->format('g:i A \o\n j M Y');

        return (new MailMessage)
            ->subject('Action Required: Confirm Your Workstation Selection (Valid for 5 Minutes)')
            ->greeting('Dear '.$notifiable->name.',')
            ->line('You have selected the following workstation through the UResearch Dashboard:')
            ->line('Block: '.$location->block)
            ->line('Room: '.$location->room_code)
            ->line('Seat: '.$seat->seat_code)
            ->line('To complete your request, please confirm your selection within 5 minutes by clicking the button below.')
            ->action('Confirm My Workstation', $confirmLink)
            ->line('This link will expire at '.$expiryTime.'. If you do not confirm before then, your selection will be cancelled automatically and the seat will be released for other students.')
            ->line('If you did not make this request, please ignore this email.')
            // PART 4 #2.
            ->line('By selecting a seat and confirming it by email, you agree to the Postgraduate Workstation Term of Use ('.route('workstation.terms').').')
            ->salutation("Best regards,\nCentre for Graduate Studies (CGS)\nUniversiti Teknologi PETRONAS");
    }
}
