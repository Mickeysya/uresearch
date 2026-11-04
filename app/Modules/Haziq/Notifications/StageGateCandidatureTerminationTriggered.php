<?php

namespace App\Modules\Haziq\Notifications;

use App\Modules\Haziq\Models\StageGate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * One-time notice: a non-recoverable (Stage 2+) gate's grace period ended
 * without completion. This module only raises the flag — see
 * StageGateTerminationTrigger and the module's README for why it does not
 * itself decide candidature status.
 */
class StageGateCandidatureTerminationTriggered extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected StageGate $gate, protected bool $forStudent) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'stage_gate_id' => $this->gate->id,
            'application_id' => $this->gate->application_id,
            'title' => $this->forStudent
                ? 'Your GRA/GA appointment has been flagged for termination review.'
                : 'Candidature termination review required.',
            'body' => "Stage {$this->gate->stage_order} ({$this->gate->stage_name}) was not completed within its grace period.",
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        if ($this->forStudent) {
            return (new MailMessage)
                ->subject('GRA/GA appointment flagged — '.$this->gate->stage_name)
                ->greeting('Hi '.$notifiable->name.',')
                ->line("Your Stage {$this->gate->stage_order} requirement — {$this->gate->stage_name} — was not recorded as complete within its grace period, which ended {$this->gate->grace_end_date->format('j M Y')}.")
                ->line('This has been flagged for a candidature termination review. This stage is not recoverable.')
                ->line('Please contact CGS if you believe this is a mistake.');
        }

        return (new MailMessage)
            ->subject('Stage Gate termination trigger — review required')
            ->greeting('Hi '.$notifiable->name.',')
            ->line("A student's Stage {$this->gate->stage_order} ({$this->gate->stage_name}) grace period ended without completion being recorded.")
            ->line('This has been logged as a candidature termination trigger and requires review.')
            ->action('Review Stage Gate', route('stage-gate.index'));
    }
}
