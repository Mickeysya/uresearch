<?php

namespace App\Modules\Norhanis\Notifications;

use App\Modules\Norhanis\Models\Candidacy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The 3 / 2 / 1-month RPD reminder, sent to the student and their supervisor.
 *
 * Both channels on purpose: `mail` is the reminder norhanis.md asks for, and
 * `database` puts it in the in-app feed so a student who missed the email still
 * sees it next time they open the portal. The feed row carries `url`, so the
 * notification page links straight to the appeal form.
 */
class RpdDeadlineApproaching extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected Candidacy $candidacy,
        protected int $milestone,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $student = $this->candidacy->student;
        $isStudent = $notifiable->is($student);
        $deadline = $this->candidacy->rpd_deadline->format('j M Y');
        $months = $this->milestone === 1 ? '1 month' : "{$this->milestone} months";

        $mail = (new MailMessage)
            ->subject("Research Proposal Defence due in {$months}".($isStudent ? '' : ': '.$student->name))
            ->greeting('Hi '.$notifiable->name.',');

        $mail = $isStudent
            ? $mail->line("Your Research Proposal Defence deadline is {$deadline}, {$months} from now.")
            : $mail->line("{$student->name}'s Research Proposal Defence deadline is {$deadline}, {$months} from now.");

        $mail->line('Programme: '.(Candidacy::programmeTypes()[$this->candidacy->programme_type] ?? $this->candidacy->programme_type));

        if ($isStudent) {
            $mail->line('If you need more time, you can file an extension appeal. It is endorsed by your supervisor and the Chair, reviewed by CGS, and decided by the Dean of PGR, so file early rather than close to the deadline.');

            if ($this->candidacy->canAppeal()) {
                $mail->action('File an extension appeal', route('rpd-appeal.create'));
            }

            $mail->line('Missing the deadline without an approved appeal starts a dismissal for exceeded candidacy.');
        } else {
            $mail->line('Please follow up with your supervisee.');
        }

        return $mail->line('Thank you for using UResearch 2.0.');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $student = $this->candidacy->student;
        $isStudent = $notifiable->is($student);
        $months = $this->milestone === 1 ? '1 month' : "{$this->milestone} months";

        return [
            'title' => $isStudent
                ? "Your RPD is due in {$months}"
                : "{$student->name}'s RPD is due in {$months}",
            'module' => 'RPD Candidacy',
            'meta' => 'Deadline '.$this->candidacy->rpd_deadline->format('j M Y'),
            // No `approved` key on purpose: the feed reads that as a decision
            // and paints the row green or red. A reminder is neither, so it
            // falls through to the neutral tone.
            'url' => $isStudent && $this->candidacy->canAppeal() ? route('rpd-appeal.create') : null,
        ];
    }
}
