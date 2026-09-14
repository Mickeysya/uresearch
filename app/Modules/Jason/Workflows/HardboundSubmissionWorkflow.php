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
 * The candidate submits the two completed CGS forms and the Non-Executive
 * CGS checks the pack is complete. That check is the whole chain: approving
 * it issues the acknowledgement receipt. The spec's second stage, a Senior
 * Executive sign-off, was dropped -- a completeness check does not need two
 * signatures, and no senior_exec_cgs account is seeded, so it only stalled
 * every submission for everyone but one machine.
 *
 * The spec asks for a "return to the student, application stays open"
 * outcome, which the engine does not have; it knows approve (advance) and
 * reject (terminate). Rather than change Core, a return is a rejection at
 * `cgs_review`, and the student's resubmission is a fresh application
 * carrying `resubmission_of_id` back to it. That keeps the returned
 * application, its reviewer and its remarks intact on the record instead of
 * overwriting them on each attempt, and it is the option TODO.md offers as
 * the alternative to an engine change. With one stage there is no separate
 * "rejected outright" ending: every rejection is a return, and the student
 * may either resubmit or take it to Appeal Hardbound Submission.
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
                decision: 'approved',
                queueTitle: 'Submissions to Review',
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

            $summary .= $replaced
                ? ' — replaced by a resubmission'
                : ' — returned for correction; resubmit from the Hardbound Submission page, or appeal';
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
     * replace. Core's student sidebar does not currently render module links
     * (its student partial is not passed $extraLinks), so the Hardbound
     * Submission page shows the same list itself; this stays so the links
     * appear the moment the sidebar does render them.
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
