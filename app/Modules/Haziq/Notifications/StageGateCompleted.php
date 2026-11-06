<?php

namespace App\Modules\Haziq\Notifications;

use App\Modules\Haziq\Models\StageGate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * When CGS marks a stage complete, everyone involved finds out — not just
 * the student. Confirmed explicitly 2026-10-15: the monthly grace-period
 * reminder stays student-only, but this one goes wider the moment CGS
 * actually acts on it.
 */
class StageGateCompleted extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected StageGate $gate, protected bool $forStudent) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toArray(object $notifiable): array
    {
        $backdated = $this->gate->allowance_status === 'backdated';
        $student = $this->gate->application->student;

        return [
            'stage_gate_id' => $this->gate->id,
            'application_id' => $this->gate->application_id,
            'title' => $this->forStudent
                ? "Stage {$this->gate->stage_order} ({$this->gate->stage_name}) marked complete."
                : "{$student->name} — Stage {$this->gate->stage_order} ({$this->gate->stage_name}) marked complete.",
            'body' => $backdated
                ? ($this->forStudent ? 'Your withheld allowance for this period will be back-paid.' : 'Withheld allowance for this period will be back-paid.')
                : ($this->forStudent ? 'Your allowance eligibility continues as normal.' : 'Allowance eligibility continues as normal.'),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        if ($this->forStudent) {
            $mail = (new MailMessage)
                ->subject('Stage Gate completed — '.$this->gate->stage_name)
                ->greeting('Hi '.$notifiable->name.',')
                ->line("CGS has recorded Stage {$this->gate->stage_order} ({$this->gate->stage_name}) as complete.");

            if ($this->gate->allowance_status === 'backdated') {
                $mail->line('Your withheld allowance for the months since your grace period ended will be back-paid.');
            }

            return $mail->action('View your Stage Gate', route('applications.index'));
        }

        $student = $this->gate->application->student;

        $mail = (new MailMessage)
            ->subject("Stage Gate completed — {$student->name}")
            ->greeting('Hi '.$notifiable->name.',')
            ->line("CGS has recorded {$student->name}'s Stage {$this->gate->stage_order} ({$this->gate->stage_name}) as complete.");

        if ($this->gate->allowance_status === 'backdated') {
            $mail->line('Their withheld allowance for the months since the grace period ended will be back-paid.');
        }

        return $mail->action('Review Stage Gate', route('stage-gate.index'));
    }
}
