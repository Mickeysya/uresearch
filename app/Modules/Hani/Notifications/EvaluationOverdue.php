<?php

namespace App\Modules\Hani\Notifications;

use App\Modules\Hani\Models\ExaminerNomination;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * To the candidate's Academic Executive: the viva date has passed but the
 * evaluation has not been marked complete, so the examiners are still tied up
 * and their 90-day gap has not started. Sent by `examiners:sweep`, queued,
 * after commit.
 */
class EvaluationOverdue extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected ExaminerNomination $nomination,
        protected CarbonInterface $vivaAt,
        protected int $daysSince,
    ) {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    protected function headline(): string
    {
        return 'Please mark the evaluation complete for '.($this->nomination->application?->student?->name ?? 'a candidate');
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->headline())
            ->greeting('Dear '.$notifiable->name.',')
            ->line('The viva voce for '.($this->nomination->application?->student?->name ?? 'this candidate')
                .' took place on '.$this->vivaAt->format('j F Y').' ('.$this->daysSince.' days ago), but the evaluation has not been marked complete.')
            ->line('Until it is, the examiners stay tied up and their cooling-off period does not start.')
            ->action('Open Pending Evaluation', route('examiner-nomination.pending-evaluation'));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'application_id' => $this->nomination->application_id,
            'module' => 'Examiner Nomination',
            'title' => $this->headline().'.',
            'message' => 'The viva was '.$this->daysSince.' days ago. Open Pending Evaluation.',
        ];
    }
}
