<?php

namespace App\Modules\Haziq\Notifications;

use App\Modules\Haziq\Models\StageGate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * One-time notice: a recoverable (Stage 1) gate's grace period ended
 * without completion. Goes to everyone in the chain, not just the
 * student — confirmed explicitly 2026-10-15.
 */
class StageGateAllowanceWithheld extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected StageGate $gate, protected bool $forStudent) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toArray(object $notifiable): array
    {
        $student = $this->gate->application->student;

        return [
            'stage_gate_id' => $this->gate->id,
            'application_id' => $this->gate->application_id,
            'title' => $this->forStudent
                ? 'Your GRA allowance has been withheld.'
                : "{$student->name} — GRA allowance withheld.",
            'body' => "Stage {$this->gate->stage_order} ({$this->gate->stage_name}) was not completed within its grace period. ".
                'Once CGS records it as complete, withheld months will be back-paid.',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        if ($this->forStudent) {
            return (new MailMessage)
                ->subject('GRA allowance withheld — '.$this->gate->stage_name)
                ->greeting('Hi '.$notifiable->name.',')
                ->line("Your Stage {$this->gate->stage_order} requirement — {$this->gate->stage_name} — was not recorded as complete within its grace period, which ended {$this->gate->grace_end_date->format('j M Y')}.")
                ->line('Your GRA allowance is now withheld.')
                ->line('This is recoverable: once the requirement is fulfilled and CGS records it as complete, the withheld months will be back-paid in full.')
                ->action('View Stage Gate status', route('applications.index'));
        }

        $student = $this->gate->application->student;

        return (new MailMessage)
            ->subject("GRA allowance withheld — {$student->name}")
            ->greeting('Hi '.$notifiable->name.',')
            ->line("{$student->name}'s Stage {$this->gate->stage_order} requirement — {$this->gate->stage_name} — was not recorded as complete within its grace period, which ended {$this->gate->grace_end_date->format('j M Y')}.")
            ->line('Their GRA allowance is now withheld (recoverable once CGS records it as complete).')
            ->action('Review Stage Gate', route('stage-gate.index'));
    }
}
