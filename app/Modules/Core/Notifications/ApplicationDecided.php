<?php

namespace App\Modules\Core\Notifications;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Support\Stage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the student every time their application moves.
 *
 * Queued, so a slow SMTP server cannot stall an approver's page submit --
 * the legacy pages sent mail inline and printed the raw PHPMailer error
 * into the approver's success banner when it failed.
 */
class ApplicationDecided extends Notification implements ShouldQueue
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
        $module = $this->application->module()->label();
        $ref = $module.' Application #'.$this->application->id;
        $next = $this->application->currentStage();

        $mail = (new MailMessage)
            ->subject($ref.' — '.($this->approved ? 'Progress Update' : 'Not Approved'))
            ->greeting('Hi '.$notifiable->name.',');

        if (! $this->approved) {
            return $mail
                ->line("Your {$module} application was not approved at the {$this->stage->label} stage.")
                ->line('This is the final outcome for this application.')
                ->action('View details', route('applications.index'))
                ->line('If you believe this is a mistake, please contact your supervisor.');
        }

        $mail->line("Your {$module} application has been {$this->stage->decision} at the {$this->stage->label} stage.");

        $mail = $next
            ? $mail->line("It now moves to: {$next->label}.")
            : $mail->line('All approvals are complete. Your application has been fully approved.');

        return $mail
            ->action('Track your application', route('applications.index'))
            ->line('Thank you for using UResearch 2.0.');
    }
}
