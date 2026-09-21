<?php

namespace App\Modules\Core\Notifications;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Support\Stage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent when WorkflowEngine::returnTo() sends an application backwards.
 *
 * Separate from ApplicationDecided rather than a third state on its boolean:
 * that class is queued, so its constructor signature is serialised into jobs
 * that may still be in flight, and a return says something genuinely
 * different anyway -- nothing was refused, someone has to do a piece of work
 * again.
 *
 * One class, two audiences. The student is told their application went back;
 * whoever owns the stage it went back TO is told there is work waiting. The
 * wording branches on which of the two is reading it, because "your
 * application" is wrong in an Academic Executive's inbox.
 */
class ApplicationReturned extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected Application $application,
        protected Stage $from,
        protected Stage $target,
        protected string $remarks,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    /** True when the person reading this is the candidate the application is about. */
    protected function isStudent(object $notifiable): bool
    {
        return $this->application->student
            && $notifiable instanceof \Illuminate\Database\Eloquent\Model
            && $notifiable->is($this->application->student);
    }

    /**
     * Keep the keys stable -- rows already written carry them. `approved` is
     * present and false so the dashboard's notification feed, which reads it
     * from ApplicationDecided rows, tones this the same way.
     */
    public function toArray(object $notifiable): array
    {
        $module = $this->application->module()->label();

        return [
            'application_id' => $this->application->id,
            'module' => $module,
            'approved' => false,
            'returned' => true,
            'stage_label' => $this->from->label,
            'next_stage' => $this->target->label,
            'remarks' => $this->remarks,
            'title' => $this->isStudent($notifiable)
                ? "Your {$module} application was returned to the {$this->target->label} stage."
                : "A {$module} application is back with you from {$this->from->label}.",
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $module = $this->application->module()->label();
        $ref = $module.' Application #'.$this->application->id;

        $mail = (new MailMessage)
            ->subject($ref.': Returned for another look')
            ->greeting('Hi '.$notifiable->name.',');

        if ($this->isStudent($notifiable)) {
            return $mail
                ->line("Your {$module} application has been sent back from the {$this->from->label} stage "
                    ."to {$this->target->label}.")
                ->line('It has not been rejected — it is being worked on again.')
                ->line('Reason given: '.$this->remarks)
                ->action('Track your application', route('applications.index'))
                ->line('You will hear again when it moves on.');
        }

        return $mail
            ->line("{$ref} has been returned to you by {$this->from->label}.")
            ->line('Reason given: '.$this->remarks)
            ->line('It is waiting at your stage again and will follow the same chain once you are done with it.')
            ->action('Open your queue', route('dashboard'))
            ->line('Thank you for using UResearch 2.0.');
    }
}
