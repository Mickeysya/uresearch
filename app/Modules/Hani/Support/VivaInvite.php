<?php

namespace App\Modules\Hani\Support;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Hani\Models\ExaminerNomination;
use App\Modules\Hani\Models\VivaDetail;

/**
 * Who is told about a viva date, and the calendar file they are sent.
 */
final class VivaInvite
{
    /** How long the calendar event blocks out. The date is what matters. */
    public const DURATION_HOURS = 2;

    /**
     * Everyone the guideline sends the calendar to, plus the supervisors.
     *
     * Users (student, supervisor) are notified as users, so it also lands in
     * their in-app feed. Everyone else is only an email address.
     *
     * An examiner with no email, or one reported not working, is left out
     * and listed under 'skipped' with the reason, so the caller can say so.
     *
     * @return array{users: array<int, User>, emails: array<string, string>, skipped: array<int, string>} emails: address => name
     */
    public static function recipients(Application $notice, VivaDetail $detail, ?ExaminerNomination $panel): array
    {
        $student = $notice->student;
        $users = array_values(array_filter([$student, $student?->supervisor]));

        $emails = [];
        $skipped = [];
        foreach ([$panel?->internalMain, $panel?->externalMain] as $examiner) {
            if ($examiner?->emailNeedsUpdating()) {
                $skipped[] = $examiner->emailProblem();
            }
        }

        foreach ([
            [$panel?->internalMain?->usableEmail(), $panel?->internalMain?->name],
            [$panel?->externalMain?->usableEmail(), $panel?->externalMain?->name],
            [$detail->chair_email, $detail->chair_name],
            [$detail->co_supervisor_email, $detail->co_supervisor_name],
        ] as [$email, $name]) {
            if ($email) {
                $emails[strtolower($email)] = (string) $name;
            }
        }

        // Someone already notified as a user does not get a second copy.
        foreach ($users as $user) {
            unset($emails[strtolower($user->email)]);
        }

        return ['users' => $users, 'emails' => $emails, 'skipped' => $skipped];
    }

    /**
     * An iCalendar file for the viva. The UID is stable per notice and
     * SEQUENCE rises with each reschedule, so a calendar that already holds
     * the event moves it instead of adding a second one.
     */
    public static function ics(Application $notice, VivaDetail $detail): string
    {
        $start = $detail->viva_at->copy()->utc();
        $end = $start->copy()->addHours(self::DURATION_HOURS);
        $format = fn ($time) => $time->format('Ymd\THis\Z');

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//UResearch 2.0//Viva Voce//EN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:viva-'.$notice->id.'@uresearch.utp.edu.my',
            'SEQUENCE:'.$detail->schedule_sequence,
            'DTSTAMP:'.$format(now()->utc()),
            'DTSTART:'.$format($start),
            'DTEND:'.$format($end),
            'SUMMARY:'.self::escape('Viva Voce: '.$notice->student?->name),
            'LOCATION:'.self::escape((string) $detail->venue),
            'DESCRIPTION:'.self::escape('Thesis: '.$detail->thesis_title.'. Chairman: '.$detail->chair_name.'.'),
            'END:VEVENT',
            'END:VCALENDAR',
        ];

        // RFC 5545 wants CRLF line endings.
        return implode("\r\n", $lines)."\r\n";
    }

    /** Escape a TEXT value per RFC 5545: backslash, semicolon, comma, newline. */
    protected static function escape(string $value): string
    {
        return str_replace(['\\', ';', ',', "\r\n", "\n"], ['\\\\', '\;', '\,', '\n', '\n'], $value);
    }
}
