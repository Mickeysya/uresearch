<?php

namespace App\Modules\Hani\Notifications;

use App\Modules\Core\Models\Application;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Step 5 news: the result is recorded, a re-viva needs logging, the
 * corrections were accepted. $kind says which (see Support\VivaResult); the
 * wording is built there. Mail and in-app; every recipient is a user.
 */
class VivaOutcome extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param  array<int, string>  $lines */
    public function __construct(
        public readonly string $kind,
        protected Application $notice,
        protected string $subject,
        protected array $lines,
        protected string $actionLabel,
        protected string $actionUrl,
    ) {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->subject)->greeting('Dear '.$notifiable->name.',');

        foreach ($this->lines as $line) {
            $mail->line($line);
        }

        return $mail->action($this->actionLabel, $this->actionUrl)->line('Sent through UResearch 2.0.');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'application_id' => $this->notice->id,
            'module' => $this->notice->module()->label(),
            'reference' => $this->notice->reference(),
            'title' => $this->subject,
            'message' => $this->lines[0] ?? '',
            'kind' => $this->kind,
        ];
    }

    /** What the lines say, for tests and the in-app feed. */
    public function lines(): array
    {
        return $this->lines;
    }
}
