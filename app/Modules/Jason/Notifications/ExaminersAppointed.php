<?php

namespace App\Modules\Jason\Notifications;

use App\Modules\Core\Models\Application;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the candidate their examiners have been appointed and the letters
 * have gone out. The engine's own ApplicationDecided says only "approved at
 * the Dean of PGR stage"; this says what that means and who the examiners
 * are, which is the thing the candidate actually wants to know.
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
            'title' => 'Your examiners have been appointed: '.implode(', ', $this->examiners)
                .'. Their appointment letters and thesis evaluation report forms have been sent to them.',
            'next_stage' => null,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject("Examiners appointed — Application #{$this->application->id}")
            ->greeting("Dear {$notifiable->name},")
            ->line('The Dean of Postgraduate and Research has approved the examiner panel for your thesis:');

        foreach ($this->examiners as $examiner) {
            $mail->line("• {$examiner}");
        }

        return $mail
            ->line('Each examiner has been sent their appointment letter and the thesis evaluation report form. Copies are archived on your application.')
            ->action('View the appointment documents', route('applications.show', $this->application))
            ->salutation('Centre for Graduate Studies, Universiti Teknologi PETRONAS');
    }
}
