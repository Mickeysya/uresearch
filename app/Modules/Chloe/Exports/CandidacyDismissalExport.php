<?php

namespace App\Modules\Chloe\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Cell\StringValueBinder;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * The Dismissal List as the Registry receives it: exactly the rows the page shows under the current filters. "Exported on" is left blank in
 * the file (it only matters on the page). Every value is written as text, so a Student ID never turns into a number.
 */
class CandidacyDismissalExport extends StringValueBinder implements FromCollection, WithColumnWidths, WithCustomValueBinder, WithHeadings, WithStyles
{
    /** @param Collection<int, array<string,mixed>> $rows */
    public function __construct(private Collection $rows) {}

    /** @return array<int, string> */
    public function headings(): array
    {
        return ['Student ID', 'Name', 'Programme', 'Department', 'Expiry date', 'Last appeal outcome', 'Exported on'];
    }

    public function collection(): Collection
    {
        return $this->rows->map(fn (array $r) => [
            (string) $r['studentId'], $r['name'], (string) $r['programme'], $r['department']?->name ?? '', $r['expiry']->format('j M Y'),
            \App\Modules\Chloe\Support\CandidacyDismissalList::OUTCOMES[$r['outcome']] ?? '', '',
        ]);
    }

    /** @return array<string,int> */
    public function columnWidths(): array
    {
        return ['A' => 14, 'B' => 30, 'C' => 52, 'D' => 36, 'E' => 14, 'F' => 22, 'G' => 14];
    }

    /** @return array<string, array<string, mixed>> */
    public function styles(Worksheet $sheet): array
    {
        return ['1' => ['font' => ['bold' => true]]];
    }
}
