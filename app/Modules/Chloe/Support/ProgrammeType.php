<?php

namespace App\Modules\Chloe\Support;

/**
 * Research or coursework, from the free-text programme (the only place it exists), parsed the way Workstation parses it: text that says
 * "coursework" is Coursework; everything else is research (Full-Time or Part-Time). Coursework students get no candidacy reminders.
 */
final class ProgrammeType
{
    public const RESEARCH = 'research';

    public const COURSEWORK = 'coursework';

    public static function of(?string $programme): string
    {
        return str_contains(mb_strtolower((string) $programme), 'coursework') ? self::COURSEWORK : self::RESEARCH;
    }

    public static function receivesReminders(?string $programme): bool
    {
        return in_array(self::of($programme), (array) config('candidacy.reminders.programme_types', ['research']), true);
    }
}
