<?php

namespace App\Modules\Hani\Notifications;

use App\Modules\Hani\Models\Examiner;
use App\Modules\Hani\Models\ExaminerNomination;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * To Non-Executive CGS when a released panel's main seat changes hands -- a
 * backup promoted after a decline, or a replacement appointed -- because the
 * appointment letter (Jason's module) now has to go to someone new. Queued,
 * after commit.
 */
class PanelSeatChanged extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected ExaminerNomination $nomination,
        protected Examiner $examiner,
        protected string $why,
        protected ?Examiner $replacing = null,
    ) {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function headline(): string
    {
        return ($this->why === 'promoted' ? 'Backup promoted' : 'Replacement examiner appointed')
            .' — please send the appointment letter to '.$this->examiner->name;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $student = $this->nomination->application?->student;

        $mail = (new MailMessage)
            ->subject($this->headline())
            ->greeting('Dear '.$notifiable->name.',')
            ->line('Examiner panel for '.($student?->name ?? 'a candidate').' (application #'.$this->nomination->application_id.').');

        if ($this->replacing) {
            $mail->line("{$this->replacing->name} declined. {$this->examiner->name} has taken the seat.");
        }

        $mail->line($this->examiner->emailNeedsUpdating()
            ? $this->examiner->emailProblem().' Please obtain a current one before sending the letter.'
            : 'Email: '.$this->examiner->email);

        return $mail->action('Open the Examiner Report', route('examiner-nomination.report'));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'application_id' => $this->nomination->application_id,
            'module' => 'Examiner Nomination',
            'title' => $this->headline().'.',
            'message' => 'Application #'.$this->nomination->application_id.'. Open the Examiner Report for the current panel.',
        ];
    }
}
