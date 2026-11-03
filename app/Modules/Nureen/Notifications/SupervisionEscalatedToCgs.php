<?php

namespace App\Modules\Nureen\Notifications;

use App\Modules\Core\Models\Application;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * CGS Staff has not acted yet at this point -- this is purely informational:
 * the named supervisor has been reminded repeatedly with no decision, so
 * CGS knows a request is stuck before the student has to ask. The
 * supervisor keeps being reminded on the same schedule; this does not
 * replace that.
 */
class SupervisionEscalatedToCgs extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected Application $application,
        protected string $supervisorName,
        protected int $reminderCount,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Supervision request #{$this->application->id} stalled at {$this->supervisorName}")
            ->greeting('Hi '.$notifiable->name.',')
            ->line("Supervision request #{$this->application->id} has been reminded ".
                "{$this->reminderCount} times and {$this->supervisorName} still has not decided on it.")
            ->line('No action is needed from you yet — this is for visibility, in case it is worth following up directly.')
            ->action('View the request', route('supervision.queue'))
            ->line('Thank you for using UResearch 2.0.');
    }
}
