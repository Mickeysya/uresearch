<?php

namespace App\Modules\Haziq\Notifications;

use App\Modules\Core\Models\Application;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Any approver (Admin, CGS Staff, Supervisor, Senior Director) flagged one
 * or more sections/documents in a single submission on an otherwise
 * still-pending application — requesterRole is carried so the student knows
 * who asked, since this is no longer Admin-exclusive. One notification for
 * the whole batch, not one per flagged item.
 */
class ApplicationCorrectionRequested extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param  array<int, string>  $targetLabels */
    public function __construct(
        protected Application $application,
        protected array $targetLabels,
        protected string $remarks,
        protected string $requesterRole,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toArray(object $notifiable): array
    {
        $count = count($this->targetLabels);

        return [
            'application_id' => $this->application->id,
            'title' => $count === 1
                ? "Please update: {$this->targetLabels[0]}"
                : "{$count} items need updating",
            'body' => implode(', ', $this->targetLabels)." — {$this->remarks} (flagged by {$this->requesterRole})",
            // Read by NotificationController::destinationFor() — clicking
            // this notification in the feed goes straight to the edit
            // form, not the generic tracking page.
            'action_url' => route($this->resubmitRoute(), $this->application),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $resubmitRoute = $this->resubmitRoute();
        $count = count($this->targetLabels);

        $mail = (new MailMessage)
            ->subject('Application update needed — '.$this->application->reference())
            ->greeting('Hi '.$notifiable->name.',')
            ->line("Your {$this->application->module()->label()} application needs the following updated before it can continue:");

        foreach ($this->targetLabels as $label) {
            $mail->line("- {$label}");
        }

        return $mail
            ->line("Reason: {$this->remarks}")
            ->line("Flagged by: {$this->requesterRole}")
            ->line($count === 1
                ? 'Your application has not been rejected — it is waiting on this one item.'
                : 'Your application has not been rejected — it is waiting on these items.')
            ->action('Make the correction', route($resubmitRoute, $this->application));
    }

    protected function resubmitRoute(): string
    {
        return str_replace('_', '-', $this->application->module_type).'.correction.edit';
    }
}
