<?php

namespace App\Modules\Nureen\Notifications;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Support\Stage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * In config('nureen.ga_extension.final_approver') === 'cgs' mode, the
 * Senior Director no longer decides GA Extension applications -- Non-
 * Executive CGS does, as the final stage. This keeps the Director informed
 * of every outcome anyway: visibility without a queue, a decision, or a
 * stage of their own.
 */
class GaExtensionFinalDecisionInformational extends Notification implements ShouldQueue
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
            ->subject("GA Extension Application #{$this->application->id} — {$verb}")
            ->greeting('Hi '.$notifiable->name.',')
            ->line("GA Extension application #{$this->application->id} for {$this->application->student?->name} ".
                "has been {$verb} by {$this->stage->label}.")
            ->line('No action is needed from you -- this is for your information only.')
            ->action('View the application', route('ga-extension.queue'))
            ->line('Thank you for using UResearch 2.0.');
    }
}
