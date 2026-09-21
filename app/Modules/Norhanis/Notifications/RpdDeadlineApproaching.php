<?php

namespace App\Modules\Norhanis\Notifications;

use App\Modules\Norhanis\Models\Candidacy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The 3/2/1-month RPD candidacy deadline reminder.
 *
 * Not tied to an Application -- candidacies are tracked entirely outside
 * WorkflowEngine (see Candidacy's docblock). Sent by RemindRpdCandidates,
 * once per (candidacy, month_mark) pair; RpdReminderLog's unique constraint
 * is what stops the same reminder ever firing twice.
 */
class RpdDeadlineApproaching extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected Candidacy $candidacy,
        protected int $monthsRemaining,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $unit = $this->monthsRemaining === 1 ? 'month' : 'months';

        return (new MailMessage)
            ->subject("RPD Candidacy Deadline — {$this->monthsRemaining} {$unit} remaining")
            ->greeting('Hi '.$notifiable->name.',')
            ->line('Your Research Proposal Defence (RPD) candidacy deadline is '
                .$this->candidacy->deadline->format('j M Y')
                ." — {$this->monthsRemaining} {$unit} from now.")
            ->line('If you need more time, submit an RPD Appeal / Extension before the deadline passes.')
            ->action('Submit an RPD Appeal', route('rpd-appeal.create'))
            ->line('Thank you for using UResearch 2.0.');
    }

    /** Feeds the student dashboard's notification list, same as ApplicationDecided. */
    public function toArray(object $notifiable): array
    {
        $unit = $this->monthsRemaining === 1 ? 'month' : 'months';

        return [
            'candidacy_id' => $this->candidacy->id,
            'deadline' => $this->candidacy->deadline->toDateString(),
            'months_remaining' => $this->monthsRemaining,
            'title' => "Your RPD candidacy deadline is {$this->monthsRemaining} {$unit} away.",
        ];
    }
}
