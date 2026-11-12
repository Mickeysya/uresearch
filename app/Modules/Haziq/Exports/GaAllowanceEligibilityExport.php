<?php

namespace App\Modules\Haziq\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * GA's own monthly download -- deliberately its own file, never merged
 * with AllowanceEligibilityExport (GRA's), confirmed explicitly
 * 2026-11-10: "jangan sekali kan dengan allowance gra satu excel."
 */
class GaAllowanceEligibilityExport implements FromCollection, WithHeadings, WithMapping
{
    /** @param  Collection<int, array{student: \App\Modules\Core\Models\User, application: \App\Modules\Core\Models\Application, amount: float|null, confirmed: bool}>  $rows */
    public function __construct(protected Collection $rows) {}

    public function collection(): Collection
    {
        return $this->rows;
    }

    /** @return array<int, string> */
    public function headings(): array
    {
        return ['Student Name', 'Matric No.', 'Application', 'Allowance (RM)', 'Status'];
    }

    /** @return array<int, string|float> */
    public function map($row): array
    {
        return [
            $row['student']->name,
            $row['student']->matric_no ?? '',
            $row['application']->reference(),
            $row['amount'] ?? '—',
            $row['confirmed'] ? 'Paid' : 'Pending Confirmation',
        ];
    }
}
