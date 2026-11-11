<?php

namespace App\Modules\Hani\Notifications;

use App\Modules\Core\Models\Application;
use App\Modules\Hani\Models\VivaDetail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The student's receipt for their Notice of Thesis Submission.
 *
 * WorkflowEngine only notifies on decisions, so without this the student
 * hears nothing until the Academic Executive verifies the notice. The
 * notice starts the 3-month clock to softbound, so the date they committed
 * to is repeated back to them.
 */
class VivaNoticeSubmitted extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected Application $application,
        protected VivaDetail $detail,
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
            ->subject('Notice of Thesis Submission received: '.$this->application->reference())
            ->greeting('Hi '.$notifiable->name.',')
            ->line('Your Notice of Thesis Submission has been received.')
            ->line('Thesis: '.$this->detail->thesis_title)
            ->line('Expected softbound submission: '.$this->detail->expected_softbound_date->format('j M Y'))
            ->line('Your Academic Executive will verify it, and your supervisor will then be asked to nominate examiners.')
            ->action('Track your notice', route('applications.show', $this->application))
            ->line('Thank you for using UResearch 2.0.');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'application_id' => $this->application->id,
            'module' => $this->application->module()->label(),
            'reference' => $this->application->reference(),
            'title' => 'Your Notice of Thesis Submission has been received.',
            'message' => 'Expected softbound submission: '.$this->detail->expected_softbound_date->format('j M Y').'.',
        ];
    }
}
