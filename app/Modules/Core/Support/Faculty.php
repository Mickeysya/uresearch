<?php

namespace App\Modules\Core\Support;

/**
 * The three academic bodies a department can sit under, and the order they
 * are shown in — foundation first, then the two degree-awarding faculties.
 *
 * Same shape as Role: a PHP list behind a VARCHAR, so adding one is a one-line
 * change nobody has to migrate for. The codes are the ones `users.faculty`
 * already carries ('FOE', 'FSMC'); the titles live here alone, so retitling a
 * faculty never touches a row.
 *
 * CGS is deliberately absent. It coordinates postgraduate candidature and
 * routes MSc and PhD students into the FoE and FSMC departments below — it
 * has no departments of its own to list.
 */
final class Faculty
{
    public const CFS = 'CFS';
    public const FOE = 'FOE';
    public const FSMC = 'FSMC';

    /** @return array<int, string> */
    public static function all(): array
    {
        return [self::CFS, self::FOE, self::FSMC];
    }

    public static function label(string $code): string
    {
        return match ($code) {
            self::CFS => 'Centre for Foundation Studies',
            self::FOE => 'Faculty of Engineering',
            self::FSMC => 'Faculty of Science, Management & Computing',
            default => $code,
        };
    }

    /**
     * One line of context under the faculty's name on the admin list. CFS gets
     * one because its two entries are really streams, not departments, and the
     * next person to read that list will otherwise wonder.
     */
    public static function note(string $code): string
    {
        return match ($code) {
            self::CFS => 'Pre-university. Organised into streams rather than departments; listed here so a foundation account has something to pick.',
            default => '',
        };
    }
}
