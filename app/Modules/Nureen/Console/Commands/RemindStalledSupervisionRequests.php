<?php

namespace App\Modules\Nureen\Console\Commands;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Nureen\Models\SupervisionDetail;
use App\Modules\Nureen\Notifications\SupervisionEscalatedToCgs;
use App\Modules\Nureen\Notifications\SupervisionRequestStalled;
use App\Modules\Nureen\Support\ClientCharter;
use Illuminate\Console\Command;

/**
 * Escalates a supervision request that has sat on one stage for too long.
 *
 * Scheduled from routes/console.php. When a stage is "stalled" is the
 * Client Charter's call -- see Support\ClientCharter::dueForReminder(): the
 * first working day the stage is past its charter (3 working days for the
 * supervisor and the Chair, 0 for cgs_review), then once per further full
 * period. Each detail row remembers the last time it was reminded
 * (`reminded_at`), which is what that repeat rule reads.
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
    protected $signature = 'supervision:remind-stalled {--days= : Remind after this many working days at any stage, instead of each stage\'s Client Charter}';

    protected $description = 'Email the pending approver for any supervision request stalled at its current stage';

    public function handle(ClientCharter $charter): int
    {
        $days = $this->option('days') ? (int) $this->option('days') : null;
        $escalateAfter = (int) config('nureen.reminders.supervision.escalate_after_reminders');

        $pending = Application::query()
            ->where('module_type', 'supervision')
            ->where('status', Application::STATUS_PENDING)
            ->with('history')
            ->get();

        $sent = 0;

        foreach ($pending as $application) {
            $detail = SupervisionDetail::where('application_id', $application->id)->first();
            $clock = $charter->stageClock($application, $days);

            if (! $detail || ! $clock) {
                continue;
            }

            if (! $charter->dueForReminder($application, $detail->reminded_at, $detail->reminded_stage_key, $days)) {
                continue;
            }

            $stage = $clock['stage'];

            $recipients = match ($stage->key) {
                'supervisor' => User::where('id', $detail->requested_supervisor_id)->get(),
                'chair' => User::where('role', Role::CHAIR)
                    ->where('department', $application->student?->department)
                    ->get(),
                'dean_pgr' => User::where('role', Role::DEAN_PGR)->get(),
                default => User::where('role', Role::NON_EXEC_CGS)->get(),
            };

            foreach ($recipients as $recipient) {
                $recipient->notify(new SupervisionRequestStalled($application, $stage, $clock['working_days_taken'], $clock['charter_days']));
                $sent++;
            }

            // A count from a stage this request has since left (or from an
            // earlier visit to this one) is stale, not "still stalled here"
            // -- reset rather than carry it forward.
            $reminderCount = $charter->remindedDuringThisStage($clock, $detail->reminded_at, $detail->reminded_stage_key)
                ? $detail->reminder_count + 1
                : 1;

            $detail->update([
                'reminded_at' => now(),
                'reminder_count' => $reminderCount,
                'reminded_stage_key' => $stage->key,
            ]);

            if (in_array($stage->key, ['supervisor', 'chair', 'dean_pgr'], true) && $reminderCount >= $escalateAfter) {
                $stalledParty = match ($stage->key) {
                    'supervisor' => User::find($detail->requested_supervisor_id)?->name ?? 'the supervisor',
                    'dean_pgr' => 'the Dean of PGR',
                    default => 'the Chair of Department',
                };

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
