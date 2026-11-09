<?php

namespace App\Modules\Chloe\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;

/**
 * THE one rule for "has the candidacy expired?", used wherever the Workstation module looks at a candidacy expiry date: the expiry day itself is still
 * valid; a candidacy is expired only from the day AFTER its expiry date. Calendar dates, in Asia/Kuala_Lumpur (never UTC, never a time of day).
 * The appeal, reminder and dismissal logic already compare dates this way; this makes Workstation eligibility and the automatic termination agree.
 */
final class ExpiryRule
{
    public const TZ = 'Asia/Kuala_Lumpur';

    public static function today(?CarbonInterface $now = null): CarbonImmutable
    {
        return CarbonImmutable::parse(($now ?? CarbonImmutable::now())->setTimezone(self::TZ)->toDateString(), self::TZ)->startOfDay();
    }

    /** Expired: today (Kuala Lumpur) is after the expiry date. No expiry date counts as expired. */
    public static function hasPassed(?DateTimeInterface $expiry, ?CarbonInterface $now = null): bool
    {
        return $expiry === null || $expiry->format('Y-m-d') < self::today($now)->toDateString();
    }

    /** Still valid: today (Kuala Lumpur) is on or before the expiry date. */
    public static function isStillValid(?DateTimeInterface $expiry, ?CarbonInterface $now = null): bool
    {
        return ! self::hasPassed($expiry, $now);
    }
}
