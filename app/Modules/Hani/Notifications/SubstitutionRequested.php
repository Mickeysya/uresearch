<?php

namespace App\Modules\Hani\Notifications;

use App\Modules\Hani\Models\ExaminerSubstitutionRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * To the candidate's Academic Executive and supervisor: please replace this
 * seat. It names the examiner, so it never goes to the candidate. Queued and
 * sent after commit.
 */
class SubstitutionRequested extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected ExaminerSubstitutionRequest $substitution)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    protected function headline(): string
    {
        return 'Examiner substitution requested for application #'.$this->substitution->nomination->application_id;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $student = $this->substitution->nomination->application?->student;

        return (new MailMessage)
            ->subject($this->headline())
            ->greeting('Dear '.$notifiable->name.',')
            ->line('Examiner panel for '.($student?->name ?? 'a candidate').': please replace '
                .($this->substitution->examiner?->name ?? 'the examiner').' in the '.$this->substitution->seatLabel().' seat.')
            ->line('Reason: '.$this->substitution->reason)
            ->action('Replace the seat', route('examiner-substitution.show', $this->substitution))
            ->line('Suggested examiners are shown on that page.');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'application_id' => $this->substitution->nomination->application_id,
            'module' => 'Examiner Nomination',
            'title' => $this->headline().'.',
            'message' => 'Replace the '.$this->substitution->seatLabel().' seat. Reason: '.$this->substitution->reason,
            'url' => route('examiner-substitution.show', $this->substitution),
        ];
    }
}
