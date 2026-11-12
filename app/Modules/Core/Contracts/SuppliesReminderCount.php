<?php

namespace App\Modules\Core\Contracts;

use App\Modules\Core\Models\Application;

/**
 * OPTIONAL companion to WorkflowModule, for modules that send counted
 * "this is overdue" reminders: how many has this application had at the
 * stage it is sitting on now?
 *
 * Read by Core\Services\CgsAssignment to decide when a CGS stage is
 * escalated (overdue plus config('cgs_assignment.escalate_after_reminders')
 * reminders) and the backup officer may step in. Checked with instanceof; a
 * module without it is escalated on being overdue alone.
 *
 * A separate interface rather than a method on SuppliesOverdueStatus, so
 * adding it breaks nobody who already implements that one.
 */
interface SuppliesReminderCount
{
    /** Reminders sent during the CURRENT visit to the current stage; 0 when none, or not pending. */
    public function remindersAtCurrentStage(Application $application): int;
}
