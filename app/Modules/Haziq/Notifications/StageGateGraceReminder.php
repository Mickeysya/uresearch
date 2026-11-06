<?php

namespace App\Modules\Haziq\Notifications;

use App\Modules\Haziq\Models\StageGate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent once per calendar month while a stage gate sits in its grace period —
 * to the student (what's at stake for them) and, separately, to the CGS team
 * (who is overdue and needs their action), since CGS is who actually records
 * completion.
 */
class StageGateGraceReminder extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected StageGate $gate, protected bool $forStudent) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toArray(object $notifiable): array
    {
        $consequence = $this->gate->isRecoverable()
            ? 'the GRA allowance will be suspended'
            : 'the GRA/GA appointment will be flagged for termination review';

        $student = $this->gate->application->student;

        return [
            'stage_gate_id' => $this->gate->id,
            'application_id' => $this->gate->application_id,
            'title' => $this->forStudent
                ? "Stage {$this->gate->stage_order} ({$this->gate->stage_name}) is still not recorded as complete."
                : "{$student->name} — Stage {$this->gate->stage_order} ({$this->gate->stage_name}) is overdue.",
            'body' => "If this is not completed by {$this->gate->grace_end_date->format('j M Y')}, {$consequence}.",
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $consequence = $this->gate->isRecoverable()
            ? 'the GRA allowance will be suspended (recoverable once the requirement is met).'
            : 'the GRA/GA appointment will be flagged for a candidature termination review. This is not recoverable.';

        if ($this->forStudent) {
            return (new MailMessage)
                ->subject("Stage Gate reminder — {$this->gate->stage_name}")
                ->greeting('Hi '.$notifiable->name.',')
                ->line("Your Stage {$this->gate->stage_order} requirement — {$this->gate->stage_name} — has not yet been recorded as complete by CGS.")
                ->line("Grace period ends: {$this->gate->grace_end_date->format('j M Y')}.")
                ->line("If it is not completed by then, {$consequence}")
                ->line('If you have already fulfilled this requirement, make sure your evidence has been submitted to CGS.');
        }

        $student = $this->gate->application->student;

        return (new MailMessage)
            ->subject("Stage Gate overdue — {$student->name}")
            ->greeting('Hi '.$notifiable->name.',')
            ->line("{$student->name}'s Stage {$this->gate->stage_order} requirement — {$this->gate->stage_name} — has not been recorded as complete.")
            ->line("Grace period ends: {$this->gate->grace_end_date->format('j M Y')}.")
            ->line("If it is not recorded by then, {$consequence}")
            ->action('Review Stage Gate', route('stage-gate.index'));
    }
}
