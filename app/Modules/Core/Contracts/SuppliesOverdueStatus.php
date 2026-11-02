<?php

namespace App\Modules\Core\Contracts;

use App\Modules\Core\Models\Application;

/**
 * OPTIONAL companion to WorkflowModule, for modules that promise a time per
 * stage (a service charter): is this pending application's current stage
 * past that promise? Track My Applications then marks the step "Taking
 * longer than usual" with a calm note. Checked with instanceof; a module
 * without it is simply never marked.
 *
 * Kept separate from SuppliesDecisionDates on purpose: adding a method to
 * an existing interface would break anyone already implementing it.
 */
interface SuppliesOverdueStatus
{
    /** True when the current stage has taken longer than promised. False when no promise applies. */
    public function decisionIsOverdue(Application $application): bool;
}
