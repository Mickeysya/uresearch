<?php

namespace App\Modules\Hani\Notifications;

use App\Modules\Core\Models\Application;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The student's email when a Notice of Thesis Submission is sent back:
 * REJECTED by the Academic Executive, or RETURNED by the supervisor. Both
 * carry the comment, which the engine's own "not approved" email cannot (and
 * that email says the outcome is final, so ModuleProvider holds it back).
 * The checklist unlocks and the student may file the notice again.
 */
class VivaNoticeDecision extends Notification implements ShouldQueue
{
    use Queueable;

    public const REJECTED = 'rejected';

    public const RETURNED = 'returned';

    public function __construct(
        protected Application $application,
        public readonly string $kind,
        public readonly string $comment,
        protected string $by,
    ) {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    protected function headline(): string
    {
        return $this->kind === self::RETURNED
            ? "Your supervisor, {$this->by}, returned your Notice of Thesis Submission."
            : "Your Academic Executive, {$this->by}, rejected your Notice of Thesis Submission.";
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Notice of Thesis Submission '.($this->kind === self::RETURNED ? 'returned' : 'rejected').': '.$this->application->reference())
            ->greeting('Hi '.$notifiable->name.',')
            ->line($this->headline())
            ->line('Comment: '.$this->comment)
            ->line('Your eligibility checklist is unlocked. Make the changes asked for, then submit the notice again.')
            ->action('Open your viva page', route('viva.eligibility'))
            ->line('Thank you for using UResearch 2.0.');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'application_id' => $this->application->id,
            'module' => $this->application->module()->label(),
            'reference' => $this->application->reference(),
            'title' => $this->headline(),
            'message' => 'Comment: '.$this->comment,
        ];
    }
}
