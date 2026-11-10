<?php

namespace App\Modules\Norhanis\Workflows\Concerns;

use App\Modules\Core\Models\Application;
use App\Modules\Norhanis\Support\StageDurations;
use Carbon\CarbonInterface;

/**
 * Core\Contracts\SuppliesDecisionDates and SuppliesOverdueStatus for Travel,
 * Claims and Publication, so Core's View details page, the student
 * dashboard and the Expected Decision Dates page show when to expect an
 * outcome. Nureen's GA Extension, Supervision and Certification do the same
 * from her own charter (Nureen\Workflows\Concerns\ExpectsDecisionByCharter).
 *
 * The date is Support\StageDurations::estimate()'s, the same "Expected final
 * decision" line this module's own pages print, so the two never disagree:
 * CGS's client charter (Config/charter.php), or a stage's own median once it
 * has enough decisions behind it.
 */
trait ExpectsDecisionByCharter
{
    public function expectedDecisionDate(Application $application): ?CarbonInterface
    {
        return app(StageDurations::class)->estimate($application)['expected'] ?? null;
    }

    /** The current stage has taken longer than its charter figure. False where it has none. */
    public function decisionIsOverdue(Application $application): bool
    {
        $current = collect(app(StageDurations::class)->estimate($application)['steps'] ?? [])
            ->firstWhere('state', 'current');

        return (bool) ($current['overdue'] ?? false);
    }
}
