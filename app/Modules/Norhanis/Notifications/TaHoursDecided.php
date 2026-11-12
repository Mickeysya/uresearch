<?php

namespace App\Modules\Norhanis\Notifications;

use App\Modules\Norhanis\Models\TaHourLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/** To the student: the course lecturer approved (or rejected) their TA hours. */
class TaHoursDecided extends Notification implements ShouldQueue
{
    use Queueable;

    /** @var array<int, array{date: string, time: string, hours: string, activity: string}> */
    protected array $rows;

    /** @param  Collection<int, TaHourLog>  $logs */
    public function __construct(Collection $logs, protected bool $approved, protected string $lecturer, protected ?string $reason = null)
    {
        // Plain values, so the queued notification carries no models.
        $this->rows = $logs->sortBy('activity_date')->map(fn (TaHourLog $log) => [
            'date' => $log->activity_date->format('j M Y'),
            'time' => "{$log->time_in}–{$log->time_out}",
            'hours' => rtrim(rtrim(number_format((float) $log->hours, 2, '.', ''), '0'), '.'),
            'activity' => $log->activityLabel(),
        ])->values()->all();
    }

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Your TA hours have been '.$this->verb())
            ->greeting('Hi '.$notifiable->name.',')
            ->line("{$this->lecturer} has {$this->verb()} the following TA hours:");

        foreach ($this->rows as $row) {
            $mail->line("• {$row['date']}, {$row['time']} ({$row['hours']} hrs): {$row['activity']}");
        }

        if (! $this->approved) {
            $mail->line('Reason: '.$this->reason)
                ->line('You can remove the rejected rows and log them again with the right details.');
        }

        return $mail->action('View your TA hours', route('ta-hours.index'))
            ->line('Thank you for using UResearch 2.0.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'module' => 'TA Hours',
            'title' => count($this->rows).' TA hours '.(count($this->rows) === 1 ? 'row' : 'rows')." {$this->verb()} by {$this->lecturer}.",
            'approved' => $this->approved,
            'action_url' => route('ta-hours.index'),
        ];
    }

    protected function verb(): string
    {
        return $this->approved ? 'approved' : 'rejected';
    }
}
