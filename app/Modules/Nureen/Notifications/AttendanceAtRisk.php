<?php

namespace App\Modules\Nureen\Notifications;

use App\Modules\Core\Models\User;
use App\Modules\Nureen\Models\AttendanceRiskState;
use App\Modules\Nureen\Support\AttendanceRiskEvaluator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The automatic attendance early warning -- sent to the student, with a copy
 * to their supervisor, by Support\AttendanceEarlyWarning after a risk check.
 * When it may be sent (and when it may NOT be repeated) is decided there.
 *
 * WHAT IT MUST NEVER CONTAIN: the projected end-of-semester percentage or
 * the trend. The projection is a CGS monitoring tool; a student is told only
 * their current attendance against the 80% requirement, in wording that
 * depends on why they were flagged:
 *   'projected' -- attendance has been declining and may fall below 80% if
 *                  the trend continues (supportive, forward-looking)
 *   'current' / 'both' -- attendance is below, or close to, 80% (plain)
 * and always ends with what they can do: contact CGS, or submit an
 * attendance appeal.
 */
class AttendanceAtRisk extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected User $student,
        protected string $reason,
        protected float $currentPercentage,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $isStudent = $notifiable->is($this->student);
        $current = rtrim(rtrim(number_format($this->currentPercentage, 1), '0'), '.').'%';
        $threshold = rtrim(rtrim(number_format(AttendanceRiskEvaluator::THRESHOLD, 1), '0'), '.').'%';
        $cgsEmail = config('nureen.letters.email');

        if (! $isStudent) {
            return (new MailMessage)
                ->subject("Attendance early warning: {$this->student->name}")
                ->greeting("Dear {$notifiable->name},")
                ->line("This is a copy of an early warning sent to your supervisee, {$this->student->name}"
                    .($this->student->matric_no ? " ({$this->student->matric_no})" : '').'.')
                ->line($this->reason === AttendanceRiskState::REASON_PROJECTED
                    ? "Their attendance has been declining over recent periods and may fall below the {$threshold} requirement if the trend continues."
                    : "Their attendance is below, or close to, the {$threshold} requirement.")
                ->line("Their attendance so far this semester is {$current}.")
                ->line('If it stays below the requirement, the Postgraduate Handbook allows their GA/GRA allowance to be suspended '
                    .'and their registration for the next semester to be barred; for an international student, CGS may not support a student visa renewal.')
                ->line('A short conversation now may help them get back on track. They have been told they can contact CGS or submit an attendance appeal if there are circumstances CGS should know about.')
                ->salutation("Regards,\nCentre for Graduate Studies");
        }

        $mail = (new MailMessage)
            ->subject('Your attendance: a note from the Centre for Graduate Studies')
            ->greeting("Dear {$this->student->name},");

        $mail = $this->reason === AttendanceRiskState::REASON_PROJECTED
            ? $mail
                ->line('We are writing because your attendance has been declining over recent periods. '
                    ."If this trend continues, it may fall below the {$threshold} attendance requirement for this semester.")
                ->line("Your attendance so far this semester is {$current}, so there is still time to turn this around.")
            : $mail
                ->line("We are writing because your attendance is below, or close to, the {$threshold} attendance requirement for this semester.")
                ->line("Your attendance so far this semester is {$current}.");

        // The real consequences, from the Postgraduate Handbook 2025. Wording
        // only: nothing here acts on them.
        return $mail
            ->line('**Why this matters**')
            ->line('Attendance below the requirement has consequences under the Postgraduate Handbook:')
            ->line('• your GA or GRA allowance may be suspended;')
            ->line('• you may be barred from registering for the next semester;')
            ->line('• if you are an international student, CGS may not be able to support your student visa renewal.')
            ->line('We know that research does not always follow a timetable, and that there can be good reasons for missing sessions. If something is affecting your attendance, we would like to help.')
            ->line('**What you can do**')
            ->line('• Talk to us: contact the Centre for Graduate Studies'.($cgsEmail ? " at {$cgsEmail}" : '').'.')
            ->line('• If there are circumstances CGS should know about, such as illness, fieldwork or a family matter, you can submit an attendance appeal with any supporting document.')
            ->action('Submit an attendance appeal', route('attendance-appeal.create'))
            ->line('Your supervisor has been sent a copy of this message.')
            ->salutation("Kind regards,\nCentre for Graduate Studies");
    }
}
