<?php

namespace App\Modules\Hani\Support\Import;

use App\Modules\Hani\Models\Examiner;

/**
 * CGS's own pool file, as Puan Waheeda keeps it today: an "Internal Examiner"
 * sheet and an "External Examiner" sheet, each laid out the way CGS has always
 * laid it out (see Database/demo/hani_examiner_pool_cgs_format_demo.xlsx).
 *
 * Reading it as-is is the point -- CGS should not have to retype the list into
 * a new template to start using the portal. So this tolerates what the real
 * file does: headers in any case, with line breaks and stray spaces; the
 * institution abbreviation in brackets after the name; the faculty approval as
 * month.year; the area of expertise as a numbered multi-line list; the Details
 * cell as "Experience: 11 years / MSc Grad: 5 / PhD Grad: 5" lines; and an
 * institution column whose header holds a stray value instead of a title.
 *
 * Only parses. Whether a row is valid, new or an update is PoolImporter's.
 */
class CgsLayoutReader
{
    /** Header (Cells::header form) => field. First match wins. */
    public const INTERNAL_HEADERS = [
        'no' => ['no', 'no.', 'bil'],
        'name' => ['approved internal examiner', 'internal examiner', 'name'],
        'email' => ['email', 'e-mail', 'email address'],
        'department' => ['department', 'dept'],
        'availability' => ['availability', 'status of availability'],
        'remarks' => ['remarks', 'remark'],
    ];

    public const EXTERNAL_HEADERS = [
        'name' => ['name examiner', 'name of examiner', 'examiner name', 'name'],
        'email' => ['email', 'e-mail', 'email address'],
        'faculty_approval' => ['faculty approval'],
        'remarks' => ['remarks'],
        'availability' => ['status of availability', 'availability'],
        'date_first' => ['date 1st', 'date 1', 'first date'],
        'date_second' => ['date 2nd', 'date 2', 'second date'],
        'student' => ['student name', 'student'],
        'next' => ['remark/next availability', 'remarks/next availability', 'next availability'],
        'expertise' => ['area of expertise', 'expertise'],
        'department' => ['department related utp', 'department related to utp', 'utp department'],
        'cluster' => ['utp acad cluster', 'utp academic cluster', 'utp cluster'],
        'details' => ['details'],
        'sector' => ['university/industry'],
        'institution' => ['institution', 'university', 'university name'],
    ];

    /** Does this header row look like CGS's internal sheet? */
    public static function isInternal(array $headers): bool
    {
        return in_array('approved internal examiner', $headers, true);
    }

