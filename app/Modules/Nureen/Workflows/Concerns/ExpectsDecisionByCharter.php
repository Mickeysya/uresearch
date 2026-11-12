<?php

namespace App\Modules\Nureen\Workflows\Concerns;

use App\Modules\Core\Models\Application;
use App\Modules\Nureen\Models\ApplicationReminder;
use App\Modules\Nureen\Models\SupervisionDetail;
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

    /**
     * Core\Contracts\SuppliesReminderCount: the stall reminders this
     * application has had during its CURRENT visit to its current stage --
     * the count the reminder commands themselves carry on or restart, read
     * with the same remindedDuringThisStage() rule. Core's CgsAssignment
     * uses it to decide when a CGS stage is escalated to the backup.
     *
     * Supervision keeps its count on supervision_details (it predates the
     * shared table); GA Extension and Certification on application_reminders.
     */
    public function remindersAtCurrentStage(Application $application): int
    {
        $charter = app(ClientCharter::class);
        $clock = $charter->stageClock($application);

        if (! $clock) {
            return 0;
        }

        if ($application->module_type === 'supervision') {
            $row = SupervisionDetail::where('application_id', $application->id)->first();
            [$count, $at, $key] = [$row?->reminder_count, $row?->reminded_at, $row?->reminded_stage_key];
        } else {
            $row = ApplicationReminder::where('application_id', $application->id)->first();
            [$count, $at, $key] = [$row?->reminder_count, $row?->last_reminded_at, $row?->reminded_stage_key];
        }

        return $charter->remindedDuringThisStage($clock, $at, $key) ? (int) $count : 0;
    }
}
