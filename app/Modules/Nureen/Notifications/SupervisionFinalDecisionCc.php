<?php

namespace App\Modules\Nureen\Notifications;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Support\Stage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Rejection at any stage requires a reason, and the student is emailed with
 * it. The supervisor is CC'd on the final decision." Core's ApplicationDecided
 * already emails the student for every decision; this is the named
 * supervisor's copy of the final one, wherever it lands -- the Chair's
 * approval in 'automatic' mode, or CGS's in 'manual' mode.
 *
 * A separate notification rather than a literal mail header CC, since
 * ApplicationDecided belongs to Core and this module does not edit Core --
 * same reasoning as GaExtensionFinalDecisionCc.
 */
class SupervisionFinalDecisionCc extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected Application $application,
        protected Stage $stage,
        protected bool $approved,
        protected ?string $remarks = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $verb = $this->approved ? 'approved' : 'not approved';

        $mail = (new MailMessage)
            ->subject("Supervision Request #{$this->application->id} — Final Decision")
            ->greeting('Hi '.$notifiable->name.',')
            ->line("You are being copied on the outcome: the supervision request naming you ".
                "for {$this->application->student?->name} has been {$verb} at the {$this->stage->label} stage.");

        if (! $this->approved && $this->remarks) {
            $mail->line('Reason given: '.$this->remarks);
        }

        return $mail
            ->line('This is a copy for your records -- no action is needed from you.')
            ->action('View the application', route('applications.index'))
            ->line('Thank you for using UResearch 2.0.');
    }
}
