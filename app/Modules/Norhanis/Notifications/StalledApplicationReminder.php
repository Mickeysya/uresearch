<?php

namespace App\Modules\Norhanis\Notifications;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Support\Stage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Reminds an approver that a Travel, Claims or Publication application has
 * waited on their stage longer than the stage should take. Sent by
 * norhanis:remind-stalled; ApproverNotifier decides who holds the stage.
 */
class StalledApplicationReminder extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected Application $application,
        protected Stage $stage,
        protected int $waitedDays,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $student = $this->application->student;

        return (new MailMessage)
            ->subject($this->subject())
            ->greeting('Hi '.$notifiable->name.',')
            ->line("This {$this->module()} application has been waiting for you at the {$this->stage->label} stage for {$this->days()}.")
            ->line('Student: '.($student?->name ?? 'Unknown').($student?->matric_no ? " ({$student->matric_no})" : ''))
            ->line('Application: '.$this->application->module()->summary($this->application))
            ->action('Review the application', $this->url())
            ->line('The student has been told that a reminder was sent.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'application_id' => $this->application->id,
            'module' => $this->module(),
            'stage_label' => $this->stage->label,
            'title' => $this->subject(),
            'url' => $this->url(),
        ];
    }

    /** Worded like ApplicationAwaitingAction, so the two read as a pair in an inbox. */
    protected function subject(): string
    {
        return "{$this->module()} Application #{$this->application->id}: Reminder, still awaiting your action";
    }

    protected function days(): string
    {
        return $this->waitedDays.' working '.($this->waitedDays === 1 ? 'day' : 'days');
    }

    protected function module(): string
    {
        return $this->application->module()->label();
    }

    /** The approver's queue, opened on this stage and filtered to this application. */
    protected function url(): string
    {
        return route($this->application->module()->queueRoute(), [
            'stage' => $this->stage->key,
            'q' => '#'.$this->application->id,
        ]);
    }
}
