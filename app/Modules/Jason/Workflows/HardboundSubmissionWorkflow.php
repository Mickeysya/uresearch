<?php

namespace App\Modules\Jason\Workflows;

use App\Modules\Core\Contracts\ProvidesLinks;
use App\Modules\Core\Contracts\WorkflowModule;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
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
class HardboundSubmissionWorkflow implements WorkflowModule, ProvidesLinks
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

    /**
     * Shown on the student's tracking page under the status badge. The badge
     * says "rejected" for both endings, so this is where the student learns
     * which one they got and what they can do about it.
     */
    public function summary(Application $application): string
    {
        $detail = HardboundSubmissionDetail::where('application_id', $application->id)->first();

        if (! $detail) {
            return 'Hardbound thesis submission';
        }

        $summary = $detail->thesis_title;

        if ($detail->isResubmission()) {
            $summary .= ' (resubmission of #'.$detail->resubmission_of_id.')';
        }

        if ($application->status === Application::STATUS_REJECTED) {
            $replaced = HardboundSubmissionDetail::where('resubmission_of_id', $application->id)->exists();

            $summary .= match (true) {
                $replaced => ' — replaced by a resubmission',
                $application->current_stage === 'cgs_review' => ' — returned for correction; resubmit from the sidebar',
                HardboundSubmissionDetail::resubmittableFor($application->student_id)->whereKey($application->id)->exists()
                    => ' — appeal upheld; resubmit from the sidebar',
                default => ' — rejected; an appeal may be filed from the sidebar',
            };
        }

        return $summary;
    }

    public function createRoute(): ?string
    {
        return 'hardbound.create';
    }

    public function queueRoute(): string
    {
        return 'hardbound.queue';
    }

    /**
     * One "Resubmit" link per submission the student is currently allowed to
     * replace. The tracking page is Core's and takes no module actions, so
     * the sidebar is where a module puts a per-application action.
     */
    public function links(User $user): array
    {
        if ($user->role !== Role::STUDENT) {
            return [];
        }

        return HardboundSubmissionDetail::resubmittableFor($user->id)
            ->get(['id'])
            ->map(fn (Application $application) => [
                'label' => "Resubmit Hardbound #{$application->id}",
                'route' => 'hardbound.resubmit.form',
                'params' => ['application' => $application->id],
            ])
            ->all();
    }
}
