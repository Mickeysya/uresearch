<?php

namespace App\Modules\Norhanis\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Travel Start Date, Travel End Date and the Duration of Travelling, the
 * same in Travel and in a Publication conference's Travel Details (UAT,
 * 11 Nov 2026), so the two behave alike: start not in the past, end on or
 * after the start (same day allowed), duration counted inclusively and
 * never taken from the form. The browser side is partials/date-range.
 */
final class TravelDates
{
    /**
     * The validation rules for the pair, each prefixed by $extra (e.g.
     * Publication's exclude_unless for journals).
     *
     * @param  array<int, string>  $extra
     * @return array<string, array<int, string>>
     */
    public static function rules(array $extra = []): array
    {
        return [
            'travel_start_date' => [...$extra, 'required', 'date', 'after_or_equal:today'],
            'travel_end_date' => [...$extra, 'required', 'date', 'after_or_equal:travel_start_date'],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'travel_start_date.after_or_equal' => 'Travel cannot start in the past.',
            'travel_end_date.after_or_equal' => 'The end date must be on or after the start date.',
        ];
    }

    /** Days, counting both ends: 1 Dec to 1 Dec is 1 day, 1 Dec to 3 Dec is 3. */
    public static function durationDays(CarbonInterface|string $start, CarbonInterface|string $end): int
    {
        $start = Carbon::parse($start)->startOfDay();
        $end = Carbon::parse($end)->startOfDay();

        return (int) $start->diffInDays($end) + 1;
    }
}
