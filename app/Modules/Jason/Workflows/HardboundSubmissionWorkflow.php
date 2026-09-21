<?php

namespace App\Modules\Jason\Workflows;

use App\Modules\Core\Contracts\ProvidesDashboardAlerts;
use App\Modules\Core\Contracts\ProvidesLinks;
use App\Modules\Core\Contracts\WorkflowModule;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Core\Support\Stage;
use App\Modules\Jason\Models\HardboundSignature;
use App\Modules\Jason\Models\HardboundSubmissionDetail;

/**
 * Final hardbound thesis submission.
 *
 * The candidate supplies the details for the Confirmation of Correction to
 * Thesis (UTP/CGS/017A) and uploads the Hardbound Thesis Submission form
 * (UTP/CGS/021) they signed themselves. The Confirmation is a document the
 * portal generates and carries through the chain. Its signatories are the
 * form's: the Supervisor, the Internal/External Examiner, and the Chairman
 * of the Viva Voce Examination. The Supervisor and the Chairman (the `chair`
 * role here) sign electronically -- each approval stamps their uploaded
 * signature and the date into their block. Examiners have no login, so the
 * Examiner block is left for a physical signature and official stamp. The
 * Non-Executive CGS then accepts the pack; CGS signs nothing on this form.
 *
 * The spec asks for a "return to the student, application stays open"
 * outcome, which the engine does not have; it knows approve (advance) and
 * reject (terminate). Rather than change Core, a rejection at any stage is
 * a return, and the student's resubmission is a fresh application carrying
 * `resubmission_of_id` back to it. That keeps the returned application, its
 * reviewer and its remarks intact on the record instead of overwriting them
 * on each attempt, and it is the option TODO.md offers as the alternative
 * to an engine change. There is no separate "rejected outright" ending: the
 * student may either resubmit or take it to Appeal Hardbound Submission.
 */
class HardboundSubmissionWorkflow implements WorkflowModule, ProvidesLinks, ProvidesDashboardAlerts
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
                key: 'supervisor',
                label: 'Supervisor',
                role: Role::SUPERVISOR,
                decision: 'confirmed',
                queueTitle: 'Corrections to Confirm',
            ),
            new Stage(
                key: 'chair',
                label: 'Chairman, Viva Voce Examination',
                role: Role::CHAIR,
                decision: 'certified',
                queueTitle: 'Corrections to Certify',
            ),
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
                : ' — returned for correction; resubmit or appeal from the Hardbound Submission page';
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
     * Students: one "Resubmit" link per submission they may currently
     * replace. Core's student sidebar does not currently render module links
     * (its student partial is not passed $extraLinks), so the Hardbound
     * Submission page shows the same list itself; this stays so the links
     * appear the moment the sidebar does render them.
     *
     * Approvers in this chain: "My Signature", where they upload the image
     * that gets stamped onto the Confirmation when they approve.
     */
    public function links(User $user): array
    {
        if ($user->role === Role::STUDENT) {
            return HardboundSubmissionDetail::resubmittableFor($user->id)
                ->get(['id'])
                ->map(fn (Application $application) => [
                    'label' => "Resubmit Hardbound #{$application->id}",
                    'route' => 'hardbound.resubmit.form',
                    'params' => ['application' => $application->id],
                ])
                ->all();
        }

        return $this->signs($user)
            ? [['label' => 'My Signature', 'route' => 'hardbound.signature']]
            : [];
    }

    /** Does this role stamp a signature onto the Confirmation? */
    protected function signs(User $user): bool
    {
        return in_array($user->role, array_map(fn (Stage $s) => $s->role, $this->stages()), true);
    }

    /**
     * Approving without a signature on file is not possible:
     * HardboundSubmissionController::decide() bounces the approver to the
     * upload page. Nothing said so until they tried, which is the whole
     * point of an alert -- a Chair finds out at the moment they were about
     * to clear a queue.
     *
     * Only raised for someone who actually has something to approve. An
     * approver with an empty queue does not need chasing about a signature
     * they have not needed yet.
     */
    public function alerts(User $user): array
    {
        if (! $this->signs($user) || HardboundSignature::forUser($user->id)) {
            return [];
        }

        $waiting = Application::where('module_type', $this->key())
            ->where('status', Application::STATUS_PENDING)
            ->whereIn('current_stage', array_map(
                fn (Stage $s) => $s->key,
                array_filter($this->stages(), fn (Stage $s) => $s->role === $user->role)
            ))
            ->exists();

        if (! $waiting) {
            return [];
        }

        return [[
            'tone' => 'critical',
            'title' => 'No signature on file',
            'body' => 'You cannot approve a Hardbound Submission until you upload one. '
                .'It is stamped onto the Confirmation of Correction with the date you approve.',
            'action' => ['label' => 'Upload my signature', 'route' => 'hardbound.signature'],
        ]];
    }
}
