<?php

namespace App\Modules\Jason\Exports;

use App\Modules\Jason\Support\AppointmentSheet;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * The blank examiner list CGS starts from when they have no file from the
 * nomination chain, and the reference for what the importer accepts.
 *
 * One row per candidate, with the panel in four named slots. The importer
 * reads columns by name in any order, so this is a guide rather than a
 * contract -- a real export with extra or differently ordered columns
 * imports just as well.
 */
class AppointmentTemplateExport implements FromArray, WithHeadings
{
    /** @return array<int, string> */
    public function headings(): array
    {
        return AppointmentSheet::TEMPLATE_HEADINGS;
    }

    /** @return array<int, array<int, string>> */
    public function array(): array
    {
        return AppointmentSheet::sampleRows();
    }
}
