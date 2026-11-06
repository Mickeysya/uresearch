<?php

namespace App\Modules\Haziq\Notifications;

use App\Modules\Haziq\Models\AllowancePaymentConfirmation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Admin or CGS confirmed an actual payout, with proof — goes to every
 * stakeholder (Admin, CGS, Supervisor, Senior Director) and the student,
 * confirmed explicitly 2026-10-23: "akan bagi noti akan semua pihak
 * admin, cgs, director, supervisor and student pasal payment complete
 * ni." forStudent switches the phrasing, same pattern every other Stage
 * Gate / allowance notification already uses.
 */
class AllowancePaymentConfirmed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected AllowancePaymentConfirmation $confirmation, protected bool $forStudent) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toArray(object $notifiable): array
    {
        $this->confirmation->loadMissing('application.student', 'confirmedBy');
        $student = $this->confirmation->application->student;

        return [
            'application_id' => $this->confirmation->application_id,
            'action_url' => route('allowance.index'),
            'title' => $this->forStudent
                ? 'Your allowance payment has been confirmed'
                : "{$student->name} — allowance payment confirmed",
            'body' => sprintf(
                'RM %s confirmed paid by %s.',
                number_format((float) $this->confirmation->amount, 2),
                $this->confirmation->confirmedBy->name,
            ),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->confirmation->loadMissing('application.student', 'confirmedBy');
        $student = $this->confirmation->application->student;
        $amount = number_format((float) $this->confirmation->amount, 2);

        if ($this->forStudent) {
            return (new MailMessage)
                ->subject('Your allowance payment has been confirmed')
                ->greeting('Hi '.$notifiable->name.',')
                ->line("RM {$amount} of your {$this->confirmation->application->reference()} allowance has been confirmed as paid by {$this->confirmation->confirmedBy->name}.")
                ->action('View your allowance', route('allowance.index'));
        }

        return (new MailMessage)
            ->subject("Allowance payment confirmed — {$student->name}")
            ->greeting('Hi '.$notifiable->name.',')
            ->line("RM {$amount} of {$student->name}'s {$this->confirmation->application->reference()} allowance has been confirmed as paid by {$this->confirmation->confirmedBy->name}.")
            ->action('Review Allowance Eligibility', route('allowance.index'));
    }
}
