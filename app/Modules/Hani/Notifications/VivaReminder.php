<?php

namespace App\Modules\Hani\Notifications;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * One reminder from `viva:reminders`. $kind says which (see
 * Console\VivaReminders::KINDS); the wording is built by the command, so
 * every reminder reads the same way and names what to do next.
 *
 * Users get mail and the in-app copy; examiners and the chairman (not users)
 * get mail only. An .ics file rides along on the panel reminders.
 */
class VivaReminder extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<int, string>  $lines
     */
    public function __construct(
        public readonly string $kind,
        protected Application $notice,
        protected string $subject,
        protected array $lines,
        protected ?string $actionLabel = null,
        protected ?string $actionUrl = null,
        protected ?string $ics = null,
    ) {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return $notifiable instanceof User ? ['mail', 'database'] : ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->subject)
            ->greeting('Dear '.($notifiable instanceof User ? $notifiable->name : 'Sir/Madam').',');

        foreach ($this->lines as $line) {
            $mail->line($line);
        }

        if ($this->actionUrl && $notifiable instanceof User) {
            $mail->action($this->actionLabel ?? 'Open UResearch', $this->actionUrl);
        }

        $mail->line('This is an automatic reminder from UResearch 2.0.');

        if ($this->ics !== null) {
            $mail->attachData($this->ics, 'viva-voce.ics', ['mime' => 'text/calendar; charset=UTF-8; method=PUBLISH']);
        }

        return $mail;
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
}
