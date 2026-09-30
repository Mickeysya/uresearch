<?php

namespace App\Modules\Jason\Notifications;

use App\Modules\Core\Models\Application;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells everyone the appointment concerns that the Dean has approved the
 * panel and the letters have gone out: the candidate, their supervisor, the
 * Academic Executive and CGS.
 *
 * The engine's own ApplicationDecided says only "approved at the Dean of PGR
 * stage", and it goes to the candidate alone. This says what that means and
 * who the examiners are, and it is worded from the recipient's side -- "your
 * thesis" to the candidate, "your candidate" to the supervisor, and a plain
 * statement of record to the Academic Executive and CGS, who chose the panel
 * and now need to know it is real.
 */
class ExaminersAppointed extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param  array<int, string>  $examiners  "Name (internal)" lines */
    public function __construct(
        protected Application $application,
        protected array $examiners,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'application_id' => $this->application->id,
            'module' => 'Appointment Letter',
            'approved' => true,
            'stage_label' => 'Dean of PGR',
            'title' => $this->whose($notifiable).' examiners have been appointed: '
                .implode(', ', $this->examiners)
                .'. Their appointment letters and thesis evaluation report forms have been sent to them.',
            'next_stage' => null,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $candidate = $this->application->student?->name ?? 'the candidate';

        $mail = (new MailMessage)
            ->subject("Examiners appointed for Application #{$this->application->id}")
            ->greeting("Dear {$notifiable->name},")
            ->line($this->isCandidate($notifiable)
                ? 'The Dean of Postgraduate and Research has approved the examiner panel for your thesis:'
                : "The Dean of Postgraduate and Research has approved the examiner panel for {$candidate}:");

        foreach ($this->examiners as $examiner) {
            $mail->line("• {$examiner}");
        }

        $mail->line('Each examiner has been sent their appointment letter and the thesis evaluation report form. '
            .'Copies are archived on the application.');

        // Only the candidate can open their own application; everyone else
        // would be sent to a 403.
        if ($this->isCandidate($notifiable)) {
            $mail->action('View the appointment documents', route('applications.show', $this->application));
        }

        return $mail->salutation('Centre for Graduate Studies, Universiti Teknologi PETRONAS');
    }

    protected function isCandidate(object $notifiable): bool
    {
        return $notifiable->getKey() === $this->application->student_id;
    }

    /** "Your" to the candidate, the candidate's name to everybody else. */
    protected function whose(object $notifiable): string
    {
        if ($this->isCandidate($notifiable)) {
            return 'Your';
        }

        $candidate = $this->application->student?->name;

        return $candidate ? $candidate."'s" : "The candidate's";
    }
}
