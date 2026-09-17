<?php

namespace App\Modules\Jason\Notifications;

use App\Modules\Core\Models\Application;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the candidate an examiner panel has been nominated for them.
 *
 * Every other chain is started by the student, so the engine has never
 * needed to announce a submission. This one is filed by the Chair on the
 * student's behalf -- without this an application simply appears on their
 * tracking page with no explanation.
 *
 * Carries the same data keys as Core's ApplicationDecided so the dashboard
 * panel and the notification feed render it without knowing it exists.
 */
class ExaminerPanelNominated extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param  array<int, string>  $examiners  "Name (internal)" lines */
    public function __construct(
        protected Application $application,
        protected string $chairName,
        protected array $examiners,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toArray(object $notifiable): array
    {
        $count = count($this->examiners);

        return [
            'application_id' => $this->application->id,
            'module' => 'Appointment Letter',
            'approved' => true,
            'stage_label' => 'Chair of Department',
            'title' => "{$this->chairName} has nominated {$count} examiners for your thesis: "
                .implode(', ', $this->examiners).'. The nomination now goes to the Academic Executive for endorsement.',
            'next_stage' => 'Academic Executive',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject("Examiner panel nominated for Application #{$this->application->id}")
            ->greeting("Dear {$notifiable->name},")
            ->line("{$this->chairName}, Chair of Department, has nominated an examiner panel for your thesis:");

        foreach ($this->examiners as $examiner) {
            $mail->line("• {$examiner}");
        }

        return $mail
            ->line('The nomination now goes to the Academic Executive for endorsement, then to CGS to prepare the appointment letters, and finally to the Dean for approval. You will be notified at each step.')
            ->action('Track this application', route('applications.show', $this->application))
            ->salutation('Centre for Graduate Studies, Universiti Teknologi PETRONAS');
    }
}
