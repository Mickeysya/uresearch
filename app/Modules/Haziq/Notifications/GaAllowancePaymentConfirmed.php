<?php

namespace App\Modules\Haziq\Notifications;

use App\Modules\Haziq\Models\GaAllowancePaymentLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * GA's own counterpart to AllowancePaymentConfirmed -- confirmed
 * explicitly 2026-11-10: "once mark keluar email complete payment."
 * Same stakeholder shape (student plus Admin/CGS/Supervisor), kept as
 * its own class rather than reused since it points at GaAllowancePaymentLog,
 * not AllowancePaymentLog -- the two never mix.
 */
class GaAllowancePaymentConfirmed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected GaAllowancePaymentLog $log, protected bool $forStudent) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toArray(object $notifiable): array
    {
        $this->log->loadMissing('application.student', 'confirmedBy', 'proofDocument');
        $student = $this->log->application->student;

        return [
            'application_id' => $this->log->application_id,
            'action_url' => route('ga-allowance.index'),
            'proof_url' => $this->log->proofDocument?->url(),
            'title' => $this->forStudent
                ? 'Your GA allowance payment has been confirmed'
                : "{$student->name} — GA allowance payment confirmed",
            'body' => sprintf(
                'RM %s confirmed paid by %s.',
                number_format((float) $this->log->amount, 2),
                $this->log->confirmedBy->name,
            ),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->log->loadMissing('application.student', 'confirmedBy', 'proofDocument');
        $student = $this->log->application->student;
        $amount = number_format((float) $this->log->amount, 2);
        $proofUrl = $this->log->proofDocument?->url();

        if ($this->forStudent) {
            $mail = (new MailMessage)
                ->subject('Your GA allowance payment has been confirmed')
                ->greeting('Hi '.$notifiable->name.',')
                ->line("RM {$amount} of your {$this->log->application->reference()} allowance has been confirmed as paid by {$this->log->confirmedBy->name}.");
        } else {
            $mail = (new MailMessage)
                ->subject("GA allowance payment confirmed — {$student->name}")
                ->greeting('Hi '.$notifiable->name.',')
                ->line("RM {$amount} of {$student->name}'s {$this->log->application->reference()} allowance has been confirmed as paid by {$this->log->confirmedBy->name}.");
        }

        if ($proofUrl) {
            $mail->line("[View Proof of Payment]({$proofUrl})");
        }

        return $mail->action('Review GA Allowance Eligibility', route('ga-allowance.index'));
    }
}
