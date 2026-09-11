<?php

namespace App\Modules\Nureen\Console\Commands;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Core\Support\Role;
use App\Modules\Nureen\Models\SupervisionDetail;
use App\Modules\Nureen\Notifications\SupervisionRequestStalled;
use Illuminate\Console\Command;

/**
 * Escalates a supervision request that has sat on one stage for too long.
 *
 * Scheduled from routes/console.php. Each detail row remembers the last time
 * it was reminded (`reminded_at`), so a request only gets nagged again once
 * it has stalled for another full period, not on every run of the command.
 */
class RemindStalledSupervisionRequests extends Command
{
    protected $signature = 'supervision:remind-stalled {--days=3 : How many days without a decision counts as stalled}';

    protected $description = 'Email the pending approver for any supervision request stalled at its current stage';

    public function handle(WorkflowEngine $engine): int
    {
        $days = (int) $this->option('days');
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

            $recipients = $stage->key === 'supervisor'
                ? User::where('id', $detail->requested_supervisor_id)->get()
                : User::where('role', Role::NON_EXEC_CGS)->get();

            $daysStalled = (int) $application->updated_at->diffInDays(now());

            foreach ($recipients as $recipient) {
                $recipient->notify(new SupervisionRequestStalled($application, $stage, $daysStalled));
                $sent++;
            }

            $detail->update(['reminded_at' => now()]);
        }

        $this->info("Sent {$sent} stall reminder(s) for supervision requests.");

        return self::SUCCESS;
    }
}
