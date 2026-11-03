<?php

namespace App\Modules\Chloe\Exports;

use App\Modules\Chloe\Models\Workstation;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * A read-only copy of exactly what the CGS landing page's results grid is
 * showing when "Export Results" is clicked — the controller builds the same
 * $rows collection for the screen and for this export, so the two can never
 * disagree. There is no import; changes are only ever made inside the
 * system (see the module README for why).
 */
class WorkstationSeatsExport implements FromCollection, WithHeadings
{
    /** @param Collection<int, array<string, mixed>> $rows */
    public function __construct(private Collection $rows) {}

    /** @return array<int, string> */
    public function headings(): array
    {
        return [
            'Block', 'Room', 'Seat', 'Status', 'Student Name', 'Student ID',
            'Email', 'Programme', 'Room Gender', 'Key Status', 'Key Dates',
        ];
    }

    public function collection(): Collection
    {
        return $this->rows->map(fn (array $row) => [
            $row['block'],
            $row['room_code'],
            $row['seat_code'],
            Workstation::statuses()[$row['status']] ?? ucfirst($row['status']),
            $row['student_name'] ?? '—',
            $row['student_matric'] ?? '—',
            $row['student_email'] ?? '—',
            $row['student_programme'] ?? '—',
            ucfirst($row['room_gender']),
            $row['key_status_label'] ?? 'Not Requested',
            $row['key_dates'] ?? '—',
        ]);
    }
}
