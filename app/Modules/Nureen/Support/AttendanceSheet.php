<?php

namespace App\Modules\Nureen\Support;

use App\Modules\Nureen\Imports\AttendanceSheetImport;
use Carbon\CarbonImmutable;
use Maatwebsite\Excel\Facades\Excel;

/**
 * The shape of a UTrace attendance export, in one place.
 *
 * The template CGS downloads, the header check the importer runs, and the
 * date parsing all read from here — so a column cannot be added to the
 * template without the importer knowing about it, and the file staff are
 * handed is by construction the file the importer accepts.
 */
final class AttendanceSheet
{
    /**
     * The four columns, in order. The importer requires exactly these as the
     * first row; anything after the fourth column is ignored, so a UTrace
     * export with extra trailing columns still works.
     */
    public const COLUMNS = ['matric_no', 'period_end', 'sessions_attended', 'sessions_total'];

    /**
     * Two filled-in rows, so the person opening the template can see the
     * expected shape rather than guessing at it — particularly the date
     * format, which is the field that goes wrong.
     *
     * Real seeded matric numbers, so a download-fill-upload round trip
     * actually lands on a student on a fresh database instead of silently
     * skipping every row.
     *
     * @return array<int, array<int, string|int>>
     */
    public static function sampleRows(): array
    {
        return [
            ['22001001', CarbonImmutable::now()->startOfMonth()->subMonth()->endOfMonth()->format('Y-m-d'), 18, 20],
            ['22001002', CarbonImmutable::now()->startOfMonth()->subMonth()->endOfMonth()->format('Y-m-d'), 15, 20],
        ];
    }

    /**
     * Normalise whatever the spreadsheet handed back into `Y-m-d`, or null
     * when it is not a date at all.
     *
     * This is the field that breaks a real upload. An .xlsx date cell arrives
     * as a DateTime; a .csv that someone opened in Excel and re-saved arrives
     * as whatever Excel's locale decided to write, very often `31/8/2026`.
     * The importer used to pass the raw string to MySQL, which — with strict
     * mode on, as this project runs it — errors on the whole upload rather
     * than telling anyone which row was wrong.
     *
     * `d/m/Y` is preferred over `m/d/Y` for slashed dates: UTP is in Malaysia
     * and every date the portal displays is day-first. A genuinely ambiguous
     * value like `03/04/2026` is therefore read as 3 April, and the template
     * ships ISO dates so nobody has to rely on that.
     */
    public static function parseDate(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        // An .xls serial number that the reader did not convert. Excel counts
        // days from 1899-12-30.
        if (is_numeric($value) && ! is_string($value)) {
            return CarbonImmutable::create(1899, 12, 30)->addDays((int) $value)->format('Y-m-d');
        }

        $text = trim((string) $value);

        if ($text === '') {
            return null;
        }

        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'Y/m/d', 'd.m.Y'] as $format) {
            try {
                // The leading ! zeroes the time, so a value carrying one does
                // not drift the date across a timezone boundary.
                $parsed = CarbonImmutable::createFromFormat('!'.$format, $text);
            } catch (\Throwable) {
                continue;
            }

            // createFromFormat is forgiving — it will happily read "31/13/2026"
            // as a rolled-over date. Round-tripping catches that.
            if ($parsed && $parsed->format($format) === $text) {
                return $parsed->format('Y-m-d');
            }
        }

        return null;
    }

    /**
     * Parse a UTrace-shaped CSV/.xlsx file -- a path string or an
     * UploadedFile -- into normalised rows, after checking the header
     * matches COLUMNS exactly.
     *
     * Shared by the manual upload and InboxFolderSource so the two can
     * never drift on what counts as a valid file: a header row that passes
     * one is guaranteed to pass the other, because it is the same check.
     *
     * @return array{rows: array<int, array{matric_no: mixed, period_end: mixed, sessions_attended: mixed, sessions_total: mixed, line: int}>, error: ?string}
     */
    public static function readFile(mixed $file): array
    {
        try {
            $sheets = Excel::toArray(new AttendanceSheetImport(), $file);
        } catch (\Throwable $e) {
            report($e);

            return [
                'rows' => [],
                'error' => 'That file could not be read. Use a .csv or .xlsx export from UTrace, '
                    .'or start from the template on the upload page.',
            ];
        }

        $rows = $sheets[0] ?? [];
        $header = array_map(fn ($h) => strtolower(trim((string) $h)), array_shift($rows) ?? []);

        if (array_slice($header, 0, 4) !== self::COLUMNS) {
            return [
                'rows' => [],
                'error' => 'The first row must be exactly: '.implode(', ', self::COLUMNS)
                    .'. Download the template on the upload page to start from a correct file.',
            ];
        }

        $normalised = [];

        foreach ($rows as $i => $row) {
            [$matricNo, $periodEnd, $attended, $total] = array_pad(array_values($row), 4, null);

            $normalised[] = [
                'matric_no' => $matricNo,
                'period_end' => $periodEnd,
                'sessions_attended' => $attended,
                'sessions_total' => $total,
                // +2: the sheet's own numbering, counting the header as row 1.
                'line' => $i + 2,
            ];
        }

        return ['rows' => $normalised, 'error' => null];
    }
}
