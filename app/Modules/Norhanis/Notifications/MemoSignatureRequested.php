<?php

namespace App\Modules\Norhanis\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Asks the supervisor, then the centre head, to sign the Memo Request Split
 * Budget online. Sent to a bare email address, like the other sign-off
 * requests: the signed link is all the signer needs.
 */
class MemoSignatureRequested extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected string $signerName,
        protected string $studentName,
        protected string $paperTitle,
        protected string $grantAmount,
        protected string $url,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Please sign the split budget memo: '.$this->paperTitle)
            ->greeting('Dear '.$this->signerName.',')
            ->line("{$this->studentName} is applying for publication funding for \"{$this->paperTitle}\". The fee is above the RM3000 the Centre for Graduate Studies fund covers, and the remaining RM{$this->grantAmount} is requested from a research grant.")
            ->line('The memo requesting this needs your signature. The link below shows it in full; you sign online, with no account or password.')
            ->action('Review and sign the memo', $this->url)
            ->line('If the grant details are wrong, the same page lets you send it back to the student.')
            ->line('This link is personal to you. Please do not forward it.');
    }
}