    public static function isExternal(array $headers): bool
    {
        return in_array('name examiner', $headers, true)
            || (in_array('area of expertise', $headers, true) && in_array('department related utp', $headers, true));
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows  the sheet's rows after the header
     * @param  array<int, string>  $headers  Cells::header() of each header cell
     * @return array<int, PoolRow>
     */
    public function internal(string $sheet, array $rows, array $headers, int $firstRowNumber): array
    {
        $col = self::columns($headers, self::INTERNAL_HEADERS);
        $out = [];

        foreach ($rows as $i => $cells) {
            $name = Cells::text($cells[$col['name']] ?? null);

            if ($name === null) {
                continue;
            }

            $row = new PoolRow("{$sheet}, row ".($firstRowNumber + $i));
            $row->attributes = [
                'type' => Examiner::TYPE_INTERNAL,
                'name' => $name,
                'email' => self::email($cells[$col['email'] ?? -1] ?? null),
            ];
            $row->departmentAsWritten = Cells::text($cells[$col['department'] ?? -1] ?? null);

            $this->availability(
                $row,
                Cells::yesNo($cells[$col['availability'] ?? -1] ?? null),
                Cells::text($cells[$col['remarks'] ?? -1] ?? null),
                null,
                studentsInRemarks: true,
            );

            $out[] = $row;
        }

        return $out;
    }

    /** @return array<int, PoolRow> */
    public function external(string $sheet, array $rows, array $headers, int $firstRowNumber): array
    {
        $col = self::columns($headers, self::EXTERNAL_HEADERS);

        // The institution column's header is not always a title: CGS's file
        // carries a stray value ("Yeungnam University, Republic") there. When
        // no header names it, it is the column between Details and
        // University/Industry.
        if (! isset($col['institution']) && isset($col['details'], $col['sector'])
            && $col['sector'] - $col['details'] === 2) {
            $col['institution'] = $col['details'] + 1;
        }

        $out = [];

        foreach ($rows as $i => $cells) {
            $raw = Cells::text($cells[$col['name']] ?? null);

            if ($raw === null) {
                continue;
            }

            [$name, $hint] = Cells::splitInstitutionHint($raw);

            $row = new PoolRow("{$sheet}, row ".($firstRowNumber + $i));
            $institution = Cells::text($cells[$col['institution'] ?? -1] ?? null) ?? $hint;

            $dates = array_filter([
                Cells::date($cells[$col['date_first'] ?? -1] ?? null),
                Cells::date($cells[$col['date_second'] ?? -1] ?? null),
            ]);
            sort($dates);

            $details = (string) ($cells[$col['details'] ?? -1] ?? '');
            $expertise = Cells::text($cells[$col['expertise'] ?? -1] ?? null);

            $row->attributes = array_filter([
                'type' => Examiner::TYPE_EXTERNAL,
                'name' => $name,
                'email' => self::email($cells[$col['email'] ?? -1] ?? null),
                'institution' => $institution,
                'faculty_approval' => Cells::facultyApproval($cells[$col['faculty_approval'] ?? -1] ?? null),
                'utp_cluster' => Cells::text($cells[$col['cluster'] ?? -1] ?? null),
                'sector' => self::sector($cells[$col['sector'] ?? -1] ?? null),
                'expertise' => $expertise,
                // Date 1st / Date 2nd: the earliest is the first examination;
                // the latest is the one the 90-day gap runs from.
                'first_examination_date' => $dates ? reset($dates) : null,
                'last_examination_date' => $dates ? end($dates) : null,
                'years_experience' => self::detail($details, 'experience'),
                'msc_graduated' => self::detail($details, 'msc\s*grad(?:uate)?s?'),
                'phd_graduated' => self::detail($details, 'phd\s*grad(?:uate)?s?'),
            ], fn ($v) => $v !== null);

            $row->keywords = Cells::terms($expertise);
            $row->departmentAsWritten = Cells::text($cells[$col['department'] ?? -1] ?? null);

            if ($hint && $institution !== $hint && ! str_contains((string) $institution, $hint)) {
                $row->warnings[] = "Name says ({$hint}) but the institution column says {$institution}.";
            }

            $this->availability(
                $row,
                Cells::yesNo($cells[$col['availability'] ?? -1] ?? null),
                Cells::text($cells[$col['next'] ?? -1] ?? null) ?? Cells::text($cells[$col['remarks'] ?? -1] ?? null),
                Cells::text($cells[$col['student'] ?? -1] ?? null),
                studentsInRemarks: false,
            );

            $out[] = $row;
        }

        return $out;
    }

    /**
     * Availability is "Yes"/"No". A "No" is either a student currently held
     * (recorded for review, never turned into an invented nomination) or a
     * reason the examiner cannot examine (retired, resigned), which marks
     * them Unavailable. CGS confirmed an examiner holds ONE assignment at a
     * time: the first student listed is the current one, and any others are
     * flagged for someone to check.
     */
    protected function availability(PoolRow $row, ?bool $available, ?string $remark, ?string $studentCell, bool $studentsInRemarks): void
    {
        $students = [];

        if ($studentsInRemarks && $remark !== null && preg_match('/_\s*\d{5,}/u', $remark)) {
            // "Ong Wei Ming_22000731", possibly several.
            $students = array_values(array_filter(array_map('trim', preg_split('/[\r\n;,]+/u', $remark))));
            $remark = null;
        } elseif ($studentCell !== null) {
            $students = array_values(array_filter(array_map('trim', preg_split('/[\r\n;]+/u', $studentCell))));
        }

        $row->students = $students;

        if ($available === false && $students === []) {
            $row->attributes['is_active'] = false;
            $row->attributes['unavailable_reason'] = $remark;
        } elseif ($available !== null) {
            $row->attributes['is_active'] = true;
        }

        if (count($students) > 1) {
            $row->warnings[] = 'Check: more than one student listed ('.implode(', ', $students).'). '
                .'The first is recorded as the current assignment.';
        }
    }

    /** @return array<string, int> field => column index */
    public static function columns(array $headers, array $map): array
    {
        $col = [];

        foreach ($map as $field => $aliases) {
            foreach ($aliases as $alias) {
                $index = array_search($alias, $headers, true);

                if ($index !== false && ! in_array($index, $col, true)) {
                    $col[$field] = $index;
                    break;
                }
            }
        }

        return $col;
    }

    protected static function email(mixed $value): ?string
    {
        $email = Cells::text($value);

        return $email === null ? null : mb_strtolower($email);
    }

    protected static function sector(mixed $value): ?string
    {
        $text = mb_strtolower((string) Cells::text($value));

        return in_array($text, ['technical', 'research'], true) ? $text : null;
    }

    /** "Experience: 11 years" -> 11, from the Details cell's lines. */
    protected static function detail(string $details, string $label): ?int
    {
        return preg_match('/'.$label.'\s*:\s*(\d+)/iu', $details, $m) ? (int) $m[1] : null;
    }
}
