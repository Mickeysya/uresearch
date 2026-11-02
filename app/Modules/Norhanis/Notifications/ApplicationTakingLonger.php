<?php

namespace App\Modules\Norhanis\Notifications;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Support\Stage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a student that their application is taking longer than usual at a
 * stage and that the approver has been reminded, so a quiet week does not
 * read as a lost application. Sent once per stage, with the first reminder.
 *
 * It names the stage, never the person holding it.
 */
class ApplicationTakingLonger extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected Application $application,
        protected Stage $stage,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->subject())
            ->greeting('Hi '.$notifiable->name.',')
            ->line("Your {$this->module()} application is taking longer than usual at the {$this->stage->label} stage.")
            ->line('We have sent the approver a reminder. You do not need to do anything.')
            ->action('Track your application', route('applications.show', $this->application))
            ->line('Thank you for using UResearch 2.0.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'application_id' => $this->application->id,
            'module' => $this->module(),
            'stage_label' => $this->stage->label,
            'title' => $this->subject(),
            'url' => route('applications.show', $this->application),
        ];
    }

    protected function subject(): string
    {
        return "{$this->module()} Application #{$this->application->id}: Taking longer than usual";
    }

    protected function module(): string
    {
        return $this->application->module()->label();
    }
}
