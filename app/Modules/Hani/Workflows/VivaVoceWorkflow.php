<?php

namespace App\Modules\Hani\Workflows;

use App\Modules\Core\Contracts\WorkflowModule;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Support\Role;
use App\Modules\Core\Support\Stage;
use App\Modules\Hani\Models\VivaDetail;

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
 */
class VivaVoceWorkflow implements WorkflowModule
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
}
