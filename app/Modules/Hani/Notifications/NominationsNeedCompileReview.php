<?php

namespace App\Modules\Hani\Notifications;

use App\Modules\Core\Models\Application;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * To the Senior Executive CGS when a department's nomination reaches
 * cgs_compile carrying a cross-department clash or a likely duplicate
 * examiner record. A prompt to look before compiling, with the reasons; the
 * system decides nothing. Queued and sent after commit.
 */
class NominationsNeedCompileReview extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param array<int, string> $findings */
    public function __construct(
        protected Application $application,
        protected array $findings,
    ) {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Examiner nomination #'.$this->application->id.' needs checking before compilation')
            ->greeting('Dear '.$notifiable->name.',')
            ->line('Application #'.$this->application->id.' ('.($this->application->student?->department ?? 'no department').') has reached CGS Compilation with the following to check:');

        foreach ($this->findings as $finding) {
            $mail->line('• '.$finding);
        }

        return $mail
            ->action('Open Conflict Detection', route('conflict-detection.index'))
            ->line('Nothing has been rejected. Decide on any substitution before compiling the list.');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'application_id' => $this->application->id,
            'module' => $this->application->module()->label(),
            'reference' => $this->application->reference(),
            'title' => 'Nomination #'.$this->application->id.' has '.count($this->findings).' '.str('item')->plural(count($this->findings)).' to check before compilation.',
            'message' => 'Open Conflict Detection before compiling the list.',
        ];
    }
}
