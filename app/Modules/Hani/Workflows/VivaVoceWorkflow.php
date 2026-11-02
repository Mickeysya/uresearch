<?php

namespace App\Modules\Hani\Workflows;

use App\Modules\Core\Contracts\ProvidesLinks;
use App\Modules\Core\Contracts\SuppliesCalendarEvents;
use App\Modules\Core\Contracts\WorkflowModule;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Core\Support\Stage;
use App\Modules\Hani\Models\VivaDetail;
use Carbon\CarbonInterface;

/**
 * Viva Voce Assessment, opened by the student's Notice of Thesis Submission.
 *
 * This application IS the viva record: the notice reaches the Academic
 * Executive, who acknowledges it and then contacts the supervisor about
 * examiners. Labelled "Notice of Thesis Submission" rather than "Viva Voce"
 * because the engine's acknowledgement email says the application is fully
 * approved, which is true of the notice and not of the viva. The key stays
 * viva_voce; labels are display-only.
 *
 * What follows hangs off this record rather than being stages on it:
 *   - the softbound thesis is its own application (VivaSoftboundWorkflow),
 *     because a stage cannot wait for the student to upload something
 *   - Examiner Nomination stays its own chain (ExaminerNominationWorkflow),
 *     only ever read here; the DAC / FSMC-AC approvals in the AE's guideline
 *     are that chain, not something recorded twice
 *   - Re-viva stays CGS-logged
 * See VivaProgress for how the three are read together.
 *
 * The viva date itself is set off-chain by the AE (VivaScheduleController):
 * it changes no status and can be moved.
 */
class VivaVoceWorkflow implements WorkflowModule, ProvidesLinks, SuppliesCalendarEvents
{
    public function key(): string
    {
        return 'viva_voce';
    }

    public function label(): string
    {
        return 'Notice of Thesis Submission';
    }

    public function stages(?Application $application = null): array
    {
        return [
            new Stage(
                key: 'notice_received',
                label: 'Academic Executive',
                role: Role::ACADEMIC_EXEC,
                decision: 'acknowledged',
                queueTitle: 'Notices Received',
            ),
        ];
    }

    public function summary(Application $application): string
    {
        $detail = VivaDetail::where('application_id', $application->id)->first();

        return $detail ? $detail->thesis_title : 'Notice of Thesis Submission';
    }

    public function createRoute(): ?string
    {
        // The checklist, not the notice: the notice route sends anyone who
        // has not finished the checklist back to it anyway.
        return 'viva.eligibility';
    }

    public function queueRoute(): string
    {
        return 'viva.queue';
    }

    /**
     * Two screens no stage produces: the AE's scheduling list sits after the
     * notice chain has finished, and the supervisor owns no stage at all.
     */
    public function links(User $user): array
    {
        return match ($user->role) {
            Role::ACADEMIC_EXEC => [
                ['label' => 'Viva Scheduling', 'route' => 'viva.schedule.index'],
            ],
            Role::SUPERVISOR => [
                ['label' => "Students' Viva Progress", 'route' => 'viva.supervisees'],
            ],
            default => [],
        };
    }

    /**
     * Viva dates: the student's own, a supervisor's candidates', and an AE's
     * department's. Scoped here, since Core cannot know who may see what.
     */
    public function calendarEvents(User $user, CarbonInterface $from, CarbonInterface $to): array
    {
        if (! in_array($user->role, [Role::STUDENT, Role::SUPERVISOR, Role::ACADEMIC_EXEC], true)) {
            return [];
        }

        $notices = Application::query()
            ->where('module_type', $this->key())
            ->where('status', Application::STATUS_APPROVED);

        match ($user->role) {
            Role::STUDENT => $notices->where('student_id', $user->id),
            Role::SUPERVISOR => $notices->whereIn('student_id', $user->supervisees()->select('id')),
            Role::ACADEMIC_EXEC => $notices->whereHas('student', fn ($q) => $q->where('department', $user->department)),
        };

        $details = VivaDetail::with('application.student')
            ->whereIn('application_id', $notices->select('id'))
            ->whereBetween('viva_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->get();

        return $details->map(fn (VivaDetail $detail) => [
            'date' => $detail->viva_at,
            'title' => $user->role === Role::STUDENT ? 'Your viva voce' : 'Viva: '.$detail->application->student?->name,
            'tone' => 'critical',
            'meta' => $detail->viva_at->format('g:i a').', '.$detail->venue,
            'url' => route('applications.show', $detail->application_id),
        ])->all();
    }
}
