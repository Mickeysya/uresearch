<?php

namespace App\Modules\Nureen\Notifications;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Support\Stage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Stall reminder for GA Extension and Certification -- the generic case
 * where the stage's own role holder genuinely is the right person to nag,
 * unlike Supervision's named-supervisor stage (SupervisionRequestStalled).
 * Reads the module's own label and queue route rather than naming either
 * module, so a third module can reuse this through
 * Support\StalledApplicationReminder with no new notification class.
 */
class ApplicationStalled extends Notification implements ShouldQueue
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
        $label = $this->application->module()->label();

        return (new MailMessage)
            ->subject("Reminder: {$label} #{$this->application->id} awaiting your decision")
            ->greeting('Hi '.$notifiable->name.',')
            ->line("{$label} application #{$this->application->id} has been waiting on your ".
                "{$this->stage->label} decision for {$this->daysStalled} working ".str('day')->plural($this->daysStalled).'.')
            ->lineIf($this->charterDays !== null, "The CGS Client Charter for this stage is {$this->charterDays} working ".str('day')->plural($this->charterDays).'.')
            ->action('Review it now', route($this->application->module()->queueRoute(), ['stage' => $this->stage->key]))
            ->line('Thank you for using UResearch 2.0.');
    }
}
