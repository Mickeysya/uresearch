<?php

namespace App\Modules\Haziq\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * The monthly Allowance Eligibility download — Admin through Senior
 * Director only (see AllowanceController::export()). Takes the same
 * computed rows the on-screen table shows (student, amount, display
 * status), already filtered to active candidatures only — a terminated
 * student's row never reaches this class at all, confirmed explicitly
 * 2026-10-23.
 */
class AllowanceEligibilityExport implements FromCollection, WithHeadings, WithMapping
{
    /** @param  Collection<int, array{student: \App\Modules\Core\Models\User, application: \App\Modules\Core\Models\Application, amount: float|null, displayStatus: string, candidatureStatus: string}>  $rows */
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
            ucwords(str_replace('_', ' ', $row['displayStatus'])),
        ];
    }
}
