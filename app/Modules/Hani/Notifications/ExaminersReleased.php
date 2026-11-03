<?php

namespace App\Modules\Hani\Notifications;

use App\Modules\Core\Models\Application;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * To every Non-Executive CGS user when the Senior Executive finalises and
 * releases an examiner list -- the email Puan Waheeda used to send Encik
 * Syahmi with the Excel attached. The list itself is the Examiner Report.
 * Queued, after commit.
 */
class ExaminersReleased extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected Application $application)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $student = $this->application->student;

        return (new MailMessage)
            ->subject('New examiner list released')
            ->greeting('Dear '.$notifiable->name.',')
            ->line('The examiner panel for '.($student?->name ?? 'a candidate')
                .($student?->department ? ' ('.$student->department.')' : '')
                .' has been finalised and released (application #'.$this->application->id.').')
            ->line('Appointment letters go to the two main examiners. The backups are on standby.')
            ->action('Open the Examiner Report', route('examiner-nomination.report'));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'application_id' => $this->application->id,
            'module' => $this->application->module()->label(),
            'reference' => $this->application->reference(),
            'title' => 'New examiner list released.',
            'message' => 'Application #'.$this->application->id.'. Open the Examiner Report.',
        ];
    }
}
