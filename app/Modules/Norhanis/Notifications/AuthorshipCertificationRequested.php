<?php

namespace App\Modules\Norhanis\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Asks an author of a paper to sign the Authorship Certification online.
 *
 * Sent to a bare email address, not a User: a co-author is often outside UTP
 * and has no account, so the signed link in the email is all they need. The
 * link is built by the caller, inside the request, and passed in as a string.
 */
class AuthorshipCertificationRequested extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected string $authorName,
        protected string $studentName,
        protected string $paperTitle,
        protected string $url,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Please sign the Authorship Certification: '.$this->paperTitle)
            ->greeting('Dear '.$this->authorName.',')
            ->line("{$this->studentName} has listed you as an author of \"{$this->paperTitle}\" in a publication funding application to the Centre for Graduate Studies, Universiti Teknologi PETRONAS.")
            ->line('Every author is asked to certify the authorship. It takes about a minute and needs no account or password.')
            ->action('Review and sign', $this->url)
            ->line('This link is personal to you. Please do not forward it.');
    }
}
