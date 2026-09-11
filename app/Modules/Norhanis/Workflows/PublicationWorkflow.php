<?php

namespace App\Modules\Norhanis\Workflows;

use App\Modules\Core\Contracts\WorkflowModule;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Support\Role;
use App\Modules\Core\Support\Stage;
use App\Modules\Norhanis\Models\PublicationDetail;

/**
 * Conference/journal funding applications and Letters of Undertaking.
 *
 * One fixed chain, no conditional routing: Supervisor -> Chair -> Non-Exec
 * CGS -> Senior Director CGS. Senior Director CGS has the final say.
 */
class PublicationWorkflow implements WorkflowModule
{
    public function key(): string
    {
        return 'publication';
    }

    public function label(): string
    {
        return 'Publication';
    }

    public function stages(?Application $application = null): array
    {
        return [
            new Stage('supervisor', 'Lecturer/Supervisor', Role::SUPERVISOR, 'endorsed'),
            new Stage('chair', 'Chair of Department', Role::CHAIR, 'endorsed'),
            new Stage('cgs_review', 'Non-Executive CGS', Role::NON_EXEC_CGS, 'reviewed'),
            new Stage('senior_director', 'Senior Director CGS', Role::SENIOR_DIRECTOR_CGS, 'approved'),
        ];
    }

    public function summary(Application $application): string
    {
        $detail = PublicationDetail::where('application_id', $application->id)->first();

        if (! $detail) {
            return 'Publication application';
        }

        return $detail->publication_title.' — '.$detail->conference_or_journal_name;
    }

    public function createRoute(): ?string
    {
        return 'publication.create';
    }

    public function queueRoute(): string
    {
        return 'publication.queue';
    }
}
