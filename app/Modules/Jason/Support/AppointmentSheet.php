<?php

namespace App\Modules\Jason\Support;

use App\Modules\Jason\Models\AppointmentExaminer;

/**
 * The shape of the finalised examiner list CGS receives, in one place.
 *
 * The template CGS downloads, the header check the importer runs and the
 * row parsing all read from here, so a column cannot be added to the
 * template without the importer knowing about it.
 *
 * ONE ROW PER EXAMINER, not per candidate. A candidate has at least two
 * examiners, so their details repeat down the rows and the importer groups
 * on `matric_no` -- which is how the list arrives from the faculty side,
 * and how anybody would type it into a spreadsheet.
 */
final class AppointmentSheet
{
    /**
     * The columns, in order. The importer requires exactly these as the
     * first row; anything after them is ignored, so a list carrying extra
     * trailing columns still imports.
     */
    public const COLUMNS = [
        'matric_no',
        'degree',
        'programme',
        'supervisor_name',
        'thesis_title',
        'examiner_type',
        'examiner_name',
        'examiner_institution',
        'examiner_email',
        'examiner_address',
    ];

    /**
     * The candidate's own columns. Repeated on every row for that
     * candidate, so the importer takes them from the first row it sees and
     * ignores the rest.
     */
    public const CANDIDATE_COLUMNS = ['degree', 'programme', 'supervisor_name', 'thesis_title'];

    /**
     * Four filled-in rows: two candidates, one internal and one external
     * examiner each, so whoever opens the template can see both that the
     * candidate columns repeat and that the panel is at least one of each.
     *
     * Real seeded matric numbers, so a download-fill-upload round trip
     * lands on a student on a fresh database instead of silently skipping
     * every row.
     *
     * @return array<int, array<int, string>>
     */
    public static function sampleRows(): array
    {
        return [
            [
                '22001001', 'MSc in Petroleum Engineering', 'Petroleum Engineering',
                'Assoc Prof Dr Mysara Eissa Mohyaldinn',
                'Analysis and Prediction of Impure CO2 Trapping Efficiency in Sandstone Deep Saline Aquifers',
                'internal', 'Dr Aisyah Binti Kamaruddin', 'Department of Petroleum Engineering, UTP',
                'aisyah.kamaruddin@utp.edu.my',
                "Department of Petroleum Engineering\nUniversiti Teknologi PETRONAS\n32610 Seri Iskandar, Perak",
            ],
            [
                '22001001', 'MSc in Petroleum Engineering', 'Petroleum Engineering',
                'Assoc Prof Dr Mysara Eissa Mohyaldinn',
                'Analysis and Prediction of Impure CO2 Trapping Efficiency in Sandstone Deep Saline Aquifers',
                'external', 'Assoc Prof Dr Azimah Binti Hussin', 'Universiti Kebangsaan Malaysia',
                'azimah@ukm.edu.my',
                "Department of Earth Sciences and Environment\nUniversiti Kebangsaan Malaysia\n43600 Bangi\nSelangor",
            ],
            [
                '22001002', 'PhD in Electrical and Electronic Engineering', 'Electrical and Electronic Engineering',
                'Assoc Prof Ir Dr Nursyarizal Bin Mohd Nor',
                'Data-Centric and Risk-Informed Machine Learning Framework for Fault Diagnosis of Oil-Filled Transformers',
                'internal', 'Dr Muhammad Faris Bin Zulkifli',
                'Department of Electrical & Electronic Engineering, UTP', 'faris.zulkifli@utp.edu.my',
                "Department of Electrical & Electronic Engineering\nUniversiti Teknologi PETRONAS\n32610 Seri Iskandar, Perak",
            ],
            [
                '22001002', 'PhD in Electrical and Electronic Engineering', 'Electrical and Electronic Engineering',
                'Assoc Prof Ir Dr Nursyarizal Bin Mohd Nor',
                'Data-Centric and Risk-Informed Machine Learning Framework for Fault Diagnosis of Oil-Filled Transformers',
                'external', 'Professor Ir Ts Dr Muzamir Bin Isa', 'Universiti Malaysia Perlis',
                'muzamir@unimap.edu.my',
                "School of Electrical System Engineering\nUniversiti Malaysia Perlis\n02600 Arau\nPerlis",
            ],
        ];
    }

    /**
     * Normalise whatever the spreadsheet handed back for the examiner kind.
     *
     * The list is typed by people, so "Internal", "INTERNAL", "internal "
     * and "Internal Examiner" all have to land on the same value -- and
     * anything else has to be rejected by name rather than quietly imported
     * as an external examiner, because the kind decides which letter
     * template the examiner receives.
     */
    public static function parseType(mixed $value): ?string
    {
        $value = strtolower(trim((string) $value));

        return match (true) {
            $value === '' => null,
            str_starts_with($value, 'int') => AppointmentExaminer::TYPE_INTERNAL,
            str_starts_with($value, 'ext') => AppointmentExaminer::TYPE_EXTERNAL,
            default => null,
        };
    }
}
