<?php

namespace App\Modules\Jason\Support;

/**
 * The shape of the finalised examiner list CGS receives.
 *
 * It is an export of the nomination chain: ONE ROW PER CANDIDATE, with the
 * panel in four named slots -- internal main and backup, external main and
 * backup -- plus the candidate's own details and the nomination's own
 * application number, stage and status.
 *
 * Columns are matched BY NAME, case- and spacing-insensitively, in any
 * order, and anything unrecognised is ignored. An export gains and loses
 * trailing columns as the nomination screen changes, and a list that is
 * right in every way except column order is still the right list.
 *
 * Only `matric no` is required: it is what ties a row to a candidate in this
 * portal. Everything else is either filled in from the row where it is
 * present, or read from the examiner records the nomination chain keeps --
 * see ExaminerDirectory -- or confirmed by CGS on the preparation screen.
 */
final class AppointmentSheet
{
    /** The only column the importer cannot do without. */
    public const REQUIRED = 'matric no';

    /**
     * What the importer looks for, and the aliases each is known by.
     *
     * The key is how the rest of this module refers to the value; the
     * aliases are what the heading may actually say.
     */
    public const COLUMNS = [
        'nomination_id' => ['application', 'application no', 'application id', 'nomination'],
        'faculty' => ['faculty'],
        'department' => ['department', 'dept'],
        'candidate' => ['candidate', 'candidate name', 'student', 'name'],
        'matric_no' => ['matric no', 'matric', 'matric number', 'matric_no'],
        'programme' => ['programme', 'program', 'mode of study'],
        'thesis_title' => ['thesis title', 'title of thesis', 'title'],
        'supervisor_name' => ['supervisor', 'supervisor name', 'main supervisor'],
        'internal_main' => ['internal main', 'internal main examiner', 'internal examiner'],
        'internal_main_dept' => ['internal main dept', 'internal main department'],
        'internal_backup' => ['internal backup', 'internal backup examiner'],
        'internal_backup_dept' => ['internal backup dept', 'internal backup department'],
        'external_main' => ['external main', 'external main examiner', 'external examiner'],
        'external_main_inst' => [
            'external main inst', 'external main institution', 'external main dept',
            'external main department', 'external main university',
        ],
        'external_backup' => ['external backup', 'external backup examiner'],
        'external_backup_inst' => [
            'external backup inst', 'external backup institution', 'external backup dept',
            'external backup department', 'external backup university',
        ],
        'stage' => ['stage'],
        'status' => ['status'],
        'submitted' => ['submitted', 'submitted at', 'submitted on'],
    ];

    /**
     * The four panel slots, as [name column, detail column, kind, role].
     *
     * Main and backup both receive an appointment letter and a thesis
     * evaluation report: CGS appoints the full panel, and a backup who is
     * never called on simply never returns a report.
     */
    public const SLOTS = [
        ['internal_main', 'internal_main_dept', 'internal', 'main'],
        ['internal_backup', 'internal_backup_dept', 'internal', 'backup'],
        ['external_main', 'external_main_inst', 'external', 'main'],
        ['external_backup', 'external_backup_inst', 'external', 'backup'],
    ];

    /** The headings the template is written with, in the export's order. */
    public const TEMPLATE_HEADINGS = [
        'Application', 'Faculty', 'Department', 'Candidate', 'Matric No', 'Programme',
        'Thesis Title', 'Supervisor', 'Internal Main', 'Internal Main Dept', 'Internal Backup',
        'External Main', 'External Main Inst', 'External Backup', 'Stage', 'Status', 'Submitted',
    ];

    /**
     * Maps a sheet's header row onto the keys above.
     *
     * @param  array<int, mixed>  $header
     * @return array<string, int>  key => column index
     */
    public static function mapHeader(array $header): array
    {
        $seen = [];

        foreach ($header as $index => $heading) {
            $heading = self::normalise($heading);

            if ($heading === '') {
                continue;
            }

            foreach (self::COLUMNS as $key => $aliases) {
                // First column to claim a key keeps it, so a later
                // near-duplicate heading cannot displace the real one.
                if (! isset($seen[$key]) && in_array($heading, $aliases, true)) {
                    $seen[$key] = $index;
                    break;
                }
            }
        }

        return $seen;
    }

    /** Lower-cased, trimmed, single-spaced, without a trailing colon or asterisk. */
    public static function normalise(mixed $value): string
    {
        $value = preg_replace('/\s+/', ' ', trim((string) $value));

        return mb_strtolower(rtrim($value, " :*"));
    }

    /**
     * Two filled-in rows, so the person opening the template can see both
     * the four slots and that one row is one candidate.
     *
     * @return array<int, array<int, string>>
     */
    public static function sampleRows(): array
    {
        return [
            [
                '2', 'FSMC', 'Computing', 'Ahmad Danial', '22001001', 'MSc Full-Time',
                'Introduction to IoT', 'Dr. Aisyah Rahman',
                'Dr. Chandra Segaran', 'Civil & Environmental Engineering',
                'Prof. Dr. Rosli Hamid',
                'Assoc Prof Dr Azimah Binti Hussin', 'Universiti Kebangsaan Malaysia',
                'Professor Ir Ts Dr Muzamir Bin Isa',
                '—', 'Approved', '2026-09-14',
            ],
            [
                '3', 'FOE', 'Petroleum Engineering', 'Nur Farah Adilah', '22001002', 'PhD Full-Time',
                'Impure CO2 Trapping Efficiency in Sandstone Aquifers', 'Dr. Aisyah Rahman',
                'Dr Hafizah Binti Mohd Yusof', 'Computer & Information Sciences',
                'Prof. Dr. Rosli Hamid',
                'Prof Dr Ravi Shankar A/L Krishnan', 'Universiti Putra Malaysia',
                'Dr Sarah Mitchell',
                '—', 'Approved', '2026-09-14',
            ],
        ];
    }
}
