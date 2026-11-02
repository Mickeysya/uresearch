<?php

namespace App\Modules\Nureen\Workflows\Concerns;

use App\Modules\Core\Models\Application;
use App\Modules\Nureen\Support\ClientCharter;
use Carbon\CarbonInterface;

/**
 * Core\Contracts\SuppliesDecisionDates for the three charter modules (GA
 * Extension, Supervision, Certification): the same expected decision date
 * the student's Expected Decision Dates page shows, from Support\
 * ClientCharter. Null when the application is not pending, or when its
 * current stage or one still ahead has no charter figure.
 */
trait ExpectsDecisionByCharter
{
    public function expectedDecisionDate(Application $application): ?CarbonInterface
    {
        return app(ClientCharter::class)->expectedDecision($application)['expected_date'] ?? null;
    }

    /**
     * Core\Contracts\SuppliesOverdueStatus: past its charter due date --
     * only where the current stage HAS a charter figure, exactly as the
     * Expected Decision Dates page judges it.
     */
    public function decisionIsOverdue(Application $application): bool
    {
        $clock = app(ClientCharter::class)->stageClock($application);

        return $clock !== null && $clock['on_charter'] && $clock['overdue'];
    }
}
