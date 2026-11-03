<?php

namespace App\Modules\Hani\Notifications;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Hani\Models\VivaDetail;
use App\Modules\Hani\Support\VivaInvite;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The viva date, with a calendar file, to the student, their supervisor, the
 * two main examiners, the chairman and any co-supervisor.
 *
 * Examiners and the chairman are not users, so they are sent this on demand
 * (Notification::route('mail', ...)) and get mail only. Users also get the
 * in-app copy.
 */
class VivaScheduled extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected Application $notice,
        protected VivaDetail $detail,
        protected bool $rescheduled,
        protected bool $discussReports = false,
    ) {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return $notifiable instanceof User ? ['mail', 'database'] : ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $student = $this->notice->student;
        $when = $this->detail->viva_at->format('l, j F Y, g:i a');
        $name = $notifiable instanceof User ? $notifiable->name : 'Sir/Madam';

        $mail = (new MailMessage)
            ->subject(($this->rescheduled ? 'Viva rescheduled: ' : 'Viva scheduled: ').$student?->name)
            ->greeting('Dear '.$name.',')
            ->line($this->rescheduled
                ? 'The viva voce below has been moved to a new date. The attached calendar file replaces the earlier one.'
                : 'The viva voce below has been scheduled. Add it to your calendar with the attached file.')
            ->line('Candidate: '.$student?->name.($student?->matric_no ? ' ('.$student->matric_no.')' : ''))
            ->line('Thesis: '.$this->detail->thesis_title)
            ->line('Date and time: '.$when)
            ->line('Venue: '.$this->detail->venue)
            ->line('Chairman: '.$this->detail->chair_name);

        // The examiners' reports disagree: the chairman alone is asked to
        // raise it at the pre-viva meeting. Neutral on purpose -- no
        // categories, and nobody else sees the line.
        if ($this->discussReports && $this->isChairman($notifiable)) {
            $mail->line('The examiners\' preliminary reports differ in their recommendations. Please discuss them with the panel at the pre-viva meeting.');
        }

        if ($notifiable instanceof User) {
            $mail->action('View in UResearch', route($notifiable->is($student) ? 'viva.eligibility' : 'viva.supervisees'));
        }

        return $mail
            ->line('Sent by the Academic Executive through UResearch 2.0.')
            ->attachData(VivaInvite::ics($this->notice, $this->detail), 'viva-voce.ics', [
                'mime' => 'text/calendar; charset=UTF-8; method=PUBLISH',
            ]);
    }

    /** Is this the chairman's copy? They are sent on demand, as [email => name]. */
    protected function isChairman(object $notifiable): bool
    {
        if ($notifiable instanceof User || blank($this->detail->chair_email)) {
            return false;
        }

        $route = (array) $notifiable->routeNotificationFor('mail', $this);
        $emails = array_map(fn ($key, $value) => is_string($key) ? $key : $value, array_keys($route), $route);

        return in_array(strtolower($this->detail->chair_email), array_map('strtolower', $emails), true);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'application_id' => $this->notice->id,
            'module' => $this->notice->module()->label(),
            'reference' => $this->notice->reference(),
            'title' => ($this->rescheduled ? 'Viva rescheduled: ' : 'Viva scheduled: ')
                .$this->detail->viva_at->format('j M Y, g:i a'),
            'message' => 'Venue: '.$this->detail->venue,
        ];
    }
}
