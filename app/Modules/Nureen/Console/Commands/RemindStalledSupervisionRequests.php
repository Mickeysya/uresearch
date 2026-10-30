<?php

namespace App\Modules\Nureen\Console\Commands;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Core\Support\Role;
use App\Modules\Nureen\Models\SupervisionDetail;
use App\Modules\Nureen\Notifications\SupervisionEscalatedToCgs;
use App\Modules\Nureen\Notifications\SupervisionRequestStalled;
use Illuminate\Console\Command;

/**
 * Escalates a supervision request that has sat on one stage for too long.
 *
 * Scheduled from routes/console.php. Each detail row remembers the last time
 * it was reminded (`reminded_at`), so a request only gets nagged again once
 * it has stalled for another full period, not on every run of the command.
 *
 * Three stages, two different kinds of recipient: 'supervisor' is addressed
 * to the one named person the student picked (see SupervisionController::
 * queue()); 'chair' is addressed to whoever holds the Chair of Department
 * role for the student's own department, same department-scoping
 * ApprovesApplications::queueFor() and WorkflowEngine::decide() already use
 * (see Role::isDepartmentScoped()); 'cgs_review' -- only reachable in
 * 'manual' mode -- goes to every Non-Executive CGS user, the same as before
 * the Chair stage existed.
 *
 * Repeated reminders at either of the first two stages with still no
 * decision also loop CGS Staff in, once `reminder_count` reaches
 * config('nureen.reminders.supervision.escalate_after_reminders') --
 * informational, not a decision request; see SupervisionEscalatedToCgs.
 * `reminded_stage_key` is what keeps that count honest across a move: a
 * count run up at the supervisor's stage must not read as "escalated"
 * again the moment the request has since moved to chair or cgs_review.
 */
class RemindStalledSupervisionRequests extends Command
{
    protected $signature = 'supervision:remind-stalled {--days= : Override config(\'nureen.reminders.supervision.stalled_days\')}';

    protected $description = 'Email the pending approver for any supervision request stalled at its current stage';

    public function handle(WorkflowEngine $engine): int
    {
        $days = (int) ($this->option('days') ?: config('nureen.reminders.supervision.stalled_days'));
        $escalateAfter = (int) config('nureen.reminders.supervision.escalate_after_reminders');
        $cutoff = now()->subDays($days);

        $stalled = Application::query()
            ->where('module_type', 'supervision')
            ->where('status', Application::STATUS_PENDING)
            ->where('updated_at', '<=', $cutoff)
            ->get();

        $sent = 0;

        foreach ($stalled as $application) {
            $detail = SupervisionDetail::where('application_id', $application->id)->first();
            $stage = $engine->currentStage($application);

            if (! $detail || ! $stage) {
                continue;
            }

            // Already reminded since this stall began -- current_stage only
            // changes when the application moves, so a reminder newer than
            // updated_at means nobody has acted since, and nagging again is
            // premature until another full period has passed.
            if ($detail->reminded_at && $detail->reminded_at->gte($cutoff)) {
                continue;
            }

            $recipients = match ($stage->key) {
                'supervisor' => User::where('id', $detail->requested_supervisor_id)->get(),
                'chair' => User::where('role', Role::CHAIR)
                    ->where('department', $application->student?->department)
                    ->get(),
                default => User::where('role', Role::NON_EXEC_CGS)->get(),
            };

            $daysStalled = (int) $application->updated_at->diffInDays(now());

            foreach ($recipients as $recipient) {
                $recipient->notify(new SupervisionRequestStalled($application, $stage, $daysStalled));
                $sent++;
            }

            // A count from a stage this request has since left is stale,
            // not "still stalled here" -- reset rather than carry it
            // forward when the stage does not match what was last stored.
            $reminderCount = $detail->reminded_stage_key === $stage->key ? $detail->reminder_count + 1 : 1;

            $detail->update([
                'reminded_at' => now(),
                'reminder_count' => $reminderCount,
                'reminded_stage_key' => $stage->key,
            ]);

            if (in_array($stage->key, ['supervisor', 'chair'], true) && $reminderCount >= $escalateAfter) {
                $stalledParty = $stage->key === 'supervisor'
                    ? (User::find($detail->requested_supervisor_id)?->name ?? 'the supervisor')
                    : 'the Chair of Department';

                foreach (User::where('role', Role::NON_EXEC_CGS)->get() as $cgsStaff) {
                    $cgsStaff->notify(new SupervisionEscalatedToCgs($application, $stalledParty, $reminderCount));
                    $sent++;
                }
            }
        }

        $this->info("Sent {$sent} stall reminder(s) for supervision requests.");

        return self::SUCCESS;
    }
}
