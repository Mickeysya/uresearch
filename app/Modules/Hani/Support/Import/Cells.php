<?php

namespace App\Modules\Hani\Support\Import;

use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Reading one cell of a CGS spreadsheet into a clean value.
 *
 * The sheets are typed by hand, so the same thing arrives several ways: a date
 * as "01/05/2009", "2009-05-01" or an Excel serial number; a faculty approval
 * as the text "3.2026" or the number 3.2026 (and "1.2020" as 1.202); a list as
 * a numbered multi-line cell. Each helper accepts all of them and returns
 * null rather than guessing when it cannot read one.
 */
final class Cells
{
    /** Header text as a lookup key: lower case, one space, no trailing colon, "a / b" as "a/b". */
    public static function header(mixed $value): string
    {
        $text = mb_strtolower(trim((string) $value));
        $text = preg_replace('/\s+/u', ' ', $text);
        $text = preg_replace('/\s*\/\s*/u', '/', $text);

        return rtrim($text, ': ');
    }

    public static function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim(preg_replace('/[ \t]+/u', ' ', (string) $value));

        return $text === '' ? null : $text;
    }

    public static function integer(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (int) round((float) $value);
        }

        return preg_match('/\d+/', (string) $value, $m) ? (int) $m[0] : null;
    }

    /** "Y-m-d", or null when the cell is empty or unreadable. */
    public static function date(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        // An Excel serial number (days since 1900). Anything plausible as a
        // date between 1955 and 2140 -- a bare year like 2024 is not one.
        if (is_numeric($value) && (float) $value > 20000 && (float) $value < 90000) {
            return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value))->format('Y-m-d');
        }

        $text = trim((string) $value);

        // Day first: Malaysian sheets write 01/05/2009 for 1 May.
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'Y/m/d', 'j M Y', 'j F Y'] as $format) {
            $date = \DateTime::createFromFormat('!'.$format, $text);
            $problems = \DateTime::getLastErrors();

            // Overflow ("31/02/2025" rolling into March) is a warning, not a
            // failure, to PHP -- refuse it rather than store the wrong day.
            if ($date === false || ($problems && ($problems['warning_count'] || $problems['error_count']))) {
                continue;
            }

            return Carbon::instance($date)->format('Y-m-d');
        }

        return null;
    }

    /**
     * CGS writes the faculty approval as month.year ("2.2023"). A cell Excel
     * kept as a number has lost its trailing zero (10.2020 is 10.202), so a
     * number is re-padded to four decimals. Anything else -- a reference such
     * as "FOE/EXT/2026/019" -- is kept as written.
     */
    public static function facultyApproval(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_float($value) || is_int($value)) {
            $value = sprintf('%.4f', $value);
        }

        $text = trim((string) $value);

        return preg_match('/^(\d{1,2})\.(\d{4})$/', $text, $m)
            ? ((int) $m[1]).'.'.$m[2]
            : self::text($text);
    }

    /**
     * A numbered multi-line list ("1.Concrete\n2.Compressive Strength") or a
     * semicolon list, as clean lower-case terms with the numbering gone.
     *
     * @return array<int, string>
     */
    public static function terms(mixed $value): array
    {
        $parts = preg_split('/[\r\n;]+/u', (string) $value);

        $terms = array_map(function ($part) {
            $part = preg_replace('/^\s*\d+\s*[.)\-:]\s*/u', '', (string) $part);

            return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $part), " \t,."));
        }, $parts);

        return array_values(array_unique(array_filter($terms, fn ($t) => $t !== '' && mb_strlen($t) <= 120)));
    }

    /** "Yes" / "No" / "Y" / "Available" -> true/false, or null when unreadable. */
    public static function yesNo(mixed $value): ?bool
    {
        $text = mb_strtolower(trim((string) $value));

        return match (true) {
            in_array($text, ['yes', 'y', 'available', 'active', 'true', '1'], true) => true,
            in_array($text, ['no', 'n', 'unavailable', 'inactive', 'false', '0'], true) => false,
            default => null,
        };
    }

    /**
     * "Prof Dr Hassan bin Omar (UTM)" -> ["Prof Dr Hassan bin Omar", "UTM"].
     * The bracket at the END of a name is the institution abbreviation CGS
     * appends; anything else stays.
     *
     * @return array{0: string, 1: ?string}
     */
    public static function splitInstitutionHint(string $name): array
    {
        if (preg_match('/^(.*?)\s*\(([^()]+)\)\s*$/u', $name, $m)) {
            return [trim($m[1]), trim($m[2])];
        }

        return [trim($name), null];
    }
}
