<?php

namespace App\Modules\Haziq\Notifications;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Support\Stage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Core's ApplicationDecided already fires on every decision in every
 * module, but it never includes the rejecting remarks — its rejection
 * branch just says "not approved", no reason. Rather than change that
 * shared notification (it would affect every module's rejection email, not
 * only this one), this is a second, GRA/GA-specific notification sent
 * alongside it only when the decision is a reject with a reason attached,
 * so the student actually sees why.
 */
class ApplicationRejected extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected Application $application,
        protected Stage $stage,
        protected string $reason,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'application_id' => $this->application->id,
            'title' => "Rejected at {$this->stage->label}",
            'body' => $this->reason,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Application rejected — '.$this->application->reference())
            ->greeting('Hi '.$notifiable->name.',')
            ->line("Your {$this->application->module()->label()} application was rejected at the {$this->stage->label} stage.")
            ->line('Reason: '.$this->reason)
            ->line('You may submit a new application whenever you are ready.')
            ->action('Apply again', route($this->application->module()->createRoute()));
    }
}
