<?php

namespace App\Modules\Hani\Imports;

use Maatwebsite\Excel\Concerns\Import;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;

/**
 * Reads a pool file (xlsx or csv) as plain rows, every sheet.
 *
 * Deliberately empty, as Nureen's AttendanceSheetImport is: Excel::toArray()
 * hands the sheets back, and Support\Import\PoolImporter decides what each
 * sheet is from its header row -- not from its tab name, which CGS is free to
 * rename. `Import` is the marker Excel::toArray() type-hints against.
 */
class PoolWorkbookImport implements Import, SkipsEmptyRows
{
}
