<?php

namespace App\Modules\Jason\Workflows;

use App\Modules\Core\Contracts\WorkflowModule;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Support\Role;
use App\Modules\Core\Support\Stage;
use App\Modules\Jason\Models\HardboundSubmissionDetail;

/**
 * Final hardbound thesis submission.
 *
 * The candidate submits the bound thesis and its clearance forms, the
 * Non-Executive CGS checks the pack is complete, and the Senior Executive
 * CGS gives final approval, which issues the acknowledgement receipt.
 *
 * The spec asks for a third outcome at the review stage -- "return to the
 * student, application stays open" -- which the engine does not have; it
 * knows approve (advance) and reject (terminate). Rather than change Core,
 * a return is a rejection at `cgs_review`, and the student's resubmission is
 * a fresh application carrying `resubmission_of_id` back to it. That keeps
 * the returned application, its reviewer and its remarks intact on the
 * record instead of overwriting them on each attempt, and it is the option
 * TODO.md offers as the alternative to an engine change. Which stage the
 * rejection happened at is what separates the two endings: returned at
 * `cgs_review` and the student can resubmit, rejected at `cgs_approve` and
 * their only route is Appeal Hardbound Submission.
 */
class HardboundSubmissionWorkflow implements WorkflowModule
{
    public function key(): string
    {
        return 'hardbound_submission';
    }

    public function label(): string
    {
        return 'Hardbound Submission';
    }

    public function stages(?Application $application = null): array
    {
        return [
            new Stage(
                key: 'cgs_review',
                label: 'Non-Executive CGS',
                role: Role::NON_EXEC_CGS,
                decision: 'reviewed',
                queueTitle: 'Submissions to Review',
            ),
            new Stage(
                key: 'cgs_approve',
                label: 'Senior Executive CGS',
                role: Role::SENIOR_EXEC_CGS,
                decision: 'approved',
                queueTitle: 'Pending Final Approval',
            ),
        ];
    }

    public function summary(Application $application): string
    {
        $detail = HardboundSubmissionDetail::where('application_id', $application->id)->first();

        if (! $detail) {
            return 'Hardbound thesis submission';
        }

        return $detail->thesis_title.($detail->isResubmission()
            ? ' (resubmission of #'.$detail->resubmission_of_id.')'
            : '');
    }

    public function createRoute(): ?string
    {
        return 'hardbound.create';
    }

    public function queueRoute(): string
    {
        return 'hardbound.queue';
    }
}
