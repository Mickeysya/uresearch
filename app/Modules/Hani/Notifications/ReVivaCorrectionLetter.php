<?php

namespace App\Modules\Hani\Notifications;

use App\Modules\Hani\Models\ReVivaLetterSend;
use App\Modules\Hani\Models\ReVivaStaffDocument;
use App\Modules\Hani\Support\ReVivaLetters;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Storage;

/**
 * The correction letter to one main examiner, as a PDF attachment, with the
 * signed link to the corrected thesis and the Summary of Corrections.
 *
 * Examiners are not users: sent on demand (Notification::route('mail')),
 * mail only. $send: the log row (ReVivaLetterSend); a resend says so, and
 * the row gets the date the link in this email works until.
 */
class ReVivaCorrectionLetter extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public ReVivaStaffDocument $letter, public ?ReVivaLetterSend $send = null)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $detail = $this->letter->detail;
        $student = $detail->application->student;
        $examiner = $this->letter->examiner;
        $sentAt = $detail->sent_to_panel_at ?? now();
        // Counted from now, when the email is built, not from $sentAt.
        $expires = ReVivaLetters::panelLinkExpiry($detail);
        $resent = $this->send?->kind === ReVivaLetterSend::RESEND;
        $this->send?->update(['link_expires_at' => $expires]);

        $mail = (new MailMessage)
            ->subject(($resent ? 'Resent: ' : '').'Correction of thesis for re-evaluation: '.$student->name)
            ->greeting('Dear '.$examiner->name.',');

        if ($resent) {
            $mail->line('The Centre for Graduate Studies is sending this letter again, with a new link to the documents. Please use the link in this email; an earlier one may have expired.');
        }

        return $mail
            ->line('Please find attached the Centre for Graduate Studies\' letter on the corrected thesis of '.$student->name.'.')
            ->line('The corrected thesis and the Summary of Corrections are available through the button below. No login is needed. The link works until '.$expires->format('j F Y').'.')
            ->action('Open the corrected thesis', ReVivaLetters::panelLink($detail, $examiner, $expires))
            ->line('Please return the Thesis Re-evaluation Report (UTP/CGS/024) by '.ReVivaLetters::reviewDue($sentAt)->format('j F Y').', as the letter explains.')
            ->line('Sent by the Centre for Graduate Studies through UResearch 2.0.')
            ->attachData(Storage::disk('local')->get($this->letter->path), $this->letter->original_name, ['mime' => 'application/pdf']);
    }
}
