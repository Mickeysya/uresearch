<?php

namespace App\Modules\Chloe\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to a student when an upload of the Registry's result marks them as dismissed. An immediate, user-triggered action, so it is sent at once
 * (never held to 08:00). A demo student has no login: e-mail only.
 */
class CandidacyDismissed extends Notification implements ShouldQueue
{
    use Queueable;

    public const MESSAGE = 'Your candidacy has been ended because the maximum candidacy period was exceeded (Postgraduate Student Handbook section 4.3), as confirmed by the Registry. The Handbook (section 4.4) describes how to appeal for reinstatement; please contact the Registry.';

    public function __construct(protected string $studentName) {}

    public function via(object $notifiable): array
    {
        return $notifiable instanceof AnonymousNotifiable ? ['mail'] : ['mail', 'database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['title' => 'Your study candidacy has been ended.', 'body' => self::MESSAGE];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Study Candidacy — Candidacy Ended')
            ->greeting('Dear '.$this->studentName.',')
            ->line(self::MESSAGE)
            ->salutation("Best regards,\nCentre for Graduate Studies (CGS)\nUniversiti Teknologi PETRONAS");
    }
}
