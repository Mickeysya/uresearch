<?php

namespace App\Modules\Haziq\Notifications;

use App\Modules\Core\Models\Application;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to whoever flagged the proposed_allowance (via
 * AllowanceRecommendationNote), once CGS revises the figure — closes the
 * loop so a Supervisor/Senior Director who raised a concern finds out it
 * was actually looked at, not just that the number quietly changed.
 */
class AllowanceRecommendationUpdated extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected Application $application, protected float $newAmount) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'application_id' => $this->application->id,
            'action_url' => route('gra-application.show', $this->application),
            'title' => "CGS revised the proposed allowance — {$this->application->student->name}",
            'body' => 'New figure: RM '.number_format($this->newAmount, 2),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Proposed allowance revised — {$this->application->student->name}")
            ->greeting('Hi '.$notifiable->name.',')
            ->line("CGS revised the proposed allowance on {$this->application->student->name}'s GRA Application #{$this->application->id}, following your flag.")
            ->line('New figure: RM '.number_format($this->newAmount, 2))
            ->action('Review it', route('gra-application.show', $this->application));
    }
}
