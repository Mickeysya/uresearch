<?php

namespace App\Modules\Haziq\Notifications;

use App\Modules\Core\Models\Application;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Candidature reinstated — the student's own appointment goes back to
 * active. Deliberately silent on timeline_status/allowance_status: the
 * appeal only ever reinstates candidature; see
 * StageGateEngine::reinstateCandidature()'s docblock for why those stay
 * exactly as they were.
 *
 * Student + everyone — confirmed explicitly 2026-10-22, same stakeholder
 * list as the reminder/withheld/terminated emails (Admin, CGS,
 * Supervisor, Senior Director); forStudent switches the phrasing the
 * same way every other Stage Gate notification already does.
 */
class CandidatureAppealApproved extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected Application $application, protected bool $forStudent = true) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toArray(object $notifiable): array
    {
        $student = $this->application->student;

        return [
            'application_id' => $this->application->id,
            'action_url' => route('stage-gate.index'),
            'title' => $this->forStudent
                ? 'Your candidature appeal was approved'
                : "{$student->name} — candidature appeal approved",
            'body' => $this->forStudent
                ? "Your {$this->application->reference()} appointment is active again."
                : "{$student->name}'s {$this->application->reference()} appointment is active again.",
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        if ($this->forStudent) {
            return (new MailMessage)
                ->subject('Your candidature appeal was approved')
                ->greeting('Hi '.$notifiable->name.',')
                ->line("Your appeal against the Stage Gate termination on {$this->application->reference()} has been approved by a Senior Director.")
                ->line('Your candidature is active again.')
                ->action('View your Stage Gate progress', route('stage-gate.index'));
        }

        $student = $this->application->student;

        return (new MailMessage)
            ->subject("Candidature appeal approved — {$student->name}")
            ->greeting('Hi '.$notifiable->name.',')
            ->line("{$student->name}'s appeal against the Stage Gate termination on {$this->application->reference()} has been approved by a Senior Director.")
            ->line('Their candidature is active again.')
            ->action('Review Stage Gate', route('stage-gate.index'));
    }
}
