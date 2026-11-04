<?php

namespace Tests\Feature\Jason;

use App\Modules\Jason\Support\AppointmentSheet;
use Tests\TestCase;

/**
 * The importer against the nomination export's real heading row.
 *
 * The export is the file CGS actually receives, and its key column is
 * headed "Application No." -- with the full stop. The importer normalised
 * headings without stripping it, so that column matched nothing and every
 * row quietly fell back to matching examiners by name. Two examiners who
 * share a name are indistinguishable on that path, which is the whole
 * reason the application number is carried.
 *
 * The headings below are copied from the export as literal strings rather
 * than read from it: nothing here imports from another member's folder, so
 * this test fails if either side changes a heading without telling the
 * other, which is exactly what it is for.
 */
class NominationExportHeadingsTest extends TestCase
{
    /** The nomination export's heading row, verbatim. */
    protected const EXPORT_HEADINGS = [
        'Application No.', 'Faculty', 'Department', 'Candidate', 'Matric No', 'Programme',
        'Thesis Title', 'Supervisor',
        'Internal Main', 'Internal Main Dept', 'Internal Backup',
        'External Main', 'External Main Institution', 'External Backup',
        'Stage', 'Status', 'Submitted',
        'Internal Main Email', 'External Main Email',
    ];

    public function test_the_export_key_column_is_matched_exactly(): void
    {
        $map = AppointmentSheet::mapHeader(self::EXPORT_HEADINGS);

        $this->assertArrayHasKey('nomination_id', $map,
            'The export\'s "Application No." column must be recognised, or rows fall back to name matching.');

        $this->assertSame(0, $map['nomination_id']);
    }

    /** Every column the letters are written from, found in the real export. */
    public function test_the_export_fills_every_column_the_letters_need(): void
    {
        $map = AppointmentSheet::mapHeader(self::EXPORT_HEADINGS);

        foreach ([
            'nomination_id' => 0,
            'faculty' => 1,
            'department' => 2,
            'candidate' => 3,
            'matric_no' => 4,
            'programme' => 5,
            'thesis_title' => 6,
            'supervisor_name' => 7,
            'internal_main' => 8,
            'internal_main_dept' => 9,
            'internal_backup' => 10,
            'external_main' => 11,
            'external_main_inst' => 12,
            'external_backup' => 13,
        ] as $key => $index) {
            $this->assertSame($index, $map[$key] ?? null, "The export's {$key} column was not matched.");
        }
    }

    /** Trailing punctuation is noise, wherever it appears. */
    public function test_trailing_punctuation_never_decides_a_match(): void
    {
        foreach (['Application No.', 'APPLICATION NO', ' application  no. ', 'Application No:'] as $heading) {
            $this->assertSame('application no', AppointmentSheet::normalise($heading));
        }
    }

    /**
     * Columns are only ever added at the end of the export, so a heading
     * row from before the email columns existed still has to work.
     */
    public function test_an_older_export_without_the_email_columns_still_maps(): void
    {
        $older = array_slice(self::EXPORT_HEADINGS, 0, 17);

        $this->assertSame(0, AppointmentSheet::mapHeader($older)['nomination_id'] ?? null);
    }
}
