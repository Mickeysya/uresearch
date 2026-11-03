<?php

namespace App\Modules\Chloe\Support;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * The daily schedule has two moments (Asia/Kuala_Lumpur): 07:30 REFRESH changes data only, 08:00 REMINDERS sends e-mail. Every e-mail or bell
 * notice that a scheduled job sends to a student goes out at 08:00: the state change is made at 07:30 and the notice of that change is queued
 * with a delay until 08:00 the same day. A run after 08:00 (a manual run, or a late scheduler) delivers at once.
 *
 * Only code that runs inside refreshing() is delayed. Page-load processing (LockerKey::processIfOverdue() called inline for the student's own row)
 * and every action a person takes stay immediate.
 */
final class ScheduledDelivery
{
    public const TIMEZONE = 'Asia/Kuala_Lumpur';

    public const HOUR = 8;

    protected static bool $refreshing = false;

    /** Run a refresh job: notifications it creates are held until 08:00. */
    public static function refreshing(callable $job): mixed
    {
        $before = self::$refreshing;
        self::$refreshing = true;

        try {
            return $job();
        } finally {
            self::$refreshing = $before;
        }
    }

    /** 08:00 today in Kuala Lumpur while a refresh job runs before that time; otherwise null (deliver now). */
    public static function deliverAt(): ?Carbon
    {
        if (! self::$refreshing) {
            return null;
        }

        $eight = self::today()->setTime(self::HOUR, 0);

        return now()->lt($eight) ? $eight : null;
    }

    /** @template T of Notification @param T $notification @return T */
    public static function apply(Notification $notification): Notification
    {
        if (($at = self::deliverAt()) && method_exists($notification, 'delay')) {
            $notification->delay($at);
        }

        return $notification;
    }

    protected static function today(): Carbon
    {
        return now(self::TIMEZONE);
    }
}
