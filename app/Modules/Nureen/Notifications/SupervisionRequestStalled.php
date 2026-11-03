<?php

namespace App\Modules\Nureen\Notifications;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Support\Stage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Escalation reminder for a supervision request sitting on someone's queue
 * too long -- the scope's "reminder escalation when an approval stalls".
 */
class SupervisionRequestStalled extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected Application $application,
        protected Stage $stage,
        protected int $daysStalled,
        // The stage's Client Charter in working days, when it has one --
        // see Support\ClientCharter. Null for a stage reminded on the
        // stalled_days fallback, which has no charter figure to quote.
        protected ?int $charterDays = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Reminder: Supervision Request #'.$this->application->id.' awaiting your decision')
            ->greeting('Hi '.$notifiable->name.',')
            ->line("Supervision request #{$this->application->id} has been waiting on your ".
                "{$this->stage->label} decision for {$this->daysStalled} working ".str('day')->plural($this->daysStalled).'.')
            ->lineIf($this->charterDays !== null, "The CGS Client Charter for this stage is {$this->charterDays} working ".str('day')->plural($this->charterDays).'.')
            ->action('Review it now', route('supervision.queue', ['stage' => $this->stage->key]))
            ->line('Thank you for using UResearch 2.0.');
    }
}
