<?php

namespace App\Modules\Hani\Notifications;

use App\Modules\Core\Models\Application;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the student's Academic Executive that something has landed in one of
 * their viva queues: a new notice, or a softbound thesis to check.
 *
 * WorkflowEngine only notifies the student, never the approver, so without
 * this the AE finds new work only by opening the queue. The in-app copy
 * raises the unread badge on the sidebar's Notification link; the email
 * carries the link to the queue itself, since following an in-app
 * notification keeps staff on the feed (NotificationController).
 */
class VivaAwaitingAcademicExec extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  string  $what  e.g. "Notice of Thesis Submission"
     * @param  string  $queueRoute  where the AE acts on it
     */
    public function __construct(
        protected Application $application,
        protected string $what,
        protected string $queueRoute,
        protected bool $resubmitted = false,
    ) {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    protected function headline(): string
    {
        $student = $this->application->student;

        return ($this->resubmitted ? "Resubmitted {$this->what}" : "New {$this->what}")
            .' from '.$student?->name.($student?->matric_no ? " ({$student->matric_no})" : '');
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->headline())
            ->greeting('Hi '.$notifiable->name.',')
            ->line($this->headline().' is waiting for you in UResearch.')
            ->line('Reference: '.$this->application->reference())
            ->action('Open your queue', route($this->queueRoute))
            ->line('Thank you for using UResearch 2.0.');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'application_id' => $this->application->id,
            'module' => $this->application->module()->label(),
            'reference' => $this->application->reference(),
            'title' => $this->headline().'.',
            'message' => 'Open '.$this->application->module()->label().' under Pending My Action.',
        ];
    }
}
