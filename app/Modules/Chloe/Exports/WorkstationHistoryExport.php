<?php

namespace App\Modules\Chloe\Exports;

use App\Modules\Chloe\Models\WorkstationHistory;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * A read-only copy of the full, append-only History table — every row,
 * newest first, exactly as WorkstationHistory holds it. Never a partial
 * export of what the landing page happens to be filtering: History has no
 * search/filter UI of its own (STEP 3), so "Export History" always means
 * the whole table.
 */
class WorkstationHistoryExport implements FromCollection, WithHeadings
{
    /** @return array<int, string> */
    public function headings(): array
    {
        return [
            'Date/Time', 'Block', 'Room', 'Seat', 'Student Name', 'Student ID', 'Student Email', 'Programme',
            'Actor', 'Action', 'Reason', 'Remark', 'Before', 'After',
        ];
    }

    /**
     * PART 3 #6/#7: one row per entry, exactly as before -- only how each
     * cell reads has changed. actorLabel()/actionLabel()/beforeLabel()/
     * afterLabel() turn internal codes into what a row already said when
     * read on screen; remarkForDisplay() hides the one seed-era remark
     * that carried no real information, without touching what is stored.
     */
    public function collection(): Collection
    {
        return WorkstationHistory::orderByDesc('created_at')->get()->map(fn (WorkstationHistory $h) => [
            $h->created_at->timezone('Asia/Kuala_Lumpur')->format('Y-m-d H:i:s'),
            $h->block,
            $h->room_code,
            $h->seat_number,
            $h->student_name ?? '—',
            $h->student_matric_no ?? '—',
            $h->student_email ?? '—',
            $h->student_programme ?? '—',
            $h->actorLabel(),
            $h->actionLabel(),
            $h->reason ?? '—',
            $h->remarkForDisplay() ?? '—',
            $h->beforeLabel() ?? '—',
            $h->afterLabel() ?? '—',
        ]);
    }
}
