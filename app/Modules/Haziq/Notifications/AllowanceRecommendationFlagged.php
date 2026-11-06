<?php

namespace App\Modules\Haziq\Notifications;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the CGS team when a Supervisor or Senior Director flags the
 * proposed_allowance CGS recommended, with a reason — internal, CGS-facing
 * only. The student is never a recipient of this notification, same as they
 * never see the AllowanceRecommendationNote itself.
 */
class AllowanceRecommendationFlagged extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected Application $application, protected User $flaggedBy, protected string $reason) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'application_id' => $this->application->id,
            'action_url' => route('gra-application.show', $this->application),
            'title' => "{$this->flaggedBy->roleLabel()} flagged the proposed allowance — {$this->application->student->name}",
            'body' => $this->reason,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Proposed allowance flagged — {$this->application->student->name}")
            ->greeting('Hi '.$notifiable->name.',')
            ->line("{$this->flaggedBy->name} ({$this->flaggedBy->roleLabel()}) flagged the proposed allowance on {$this->application->student->name}'s GRA Application #{$this->application->id}.")
            ->line("Reason: {$this->reason}")
            ->action('Review it', route('gra-application.show', $this->application));
    }
}
