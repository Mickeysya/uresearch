<?php

namespace App\Modules\Jason\Exports;

use App\Modules\Jason\Support\AppointmentSheet;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * The blank examiner list CGS starts from when they have no file from the
 * faculty side, and the reference for what the importer accepts.
 */
class AppointmentTemplateExport implements FromArray, WithHeadings
{
    /** @return array<int, string> */
    public function headings(): array
    {
        return AppointmentSheet::COLUMNS;
    }

    /** @return array<int, array<int, string>> */
    public function array(): array
    {
        return AppointmentSheet::sampleRows();
    }
}
