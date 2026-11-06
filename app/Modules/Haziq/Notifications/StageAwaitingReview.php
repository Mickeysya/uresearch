<?php

namespace App\Modules\Haziq\Notifications;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Support\Stage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to every user holding the role an application just advanced to.
 * Core's WorkflowEngine::decide() only ever notifies the student
 * (ApplicationDecided) — nothing in the codebase pings the *next* approver
 * that something has landed on their desk. Added here, scoped to this
 * module, rather than as a Core change affecting every module's chain.
 */
class StageAwaitingReview extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected Application $application, protected Stage $stage) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'application_id' => $this->application->id,
            'title' => "{$this->application->module()->label()} awaiting your {$this->stage->label} review",
            'body' => $this->application->module()->summary($this->application),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Action needed — {$this->application->module()->label()} #{$this->application->id}")
            ->greeting('Hi '.$notifiable->name.',')
            ->line("A {$this->application->module()->label()} application is now awaiting your {$this->stage->label} review.")
            ->line($this->application->module()->summary($this->application))
            ->action('Review it', route($this->application->module()->queueRoute(), ['stage' => $this->stage->key]));
    }
}
