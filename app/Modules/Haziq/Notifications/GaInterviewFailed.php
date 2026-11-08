<?php

namespace App\Modules\Haziq\Notifications;

use App\Modules\Core\Models\Application;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Admin, the full CGS team, and the student's own Supervisor find out the
 * moment Research Centre fails an interview — confirmed explicitly
 * 2026-11-06: "sepatutnya even yang fail interview semua boleh tengok
 * dapat noti, tapi no actions." They were already able to *see* the
 * failed application (index()/show() never filtered it out, and
 * decide()'s own "already decided" guard already blocks any further
 * action on it) — what was actually missing was telling them it
 * happened at all. The student gets the separate, student-facing
 * ApplicationRejected notification alongside this one; this is purely
 * the rest of the chain's copy, worded for an audience that can see the
 * record but can't act on it.
 */
class GaInterviewFailed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected Application $application, protected string $reason) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toArray(object $notifiable): array
    {
        $this->application->loadMissing('student');

        return [
            'application_id' => $this->application->id,
            'action_url' => route('ga-application.show', $this->application),
            'title' => "{$this->application->student->name} failed the Research Centre interview",
            'body' => $this->reason,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->application->loadMissing('student');
        $student = $this->application->student;

        return (new MailMessage)
            ->subject("GA interview failed — {$student->name}")
            ->greeting('Hi '.$notifiable->name.',')
            ->line("{$student->name}'s {$this->application->reference()} GA application failed the Research Centre interview and will not proceed any further.")
            ->line('Reason: '.$this->reason)
            ->action('View the record', route('ga-application.show', $this->application));
    }
}
