<?php

namespace App\Modules\Norhanis\Services;

use App\Modules\Norhanis\Models\Candidacy;
use App\Modules\Norhanis\Models\RpdReminderLog;
use App\Modules\Norhanis\Notifications\RpdDeadlineApproaching;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The one place a 3/2/1-month RPD reminder is checked, sent and logged.
 * Both the scheduled rpd:remind scan and the "Send Now" button on the
 * Upcoming RPD Reminders page call sendIfDue(), so the rules for "is this
 * reminder due, has it already gone out, and how do we record it" cannot
 * differ between the automatic and manual paths.
 */
class RpdReminderSender
{
    public const SENT = 'sent';
    public const NOT_DUE = 'not_due';
    public const ALREADY_SENT = 'already_sent';
    public const NO_STUDENT = 'no_student';

    /** @return self::SENT|self::NOT_DUE|self::ALREADY_SENT|self::NO_STUDENT */
    public function sendIfDue(Candidacy $candidacy, int $monthMark): string
    {
        if (! $candidacy->student) {
            return self::NO_STUDENT;
        }

        if (now()->lt($candidacy->reminderWindowOpensAt($monthMark))) {
            return self::NOT_DUE;
        }

        if (RpdReminderLog::where('candidacy_id', $candidacy->id)->where('month_mark', $monthMark)->exists()) {
            return self::ALREADY_SENT;
        }

        try {
            // The log row is written first, inside the transaction, so the
            // unique (candidacy_id, month_mark) index claims the slot before
            // anything is sent: a race with another run loses here and never
            // queues a second email. If notify() throws, the row rolls back
            // and the reminder can be retried.
            DB::transaction(function () use ($candidacy, $monthMark) {
                RpdReminderLog::create([
                    'candidacy_id' => $candidacy->id,
                    'month_mark' => $monthMark,
                    'sent_at' => now(),
                ]);

                $candidacy->student->notify(new RpdDeadlineApproaching($candidacy, $monthMark));
            });
        } catch (QueryException $e) {
            if (RpdReminderLog::where('candidacy_id', $candidacy->id)->where('month_mark', $monthMark)->exists()) {
                return self::ALREADY_SENT;
            }

            throw $e;
        }

        return self::SENT;
    }
}
