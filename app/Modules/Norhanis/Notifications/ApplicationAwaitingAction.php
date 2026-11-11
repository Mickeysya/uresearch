<?php

namespace App\Modules\Norhanis\Notifications;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Support\Stage;
use App\Modules\Norhanis\Models\TravelDetail;
use App\Modules\Norhanis\Support\StageAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells an approver that a Travel, Claims or Publication application is
 * waiting on their stage. See ApproverNotifier for who receives it.
 */
class ApplicationAwaitingAction extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected Application $application,
        protected Stage $stage,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $student = $this->application->student;

        $mail = (new MailMessage)
            ->subject($this->subject())
            ->greeting('Hi '.$notifiable->name.',')
            ->line("A {$this->module()} application is waiting for your {$this->action()->noun()} at the {$this->stage->label} stage.")
            ->line('Student: '.($student?->name ?? 'Unknown').($student?->matric_no ? " ({$student->matric_no})" : ''))
            ->line('Application: '.$this->application->module()->summary($this->application));

        if ($type = $this->travelRequestType()) {
            $mail->line('Type of Request: '.$type);
        }

        return $mail
            ->action('Open the application', $this->url())
            ->line("You can {$this->verb()} or reject it from your queue.")
            ->line('Thank you for using UResearch 2.0.');
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

    /** Worded like Core's ApplicationDecided, so a student's and an approver's inbox match. */
    protected function subject(): string
    {
        return "{$this->module()} Application #{$this->application->id}: Awaiting your ".$this->action()->noun();
    }

    /** Travel's type of request, with the "Others" description only when there is one. */
    protected function travelRequestType(): ?string
    {
        if ($this->application->module_type !== 'travel'
            || ! $detail = TravelDetail::where('application_id', $this->application->id)->first()) {
            return null;
        }

        $label = TravelDetail::requestTypes()[$detail->type_of_request] ?? $detail->type_of_request;

        return $detail->other_request_specify ? "{$label} ({$detail->other_request_specify})" : $label;
    }

    /** What this stage does: endorse, review or approve. See Support\StageAction. */
    protected function action(): StageAction
    {
        return StageAction::forStage($this->stage);
    }

    protected function verb(): string
    {
        return strtolower($this->action()->label());
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
