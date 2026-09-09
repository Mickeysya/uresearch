<?php

namespace App\Modules\Norhanis\Workflows;

use App\Modules\Core\Contracts\WorkflowModule;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Support\Role;
use App\Modules\Core\Support\Stage;
use App\Modules\Norhanis\Models\TravelDetail;

/**
 * Travel requests.
 *
 * Local travel stops at the Chair. International travel continues to CGS for
 * review and the Dean of PGR for final approval.
 *
 * In the legacy app that branch lived inside travel_chair_approval.php, which
 * queried is_international and picked the next stage by hand. Expressing it as
 * a chain instead means the student's stepper shows the correct number of
 * steps from the moment they submit, and nothing has to be kept in sync.
 */
class TravelWorkflow implements WorkflowModule
{
    /** @var array<int, bool> memoised per application, keyed by id */
    protected array $internationalCache = [];

    public function key(): string
    {
        return 'travel';
    }

    public function label(): string
    {
        return 'Travel';
    }

    public function stages(?Application $application = null): array
    {
        $chain = [
            new Stage(
                key: 'supervisor',
                label: 'Lecturer/Supervisor',
                role: Role::SUPERVISOR,
                decision: 'endorsed',
                queueTitle: 'Pending My Endorsement',
            ),
        ];

        // null means "every stage this module can use" -- that is the
        // international chain, which is a superset of the local one.
        if ($application === null || $this->isInternational($application)) {
            $chain[] = new Stage('chair', 'Chair of Department', Role::CHAIR, 'endorsed');
            $chain[] = new Stage('cgs_review', 'Non-Executive CGS', Role::NON_EXEC_CGS, 'reviewed');
            $chain[] = new Stage('dean', 'Dean of PGR', Role::DEAN_PGR, 'approved');
        } else {
            // Local travel: the Chair has the final say.
            $chain[] = new Stage('chair', 'Chair of Department', Role::CHAIR, 'approved');
        }

        return $chain;
    }

    public function summary(Application $application): string
    {
        $detail = $this->detail($application);

        if (! $detail) {
            return 'Travel application';
        }

        return $detail->reason_for_travel.' — '.$detail->destination_address;
    }

    public function createRoute(): ?string
    {
        return 'travel.create';
    }

    public function queueRoute(): string
    {
        return 'travel.queue';
    }

    protected function isInternational(Application $application): bool
    {
        if (! $application->exists) {
            // The registry probes with a blank Application to build the
            // sidebar; the local chain is the right default there.
            return false;
        }

        return $this->internationalCache[$application->id] ??=
            (bool) TravelDetail::where('application_id', $application->id)->value('is_international');
    }

    protected function detail(Application $application): ?TravelDetail
    {
        return TravelDetail::where('application_id', $application->id)->first();
    }
}
