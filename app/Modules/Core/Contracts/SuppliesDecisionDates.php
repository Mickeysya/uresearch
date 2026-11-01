<?php

namespace App\Modules\Core\Contracts;

use App\Modules\Core\Models\Application;
use Carbon\CarbonInterface;

/**
 * Optional companion to WorkflowModule, for modules that promise students a
 * decision date (a service charter).
 *
 * The student dashboard's "Active Applications" card shows the nearest
 * expected decision date across the student's pending applications. Core
 * has no charter of its own, so a module that keeps one hands the date over
 * and Core only picks the earliest. Discovered like ProvidesLinks: implement
 * it on the workflow class; no new wiring.
 */
interface SuppliesDecisionDates
{
    /** When this pending application should be decided, or null if no date is promised. */
    public function expectedDecisionDate(Application $application): ?CarbonInterface;
}
