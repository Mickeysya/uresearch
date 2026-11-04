<?php

namespace App\Modules\Hani\Support;

use App\Modules\Hani\Models\ReVivaSummary;
use App\Modules\Hani\Support\Pdf\PdfReader;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * The digital Summary of Corrections: what the system checks, and the PDF.
 *
 * Checks, each with its reason:
 *   - each examiner has at least one comment
 *   - every comment has the action taken
 *   - every comment names the updated pages, written as "12", "12-14" or
 *     "3, 5, 7-9" (front-matter roman numerals such as "iv" are accepted
 *     but cannot be checked against the page count)
 *   - every page is within the corrected thesis's page count, read with
 *     the built-in PDF reader; when the PDF cannot be read, the page check
 *     says "Not checked automatically" and does not block
 */
final class SummaryOfCorrections
{
    public const PASS = 'pass';

    public const PROBLEM = 'problem';

    public const UNCHECKED = 'unchecked';

    /** The page count of a PDF, or null when the reader cannot tell. */
    public static function pageCount(string $bytes): ?int
    {
        $facts = app(PdfReader::class)->facts($bytes);

        return $facts['is_pdf'] && ! $facts['encrypted'] ? $facts['pages'] : null;
    }

    /**
     * "3, 5, 7-9" -> [3, 5, 7, 8, 9]; roman numerals listed separately.
     *
     * @return array{pages: array<int, int>, roman: array<int, string>}|null null when it cannot be read
     */
    public static function parsePages(string $value): ?array
    {
        $pages = [];
        $roman = [];

        foreach (preg_split('/[,;]+/', trim($value)) as $part) {
            $part = strtolower(trim(preg_replace('/^(pp?\.?|pages?)\s*/i', '', trim($part))));

            if ($part === '') {
                continue;
            }
            if (preg_match('/^(\d+)\s*[-–]\s*(\d+)$/u', $part, $m) && (int) $m[1] <= (int) $m[2] && (int) $m[2] - (int) $m[1] <= 500) {
                $pages = array_merge($pages, range((int) $m[1], (int) $m[2]));
            } elseif (ctype_digit($part)) {
                $pages[] = (int) $part;
            } elseif (preg_match('/^[ivxlc]+$/', $part)) {
                $roman[] = $part;
            } else {
                return null;
            }
        }

        return $pages === [] && $roman === [] ? null : ['pages' => array_values(array_unique($pages)), 'roman' => $roman];
    }

    /**
     * @return array{ok: bool, checks: array<int, array{status: string, text: string}>}
     */
    public static function check(ReVivaSummary $summary): array
    {
        $checks = [];
        $rows = $summary->rows ?? [];

        foreach (ReVivaSummary::EXAMINERS as $n => $label) {
            $count = count($summary->rowsFor($n));
            $checks[] = $count
                ? ['status' => self::PASS, 'text' => "{$label}: {$count} ".($count === 1 ? 'comment' : 'comments').'.']
                : ['status' => self::PROBLEM, 'text' => "{$label}: no comments yet. Add every comment from this examiner's report."];
        }

        foreach ($rows as $i => $row) {
            $where = (ReVivaSummary::EXAMINERS[(int) $row['examiner']] ?? 'Examiner').', comment '.(count(array_filter(array_slice($rows, 0, $i + 1), fn ($r) => $r['examiner'] == $row['examiner'])));

            if (blank($row['action'] ?? null)) {
                $checks[] = ['status' => self::PROBLEM, 'text' => "{$where}: the action taken is missing."];
            }

            $parsed = self::parsePages((string) ($row['pages'] ?? ''));

            if ($parsed === null) {
                $checks[] = ['status' => self::PROBLEM, 'text' => "{$where}: give the updated pages as numbers, for example \"12\", \"12-14\" or \"3, 5, 7-9\"."];

                continue;
            }

            if ($summary->thesis_pages === null) {
                $checks[] = ['status' => self::UNCHECKED, 'text' => "{$where}: pages not checked automatically (the corrected thesis could not be read)."];
            } elseif ($beyond = array_filter($parsed['pages'], fn ($p) => $p < 1 || $p > $summary->thesis_pages)) {
                $checks[] = ['status' => self::PROBLEM, 'text' => "{$where}: page ".implode(', ', $beyond)." is beyond the thesis's {$summary->thesis_pages} pages."];
            }

            if ($parsed['roman'] !== []) {
                $checks[] = ['status' => self::UNCHECKED, 'text' => "{$where}: front-matter page ".implode(', ', $parsed['roman']).' accepted, not checked against the page count.'];
            }
        }

        if ($summary->thesis_pages !== null) {
            $checks[] = ['status' => self::PASS, 'text' => "Read the corrected thesis ({$summary->thesis_name}): {$summary->thesis_pages} pages."];
        }

        $problems = array_filter($checks, fn ($c) => $c['status'] === self::PROBLEM);
        if ($rows !== [] && ! $problems) {
            $checks[] = ['status' => self::PASS, 'text' => 'Every comment has an action and pages within the thesis.'];
        }

        return ['ok' => $rows !== [] && ! $problems, 'checks' => $checks];
    }

    public static function render(ReVivaSummary $summary): string
    {
        $student = $summary->student;

        return Pdf::loadView('hani::re_viva.pdf.summary_of_corrections', [
            'summary' => $summary,
            'student' => $student,
            'supervisor' => $student->supervisor?->name,
            'thesisTitle' => ReVivaWindow::categoryFourViva($student)?->thesis_title ?? '-',
            'institution' => config('hani.reviva.letter.institution', 'Universiti Teknologi PETRONAS'),
        ])
            ->setPaper('a4')
            ->setOption(['isFontSubsettingEnabled' => true, 'defaultFont' => 'DejaVu Serif', 'isRemoteEnabled' => false])
            ->output();
    }
}
