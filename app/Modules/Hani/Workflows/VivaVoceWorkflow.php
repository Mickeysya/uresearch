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
 * Step 1 of the build: the notice reaches the Academic Executive, who
 * acknowledges it and then contacts the supervisor about examiners. The
 * later steps (softbound thesis, viva date, report, result category) will be
 * appended as further stages or detail columns, never inserted before this
 * one, since stage keys are stored in rows.
 *
 * Examiner Nomination is NOT a stage here. It stays its own chain
 * (ExaminerNominationWorkflow) and this module only reads whether it has
 * finished. The DAC / FSMC-AC approvals in the AE's guideline are that
 * chain, not something recorded twice. Re-viva likewise stays CGS-logged.
 */
class VivaVoceWorkflow implements WorkflowModule
{
    public function key(): string
    {
        return 'viva_voce';
    }

    public function label(): string
    {
        return 'Viva Voce';
    }

    public function stages(?Application $application = null): array
    {
        return [
            new Stage(
                key: 'notice_received',
                label: 'Academic Executive',
                role: Role::ACADEMIC_EXEC,
                decision: 'acknowledged',
                queueTitle: 'Thesis Notices Received',
            ),
        ];
    }

    public function summary(Application $application): string
    {
        $detail = VivaDetail::where('application_id', $application->id)->first();

        return $detail ? 'Notice of Thesis Submission: '.$detail->thesis_title : 'Notice of Thesis Submission';
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
