<?php

namespace App\Modules\Hani\Exports;

use App\Modules\Hani\Models\ExaminerNomination;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

/** The AE's compiled list for the DAC meeting: one row per nomination, the four seats, COI and clash flags. */
class DacCompilationExport implements FromArray, WithHeadings, ShouldAutoSize
{
    public function __construct(protected Collection $nominations, protected array $flags) {}

    public function headings(): array
    {
        return [
            'No.', 'Application No.', 'Candidate', 'Matric No', 'Programme', 'Thesis Title', 'Supervisor',
            'Internal Main', 'Internal Main Dept/Institution', 'Internal Backup', 'Internal Backup Dept/Institution',
            'External Main', 'External Main Dept/Institution', 'External Backup', 'External Backup Dept/Institution',
            'COI and Clash Flags', 'DAC Decision',
        ];
    }

    public function array(): array
    {
        return $this->nominations->values()->map(function (ExaminerNomination $n, int $i) {
            $student = $n->application?->student;
            $seat = fn ($e) => [$e?->name, $e ? ($e->institution ?: $e->department) : null];
            $flags = collect($this->flags[$n->application_id] ?? [])
                ->flatMap(fn ($list, $examinerId) => collect($list)->map(fn ($f) => strtoupper($f['level']).': '.$f['message']))
                ->implode("\n");

            return array_merge(
                [$i + 1, $n->application_id, $student?->name, $student?->matric_no, $student?->programme, $n->thesis_title,
                    $n->application?->submittedBy?->name ?? $student?->supervisor?->name],
                $seat($n->internalMain), $seat($n->internalBackup), $seat($n->externalMain), $seat($n->externalBackup),
                [$flags ?: 'None', ''],
            );
        })->all();
    }
}
