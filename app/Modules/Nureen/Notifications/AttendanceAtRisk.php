<?php

namespace App\Modules\Nureen\Notifications;

use App\Modules\Nureen\Models\AttendanceRecord;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Proactive early-warning alert -- sent to both the student and their
 * supervisor the moment a new upload first flags them at-risk. Not sent on
 * every upload: see AttendanceController, which only fires this on the
 * false-to-true transition.
 */
class AttendanceAtRisk extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected AttendanceRecord $record) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $student = $this->record->student;
        $isStudent = $notifiable->is($student);

        $mail = (new MailMessage)
            ->subject('Attendance Early Warning'.($isStudent ? '' : ' — '.$student->name))
            ->greeting('Hi '.$notifiable->name.',');

        $mail = $isStudent
            ? $mail->line("Your attendance is currently {$this->record->percentage}%, ".
                'at or trending toward the mandatory 80% threshold.')
            : $mail->line("{$student->name}'s attendance is currently {$this->record->percentage}%, ".
                'at or trending toward the mandatory 80% threshold.');

        return $mail
            ->line('Period ending: '.$this->record->period_end->format('j M Y'))
            ->line($isStudent
                ? 'If you believe this is inaccurate, you can file an attendance appeal from your dashboard.'
                : 'Please follow up with your supervisee.')
            ->line('Thank you for using UResearch 2.0.');
    }
}
