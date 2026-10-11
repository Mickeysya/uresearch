<?php

namespace App\Modules\Norhanis\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Asks an internal reviewer to complete the Paper Evaluation Sheet online.
 *
 * Sent to a bare email address, like AuthorshipCertificationRequested: the
 * signed link is all the reviewer needs, whether or not they have an account.
 */
class PaperEvaluationRequested extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected string $reviewerName,
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
            ->subject('Request to evaluate a paper: '.$this->paperTitle)
            ->greeting('Dear '.$this->reviewerName.',')
            ->line("{$this->studentName} has asked you to be an internal reviewer for the paper \"{$this->paperTitle}\", as part of a publication funding application to the Centre for Graduate Studies.")
            ->line('The link below opens the paper and the Paper Evaluation Sheet (UTP/CGS/040). You rate four criteria, add your comments and sign, all online. No account or password is needed.')
            ->action('Open the paper and evaluate', $this->url)
            ->line('If you are unable to review it, the same page lets you say so, and the student will ask someone else.')
            ->line('This link is personal to you. Please do not forward it.');
    }
}
