<?php

namespace App\Modules\Hani\Notifications;

use App\Modules\Core\Models\User;
use App\Modules\Hani\Models\Examiner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * To Non-Executive and Senior Executive CGS: someone has reported an
 * examiner's email as not working. Invitations, letters and reminders skip
 * the examiner until a new address is saved.
 */
class ExaminerEmailReported extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Examiner $examiner,
        protected User $reportedBy,
        protected string $reason,
    ) {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    protected function headline(): string
    {
        return "Email needs updating: {$this->examiner->name}";
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->headline())
            ->greeting('Dear '.$notifiable->name.',')
            ->line("{$this->reportedBy->name} has reported that the email on record for {$this->examiner->name} ({$this->examiner->email}) is not working.")
            ->line('Reason given: '.$this->reason)
            ->line('Invitations, letters and reminders to this examiner are being skipped until a new email is saved.')
            ->action('Update the email', route('examiner-admin.email.edit', $this->examiner))
            ->line('Thank you for using UResearch 2.0.');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'examiner_id' => $this->examiner->id,
            'module' => 'Examiner Nomination',
            'title' => $this->headline().'.',
            'message' => "Reported by {$this->reportedBy->name}: {$this->reason}",
        ];
    }
}
