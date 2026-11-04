<?php

namespace App\Modules\Hani\Notifications;

use App\Modules\Core\Models\Application;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * One reminder from `hani:sla-reminders`: an application has waited at its
 * stage past a limit in hani.sla. The command builds the wording, so every
 * reminder says how long, against what limit, and what to do next.
 */
class SlaReminder extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<int, string>  $lines
     */
    public function __construct(
        public readonly int $threshold,
        protected Application $application,
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
        $mail = (new MailMessage)
            ->subject($this->subject)
            ->greeting('Dear '.$notifiable->name.',');

        foreach ($this->lines as $line) {
            $mail->line($line);
        }

        return $mail->action($this->actionLabel, $this->actionUrl)
            ->line('This is an automatic reminder from UResearch 2.0. The time limits are set by CGS and are provisional.');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'application_id' => $this->application->id,
            'module' => $this->application->module()->label(),
            'reference' => $this->application->reference(),
            'title' => $this->subject,
            'message' => $this->lines[0] ?? '',
            'kind' => 'sla_'.$this->threshold,
        ];
    }
}
