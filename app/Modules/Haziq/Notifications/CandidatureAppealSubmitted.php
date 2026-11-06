<?php

namespace App\Modules\Haziq\Notifications;

use App\Modules\Core\Models\Application;
use App\Modules\Haziq\Models\CandidatureAppeal;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to Admin + CGS + the student's own assigned Supervisor the moment a
 * student appeals their Stage Gate termination — same audience as every
 * other Stage Gate consequence (see StageGateEngine::notifyEveryone()),
 * though only a Senior Director can actually decide it; everyone else in
 * this audience is informed, not an approver of this specific action.
 */
class CandidatureAppealSubmitted extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected CandidatureAppeal $appeal, protected Application $application) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'application_id' => $this->application->id,
            'action_url' => route('candidature-appeal.queue'),
            'title' => "Candidature appeal — {$this->application->student->name}",
            'body' => "{$this->application->student->name} has appealed their Stage Gate termination on {$this->application->reference()}. Only a Senior Director can decide it.",
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Candidature appeal — {$this->application->student->name}")
            ->greeting('Hi '.$notifiable->name.',')
            ->line("{$this->application->student->name} has appealed their Stage Gate termination on {$this->application->reference()}.")
            ->line('Only a Senior Director can approve or reject this appeal.')
            ->action('Review the appeal', route('candidature-appeal.queue'));
    }
}
