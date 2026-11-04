<?php

namespace App\Modules\Haziq\Notifications;

use App\Modules\Core\Models\Application;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The student resubmitted after one or more of the four approver roles
 * flagged a correction — sent to whichever of them actually asked, not a
 * blanket "notify Admin" (that was correct back when only Admin could flag
 * anything; it stopped being correct the moment that changed).
 */
class ApplicationCorrectionResubmitted extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected Application $application) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'application_id' => $this->application->id,
            'title' => 'Application resubmitted',
            'body' => "{$this->application->student?->name} has resubmitted corrections on their application.",
            'action_url' => $this->destinationUrl(),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Application resubmitted — '.$this->application->reference())
            ->greeting('Hi '.$notifiable->name.',')
            ->line("{$this->application->student?->name} has resubmitted the requested corrections.")
            ->action('Review application', $this->destinationUrl());
    }

    /**
     * GRA has an applicant list every approver can always open
     * (gra-application.index), which stays correct even for an approver
     * whose own stage this application hasn't reached yet. GA has no such
     * list, so its queue route is the next best thing there.
     */
    protected function destinationUrl(): string
    {
        return $this->application->module_type === 'gra_application'
            ? route('gra-application.index')
            : route($this->application->module()->queueRoute());
    }
}
