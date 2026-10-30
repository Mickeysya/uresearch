<?php

namespace App\Modules\Nureen\Notifications;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Support\Stage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "The student is emailed at each decision, and the supervisor is CC'd on
 * the final approve/reject email." Core's ApplicationDecided already emails
 * the student for every decision; this is the supervisor's copy of the
 * final one, sent in parallel to the same person who endorsed the
 * application at the 'supervisor' stage rather than to whoever currently
 * holds that role.
 *
 * A separate notification rather than a literal mail header CC, since
 * ApplicationDecided belongs to Core and this module does not edit Core --
 * see GaExtensionController::notifyFinalDecision().
 */
class GaExtensionFinalDecisionCc extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected Application $application,
        protected Stage $stage,
        protected bool $approved,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $verb = $this->approved ? 'approved' : 'not approved';

        return (new MailMessage)
            ->subject("GA Extension Application #{$this->application->id} — Final Decision")
            ->greeting('Hi '.$notifiable->name.',')
            ->line("As the supervisor on this request, you are being copied on the outcome: ".
                "GA Extension application #{$this->application->id} for {$this->application->student?->name} ".
                "has been {$verb} at the {$this->stage->label} stage.")
            ->line('This is a copy for your records -- no action is needed from you.')
            ->action('View the application', route('applications.index'))
            ->line('Thank you for using UResearch 2.0.');
    }
}
