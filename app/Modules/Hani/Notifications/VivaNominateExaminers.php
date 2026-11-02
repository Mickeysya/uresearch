<?php

namespace App\Modules\Hani\Notifications;

use App\Modules\Core\Models\Application;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Step 3 of the viva guideline: "AE will communicate with the Sv on the
 * appointment of examiners upon receiving the notice of thesis submission."
 *
 * Sent to the student's supervisor when the AE acknowledges the notice, and
 * points them at Examiner Nomination, which is where proposing examiners
 * actually happens. Only a prompt: the nomination itself is unchanged.
 */
class VivaNominateExaminers extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected Application $notice,
        protected string $thesisTitle,
        protected string $academicExecName,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    protected function student(): string
    {
        $student = $this->notice->student;

        return $student?->name.($student?->matric_no ? " ({$student->matric_no})" : '');
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Please nominate examiners for '.$this->notice->student?->name)
            ->greeting('Dear '.$notifiable->name.',')
            ->line($this->student().' has filed their Notice of Thesis Submission, and it has been acknowledged by '.$this->academicExecName.'.')
            ->line('Thesis: '.$this->thesisTitle)
            ->line('Please propose the examiners for their viva voce: one internal examiner from the candidate\'s department (not the supervisor or co-supervisor) and one external examiner, each with a backup.')
            ->action('Nominate Examiners', route('examiner-nomination.create'))
            ->line('The nomination then goes through DAC endorsement and FSMC-AC approval before the viva date can be set.');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'application_id' => $this->notice->id,
            'module' => $this->notice->module()->label(),
            'reference' => $this->notice->reference(),
            'title' => 'Please nominate examiners for '.$this->student().'.',
            'message' => 'Their Notice of Thesis Submission has been acknowledged. Use Nominate Examiners.',
        ];
    }
}
