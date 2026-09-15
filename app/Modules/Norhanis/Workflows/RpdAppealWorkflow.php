<?php

namespace App\Modules\Norhanis\Workflows;

use App\Modules\Core\Contracts\ProvidesLinks;
use App\Modules\Core\Contracts\WorkflowModule;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Core\Support\Stage;
use App\Modules\Norhanis\Models\RpdAppealDetail;

/**
 * RPD extension appeal — Supervisor → Chair → Non-Exec CGS → Dean of PGR.
 *
 * Straight from norhanis.md Module 4, path 2. The chain is fixed: unlike
 * Travel, nothing about the appeal changes who sees it, so stages() ignores
 * its argument entirely.
 *
 * The interesting part is not here but in RpdAppealController::decide(), which
 * moves the masterlist deadline on the Dean's approval. This class only says
 * who signs.
 */
class RpdAppealWorkflow implements WorkflowModule, ProvidesLinks
{
    public function key(): string
    {
        return 'rpd_appeal';
    }

    public function label(): string
    {
        return 'RPD Extension Appeal';
    }

    public function stages(?Application $application = null): array
    {
        return [
            new Stage(
                key: 'supervisor',
                label: 'Lecturer/Supervisor',
                role: Role::SUPERVISOR,
                decision: 'endorsed',
                queueTitle: 'RPD Appeals Pending My Endorsement',
            ),
            new Stage('chair', 'Chair of Department', Role::CHAIR, 'endorsed'),
            new Stage('cgs_review', 'Non-Executive CGS', Role::NON_EXEC_CGS, 'reviewed'),
            new Stage('dean', 'Dean of PGR', Role::DEAN_PGR, 'approved'),
        ];
    }

    public function summary(Application $application): string
    {
        $detail = RpdAppealDetail::with('candidacy')->where('application_id', $application->id)->first();

        if (! $detail) {
            return 'RPD extension appeal';
        }

        $to = $detail->new_deadline ?? $detail->deadline_at_filing->copy()->addMonthsNoOverflow($detail->requested_months);

        return $detail->requested_months.'-month extension — '
            .$detail->deadline_at_filing->format('j M Y').' → '.$to->format('j M Y');
    }

    public function createRoute(): ?string
    {
        return 'rpd-appeal.create';
    }

    public function queueRoute(): string
    {
        return 'rpd-appeal.queue';
    }

    /**
     * The masterlist screens the chain cannot infer.
     *
     * A student reaches the appeal form through createRoute() already, but
     * nothing would show them the deadline they are appealing against. CGS
     * owns one stage in this chain, which gets them a queue but not the
     * list the whole module revolves around.
     */
    public function links(User $user): array
    {
        return match ($user->role) {
            Role::STUDENT => [
                ['label' => 'My Candidacy', 'route' => 'candidacies.mine'],
            ],
            Role::NON_EXEC_CGS => [
                ['label' => 'RPD Masterlist', 'route' => 'candidacies.index'],
                ['label' => 'Open a Dismissal', 'route' => 'rpd-dismissal.create'],
            ],
            Role::MANAGER_CGS, Role::SENIOR_DIRECTOR_CGS => [
                ['label' => 'RPD Masterlist', 'route' => 'candidacies.index'],
            ],
            default => [],
        };
    }
}
