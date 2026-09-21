<?php

namespace App\Modules\Norhanis\Notifications;

use App\Modules\Norhanis\Models\Candidacy;
use App\Modules\Norhanis\Models\RpdDismissalDetail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The termination email — norhanis.md Module 4.3, the Registry's stage.
 *
 * "Registry (Sends termination email)" is what that stage is *for*, so the
 * chain was one notification short of doing what the scope says: the
 * candidacy closed and `terminated_at` was stamped, and the student was
 * never told in their own words.
 *
 * Sent to the student only. The supervisor, the Dean and the Faculty have
 * all already seen the case pass through their queues; the person who has
 * not is the one it happens to.
 *
 * `mail` and `database` for the same reason as [[RpdDeadlineApproaching]] --
 * a student who stopped reading their email still sees this in the feed.
 */
class CandidacyTerminated extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected Candidacy $candidacy,
        protected RpdDismissalDetail $detail,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $missed = $this->detail->deadline_missed_on->format('j M Y');
        $terminated = ($this->detail->terminated_at ?? now())->format('j M Y');

        return (new MailMessage)
            ->subject('Termination of Candidature')
            ->greeting('Dear '.$notifiable->name.',')
            ->line("Your candidature at Universiti Teknologi PETRONAS has been terminated with effect from {$terminated}, for exceeded candidacy.")
            ->line("Your Research Proposal Defence was due on {$missed}. It was not completed by that date and no extension appeal was approved.")
            ->line('Grounds on record: '.$this->detail->grounds)
            ->line('The decision was endorsed by the Dean of PGR, approved by the Faculty, and completed by the Registry. The full record is on your applications page.')
            ->action('View the decision record', route('applications.index'))
            // No sign-off pleasantry. This is the one email in the portal
            // where "Thank you for using UResearch 2.0" would be grotesque.
            ->line('If you believe this is in error, contact the Centre for Graduate Studies.');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Your candidature has been terminated',
            'module' => 'RPD Candidacy',
            'meta' => 'Terminated '.($this->detail->terminated_at ?? now())->format('j M Y'),
            // The dismissal application was approved; for the student that is
            // the bad outcome, so the feed row reads red rather than green.
            'approved' => false,
            'url' => route('applications.index'),
        ];
    }
}
