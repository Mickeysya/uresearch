<?php

namespace App\Modules\Nureen\Exports;

use App\Modules\Nureen\Support\AttendanceSheet;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * The .xlsx half of the blank attendance template.
 *
 * Both headings and rows come from AttendanceSheet, so the file CGS downloads
 * is by construction the file AttendanceController::upload() accepts.
 *
 * The .xlsx is the version worth recommending: Excel keeps `period_end` as a
 * real date cell, which the importer reads as a DateTime and normalises
 * exactly. A .csv filled in and re-saved through Excel is where dates get
 * rewritten into the local format — survivable now that the importer parses
 * those too, but avoidable entirely by starting from this file.
 */
class AttendanceTemplateExport implements FromArray, WithHeadings
{
    /** @return array<int, string> */
    public function headings(): array
    {
        return AttendanceSheet::COLUMNS;
    }

    /** @return array<int, array<int, string|int>> */
    public function array(): array
    {
        return AttendanceSheet::sampleRows();
    }
}
