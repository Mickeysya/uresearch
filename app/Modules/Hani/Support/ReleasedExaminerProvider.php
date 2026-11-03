<?php

namespace App\Modules\Hani\Support;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApprovalHistory;
use App\Modules\Hani\Models\Examiner;
use App\Modules\Hani\Models\ExaminerNomination;

/**
 * The released examiner lists, for Jason's appointment-letter module.
 *
 * Today Puan Waheeda emails Encik Syahmi an Excel file of the final list and
 * he retypes it into the letters. This hands the same list over in code:
 * released nominations only, each panel as it stands NOW (after any decline
 * and backup promotion -- PanelSeats keeps the seat columns current).
 *
 * The methods are those of the Core contract proposed in SHARED_CHANGES.md
 * (SuppliesReleasedExaminers). This class does not declare the interface,
 * so it loads whether or not Core has it; ReleasedExaminerBinding does, and
 * Hani\ModuleProvider binds that only when the interface exists. Never
 * imported by anyone else's module.
 */
class ReleasedExaminerProvider
{
    /** @return array<int, array> every released panel, newest first, optionally only those released since a moment */
    public function releasedPanels(?\DateTimeInterface $since = null): array
    {
        $released = $this->releasedAt();

        return $this->query()
            ->get()
            ->map(fn (ExaminerNomination $n) => $this->panel($n, $released[$n->application_id] ?? null))
            ->filter(fn (array $panel) => $since === null || ($panel['released_at'] && $panel['released_at'] >= $since))
            ->sortByDesc('released_at')
            ->values()
            ->all();
    }

    /** The candidate's current released panel, or null if none is released. */
    public function panelForStudent(int $studentId): ?array
    {
        $nomination = $this->query()
            ->whereHas('application', fn ($q) => $q->where('student_id', $studentId))
            ->latest('id')
            ->first();

        return $nomination ? $this->panel($nomination, $this->releasedAt($nomination->application_id)[$nomination->application_id] ?? null) : null;
    }

    protected function query()
    {
        return ExaminerNomination::with(array_merge(ExaminerNomination::slotRelations(), ['application.student']))
            ->whereHas('application', fn ($q) => $q->where('module_type', 'examiner_nomination')
                ->where('status', Application::STATUS_APPROVED));
    }

    /**
     * When each list was released: the cgs_release row in approval_history.
     *
     * @return array<int, \DateTimeInterface>
     */
    protected function releasedAt(?int $applicationId = null): array
    {
        return ApprovalHistory::query()
            ->where('stage_key', 'cgs_release')
            ->where('decision', 'released')
            ->when($applicationId, fn ($q) => $q->where('application_id', $applicationId))
            ->get(['application_id', 'created_at'])
            ->mapWithKeys(fn ($row) => [$row->application_id => $row->created_at])
            ->all();
    }

    protected function panel(ExaminerNomination $nomination, ?\DateTimeInterface $releasedAt): array
    {
        $student = $nomination->application?->student;
        $supervisor = $student?->supervisor_id ? \App\Modules\Core\Models\User::find($student->supervisor_id) : null;

        return [
            'nomination_id' => $nomination->id,
            'application_id' => $nomination->application_id,
            'released_at' => $releasedAt ?? $nomination->application?->updated_at,
            'student' => [
                'id' => $student?->id,
                'name' => $student?->name,
                'matric_id' => $student?->matric_no,
                'programme' => $student?->programme,
                'degree' => $this->degree($student?->programme),
                'department' => $student?->department,
            ],
            'supervisor' => ['name' => $supervisor?->name, 'email' => $supervisor?->email],
            'thesis_title' => $nomination->thesis_title,
            'examiners' => [
                'internal_main' => $this->internal($nomination->internalMain),
                'internal_backup' => $this->internal($nomination->internalBackup),
                'external_main' => $this->external($nomination->externalMain),
                'external_backup' => $this->external($nomination->externalBackup),
            ],
        ];
    }

    protected function internal(?Examiner $e): ?array
    {
        return $e ? ['id' => $e->id, 'name' => $e->name, 'email' => $e->usableEmail(), 'email_problem' => $e->emailProblem(), 'department' => $e->department, 'faculty' => $e->faculty] : null;
    }

    protected function external(?Examiner $e): ?array
    {
        return $e ? ['id' => $e->id, 'name' => $e->name, 'email' => $e->usableEmail(), 'email_problem' => $e->emailProblem(), 'institution' => $e->institution, 'department' => $e->department] : null;
    }

    /** "MSc Full-Time" -> "MSc". */
    protected function degree(?string $programme): ?string
    {
        return preg_match('/\b(MSc|MPhil|PhD|DEng|Master|Doctor)\w*/i', (string) $programme, $m) ? $m[0] : null;
    }
}
