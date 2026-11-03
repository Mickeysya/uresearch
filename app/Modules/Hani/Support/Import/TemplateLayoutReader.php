<?php

namespace App\Modules\Hani\Support\Import;

use App\Modules\Hani\Models\Examiner;

/**
 * The UResearch pool template: an "Examiners" sheet (one row per examiner,
 * internal and external together) and a "Publications" sheet. The template
 * offered for download is built from EXAMINER_HEADERS and
 * PUBLICATION_HEADERS, so the file CGS fills in and the file this reads can
 * never drift apart.
 *
 * Also the shape HaniDemoSeeder feeds in from hani_demo_dataset.json, via
 * record(), so the seeder and a real upload go through exactly the same rules.
 */
class TemplateLayoutReader
{
    /** Column header => field. The order is the template's column order. */
    public const EXAMINER_HEADERS = [
        'Type' => 'type',
        'Full Name' => 'name',
        'Email' => 'email',
        'Department' => 'department',
        'Faculty' => 'faculty',
        'Institution' => 'institution',
        'Sector' => 'sector',
        'UTP Cluster' => 'utp_cluster',
        'Area of Expertise' => 'expertise',
        'Keywords' => 'keywords',
        'Years of Experience' => 'years_experience',
        'MSc Graduates Supervised' => 'msc_graduated',
        'PhD Graduates Supervised' => 'phd_graduated',
        'First Examination Date' => 'first_examination_date',
        'Last Examination Date' => 'last_examination_date',
        'Status' => 'status',
        'Unavailable Reason' => 'unavailable_reason',
        'Faculty Approval Ref' => 'faculty_approval',
        'Current UTP Appointment' => 'current_utp_appointment',
        'Former UTP Staff/Student Until' => 'former_utp_until',
    ];

    public const PUBLICATION_HEADERS = [
        'Examiner Email' => 'email',
        'Title' => 'title',
        'Year' => 'year',
        'Co-authors (semicolon-separated)' => 'coauthors',
    ];

    public static function isExaminers(array $headers): bool
    {
        return in_array('type', $headers, true) && in_array('full name', $headers, true);
    }

    public static function isPublications(array $headers): bool
    {
        return in_array('examiner email', $headers, true) && in_array('title', $headers, true);
    }

    /** @return array<int, PoolRow> */
    public function examiners(string $sheet, array $rows, array $headers, int $firstRowNumber): array
    {
        $col = self::columns($headers, self::EXAMINER_HEADERS);
        $out = [];

        foreach ($rows as $i => $cells) {
            $record = [];

            foreach ($col as $field => $index) {
                $record[$field] = $cells[$index] ?? null;
            }

            if (Cells::text($record['name'] ?? null) === null) {
                continue;
            }

            $out[] = $this->record($record, "{$sheet}, row ".($firstRowNumber + $i));
        }

        return $out;
    }

    /**
     * One examiner from field => raw value. Public for HaniDemoSeeder.
     *
     * @param  array<string, mixed>  $record
     */
    public function record(array $record, string $source): PoolRow
    {
        $row = new PoolRow($source);
        $type = mb_strtolower((string) Cells::text($record['type'] ?? null));
        $status = mb_strtolower((string) Cells::text($record['status'] ?? null));

        $row->attributes = array_filter([
            'type' => $type ?: null,
            'name' => Cells::text($record['name'] ?? null),
            'email' => ($email = Cells::text($record['email'] ?? null)) ? mb_strtolower($email) : null,
            'institution' => Cells::text($record['institution'] ?? null),
            'sector' => in_array($sector = mb_strtolower((string) Cells::text($record['sector'] ?? null)), ['technical', 'research'], true) ? $sector : null,
            'utp_cluster' => Cells::text($record['utp_cluster'] ?? null),
            'expertise' => Cells::text($record['expertise'] ?? null),
            'years_experience' => Cells::integer($record['years_experience'] ?? null),
            'msc_graduated' => Cells::integer($record['msc_graduated'] ?? null),
            'phd_graduated' => Cells::integer($record['phd_graduated'] ?? null),
            'first_examination_date' => Cells::date($record['first_examination_date'] ?? null),
            'last_examination_date' => Cells::date($record['last_examination_date'] ?? null),
            'faculty_approval' => Cells::facultyApproval($record['faculty_approval'] ?? null),
            'current_utp_appointment' => Cells::text($record['current_utp_appointment'] ?? null),
            'former_utp_until' => Cells::date($record['former_utp_until'] ?? null),
        ], fn ($v) => $v !== null);

        if ($status !== '') {
            $active = Cells::yesNo($status);

            if ($active === null) {
                $row->errors[] = "Status must be Active or Unavailable, not \"{$status}\".";
            } else {
                $row->attributes['is_active'] = $active;
                $row->attributes['unavailable_reason'] = $active ? null : Cells::text($record['unavailable_reason'] ?? null);
            }
        }

        foreach (['first_examination_date', 'last_examination_date', 'former_utp_until'] as $date) {
            if (Cells::text($record[$date] ?? null) !== null && ! isset($row->attributes[$date])) {
                $row->warnings[] = "Could not read the date \"{$record[$date]}\" ({$date}); left blank.";
            }
        }

        $row->keywords = Cells::terms($record['keywords'] ?? null);
        $row->departmentAsWritten = Cells::text($record['department'] ?? null);

        if ($type !== '' && ! in_array($type, [Examiner::TYPE_INTERNAL, Examiner::TYPE_EXTERNAL], true)) {
            $row->errors[] = "Type must be internal or external, not \"{$type}\".";
        }

        return $row;
    }

    /**
     * The Publications sheet, keyed by examiner email (lower case).
     *
     * @return array<string, array<int, array{title: string, year: ?int, coauthors: ?string, source: string}>>
     */
    public function publications(string $sheet, array $rows, array $headers, int $firstRowNumber): array
    {
        $col = self::columns($headers, self::PUBLICATION_HEADERS);
        $out = [];

        foreach ($rows as $i => $cells) {
            $email = Cells::text($cells[$col['email'] ?? -1] ?? null);
            $title = Cells::text($cells[$col['title'] ?? -1] ?? null);

            if ($email === null || $title === null) {
                continue;
            }

            $year = Cells::integer($cells[$col['year'] ?? -1] ?? null);

            $out[mb_strtolower($email)][] = [
                'title' => mb_substr($title, 0, 500),
                'year' => $year && $year > 1900 && $year < 2200 ? $year : null,
                'coauthors' => Cells::text($cells[$col['coauthors'] ?? -1] ?? null),
                'source' => "{$sheet}, row ".($firstRowNumber + $i),
            ];
        }

        return $out;
    }

    /** @return array<string, int> field => column index, matched on Cells::header() */
    protected static function columns(array $headers, array $map): array
    {
        $col = [];

        foreach ($map as $label => $field) {
            $key = Cells::header($label);
            // "Co-authors (semicolon-separated)" also matches "Co-authors".
            $short = Cells::header(preg_replace('/\s*\(.*\)$/', '', $label));

            foreach ([$key, $short] as $candidate) {
                $index = array_search($candidate, $headers, true);

                if ($index !== false) {
                    $col[$field] = $index;
                    break;
                }
            }
        }

        return $col;
    }
}
