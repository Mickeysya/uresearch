<?php

namespace App\Modules\Hani\Notifications;

use App\Modules\Core\Models\Application;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A softbound attempt returned to the student.
 *
 * The engine's own ApplicationDecided still fires and calls it "the final
 * outcome", which is true of this attempt and wrong about the viva: the
 * notice stays open and the student files again. This says so, and quotes
 * what the AE asked for. Same approach as Jason's HardboundSubmissionRejected.
 */
class VivaSoftboundReturned extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected Application $application,
        protected string $returnedBy,
        protected string $reason,
    ) {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Softbound thesis returned: '.$this->application->reference())
            ->greeting('Hi '.$notifiable->name.',')
            ->line("{$this->returnedBy} has returned your softbound thesis for corrections.")
            ->line('What to fix: '.$this->reason)
            ->line('Your viva is still open. Fix the points above and submit the softbound thesis again.')
            ->action('Submit it again', route('viva.softbound.create'))
            ->line('Thank you for using UResearch 2.0.');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'application_id' => $this->application->id,
            'module' => $this->application->module()->label(),
            'reference' => $this->application->reference(),
            'title' => 'Your softbound thesis was returned for corrections.',
            'message' => $this->reason,
        ];
    }
}
