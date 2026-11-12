<?php

namespace App\Modules\Norhanis\Notifications;

use App\Modules\Core\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** To the course lecturer: a student has submitted TA hours that name them. */
class TaHoursAwaitingApproval extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected User $student,
        protected string $month,
        protected int $rows,
        protected float $hours,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("TA Hours to approve: {$this->student->name}, {$this->month}")
            ->greeting('Hi '.$notifiable->name.',')
            ->line("{$this->student->name} has submitted {$this->rows} TA hours ".($this->rows === 1 ? 'row' : 'rows')
                ." ({$this->hoursText()}) for {$this->month}, naming you as the course lecturer.")
            ->action('Review TA hours', route('ta-hours.approvals'))
            ->line('Thank you for using UResearch 2.0.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'module' => 'TA Hours',
            'title' => "{$this->student->name} submitted {$this->hoursText()} of TA hours for {$this->month}.",
            'action_url' => route('ta-hours.approvals'),
        ];
    }

    protected function hoursText(): string
    {
        return rtrim(rtrim(number_format($this->hours, 2, '.', ''), '0'), '.').' hrs';
    }
}
