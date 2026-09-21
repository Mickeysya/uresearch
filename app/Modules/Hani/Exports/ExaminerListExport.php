<?php

namespace App\Modules\Hani\Exports;

use App\Modules\Hani\Models\ExaminerNomination;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * The examiner list as a sheet: one row per candidate, four examiner columns.
 *
 * One class for both formats. Maatwebsite writes .xlsx or .csv from the same
 * FromArray source (see ExaminerNominationController::export()), so the file
 * the Academic Executive hands to CGS and the file CGS hands to the Senior
 * Director are the same shape, whichever button was pressed.
 *
 * It is a FLAT sheet on purpose. This is the artefact that leaves the system
 * -- it gets mailed, opened in Excel, sorted and pasted into a report -- so
 * every cell a reader needs is on the row, repeated where it has to be,
 * rather than implied by a grouping the CSV cannot carry.
 */
class ExaminerListExport implements FromArray, WithHeadings
{
    /** @param  Collection<int, ExaminerNomination>  $nominations */
    public function __construct(protected Collection $nominations) {}

    /** @return array<int, string> */
    public function headings(): array
    {
        return [
            'Application', 'Faculty', 'Department', 'Candidate', 'Matric No', 'Programme',
            'Thesis Title', 'Supervisor',
            'Internal Main', 'Internal Main Dept', 'Internal Backup',
            'External Main', 'External Main Institution', 'External Backup',
            'Stage', 'Status', 'Submitted',
        ];
    }

    /** @return array<int, array<int, string|int|null>> */
    public function array(): array
    {
        return $this->nominations->map(function (ExaminerNomination $nomination) {
            $application = $nomination->application;
            $student = $application?->student;

            return [
                $application?->id,
                $student?->faculty,
                $student?->department,
                $student?->name,
                $student?->matric_no,
                $student?->programme,
                $nomination->thesis_title,
                $application?->submittedBy?->name,
                $nomination->internalMain?->name,
                $nomination->internalMain?->department,
                $nomination->internalBackup?->name,
                $nomination->externalMain?->name,
                $nomination->externalMain?->institution,
                $nomination->externalBackup?->name,
                // The stage it is sitting on, not the stage it passed: this
                // file is read to answer "where has this got to?".
                $application?->currentStage()?->label ?? '—',
                ucfirst((string) $application?->status),
                $application?->submitted_at?->format('Y-m-d'),
            ];
        })->values()->all();
    }
}
