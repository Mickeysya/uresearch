<?php

namespace App\Modules\Hani\Exports;

use App\Modules\Hani\Models\ExaminerNomination;
use App\Modules\Hani\Support\AppointmentLetters;
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
    /**
     * @param  Collection<int, ExaminerNomination>  $nominations
     * @param  array<int, array>  $letters  AppointmentLetters::forPanels()
     * @param  bool  $flagNeeded  fill "Letter Needed For": the Awaiting letters download
     */
    public function __construct(
        protected Collection $nominations,
        protected array $letters = [],
        protected bool $flagNeeded = false,
    ) {}

    /** @return array<int, string> */
    public function headings(): array
    {
        return [
            // "Application No." is examiner_nominations.application_id, the
            // key for reading the seat ids exactly. Jason's import does not
            // match this heading yet (no alias with the full stop); he is
            // adding one on his side, so the heading stays as it is.
            // Columns are only ever ADDED at the end; see README.md,
            // "Examiner Report export layout".
            'Application No.', 'Faculty', 'Department', 'Candidate', 'Matric No', 'Programme',
            'Thesis Title', 'Supervisor',
            'Internal Main', 'Internal Main Dept', 'Internal Backup',
            'External Main', 'External Main Institution', 'External Backup',
            'Stage', 'Status', 'Submitted',
            'Internal Main Email', 'External Main Email',
            'Internal Main Letter Status', 'Internal Backup Letter Status',
            'External Main Letter Status', 'External Backup Letter Status',
            'Letter Needed For', 'Replaced Examiners',
            'DAC Endorsed',
        ];
    }

    /** @return array<int, array<int, string|int|null>> */
    public function array(): array
    {
        return $this->nominations->map(function (ExaminerNomination $nomination) {
            $application = $nomination->application;
            $student = $application?->student;
            $letters = $this->letters[$nomination->id] ?? null;

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
                $nomination->internalMain?->email,
                $nomination->externalMain?->email,
                $letters['seats']['internal_main_id'] ?? null,
                $letters['seats']['internal_backup_id'] ?? null,
                $letters['seats']['external_main_id'] ?? null,
                $letters['seats']['external_backup_id'] ?? null,
                $this->flagNeeded ? $this->needed($letters) : null,
                $this->replaced($letters),
                \App\Modules\Hani\Models\ExaminerDacRecord::endorsementFor((int) $application?->id)?->label(),
            ];
        })->values()->all();
    }

    protected function needed(?array $letters): ?string
    {
        return collect($letters['needed'] ?? [])
            ->map(fn (?string $name, string $seat) => AppointmentLetters::SEATS[$seat].': '.$name)
            ->join('; ') ?: null;
    }

    protected function replaced(?array $letters): ?string
    {
        return collect($letters['replaced'] ?? [])
            ->map(fn (array $former) => AppointmentLetters::SEATS[$former['seat']].': '.$former['name'].', '.$former['status'])
            ->join('; ') ?: null;
    }
}
