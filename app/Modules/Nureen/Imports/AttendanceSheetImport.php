<?php

namespace App\Modules\Nureen\Imports;

use Maatwebsite\Excel\Concerns\Import;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;

/**
 * Reads a UTrace attendance export as plain rows.
 *
 * Deliberately thin: AttendanceController already owns the header check, the
 * matric lookup and the derived percentage, and splitting that across two
 * classes would be worse than leaving it where it is. This exists only so the
 * same controller can read .xlsx as well as .csv — CGS exports xlsx from
 * UTrace, and before this someone had to convert every file by hand.
 *
 * It carries no `array()` method on purpose. `Excel::toArray()` hands the
 * sheets straight back to the caller; the ToArray *concern* is a different
 * contract (it returns void, for imports that consume rows themselves) and
 * implementing it here is a fatal signature clash. `Import` is the marker
 * interface `Excel::toArray()` type-hints against, so it has to be declared
 * even though it defines no methods.
 *
 * SkipsEmptyRows keeps a spreadsheet's trailing blank rows — which xlsx files
 * very often carry and csv files do not — out of the parse.
 */
class AttendanceSheetImport implements Import, SkipsEmptyRows
{
}
