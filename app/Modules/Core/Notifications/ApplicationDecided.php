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
        // null (default) => derive wording from $approved, exactly as before.
        // 'returned' => sent back for revision, not a terminal outcome.
        protected ?string $outcome = null,
    ) {}

    public function via(object $notifiable): array
    {
        // 'database' as well as mail, so the student dashboard's notification
        // feed and unread badge have something to read. Mail behaviour below
        // is unchanged.
        return ['mail', 'database'];
    }

    /**
     * The stored form, read back by the dashboard's Recent Notifications
     * panel. Keep the keys stable -- rows already written carry them.
     */
    public function toArray(object $notifiable): array
    {
        $module = $this->application->module()->label();
        $next = $this->application->currentStage();

        $title = match (true) {
            $this->outcome === 'returned' => "Your {$module} application was returned for revision at the {$this->stage->label} stage.",
            $this->approved => "Your {$module} application has been {$this->stage->decision} at the {$this->stage->label} stage.",
            default => "Your {$module} application was not approved at the {$this->stage->label} stage.",
        };

        return [
            'application_id' => $this->application->id,
            'module' => $module,
            'approved' => $this->approved,
            'stage_label' => $this->stage->label,
            'title' => $title,
            'next_stage' => $next?->label,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $module = $this->application->module()->label();
        $ref = $module.' Application #'.$this->application->id;
        $next = $this->application->currentStage();

        $subject = match (true) {
            $this->outcome === 'returned' => 'Returned for Revision',
            $this->approved => 'Progress Update',
            default => 'Not Approved',
        };

        $mail = (new MailMessage)
            ->subject($ref.' — '.$subject)
            ->greeting('Hi '.$notifiable->name.',');

        if ($this->outcome === 'returned') {
            return $mail
                ->line("Your {$module} application was returned for revision at the {$this->stage->label} stage.")
                ->line('Please review the comments, make the necessary changes, and resubmit.')
                ->action('Review and resubmit', route('applications.index'))
                ->line('Thank you for using UResearch 2.0.');
        }

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
