<?php

namespace App\Modules\Nureen\Console\Commands;

use App\Modules\Core\Models\User;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Nureen\Models\GaCertificationDetail;
use App\Modules\Nureen\Notifications\ApplicationStalled;
use App\Modules\Nureen\Support\StalledApplicationReminder;
use Illuminate\Console\Command;

/**
 * Certification's share of "remind whoever needs to act" -- mostly
 * RemindStalledGaExtension's pattern, bar the module key and config path.
 * The 'cgs_verify' and 'senior_director' stages are held by whoever has the
 * role, so the generic Support\StalledApplicationReminder fits those with
 * no special-casing. The 'supervisor' stage is different: it is addressed
 * to the one named person on GaCertificationDetail::supervisor_id, not to
 * everyone holding the Supervisor role -- same reasoning as
 * RemindStalledSupervisionRequests, handled here rather than in the shared
 * class, which has no way to know a module's own addressing rule.
 */
class RemindStalledCertification extends Command
{
    protected $signature = 'ga-certification:remind-stalled {--days= : Override config(\'nureen.reminders.ga_certification.stalled_days\')}';

    protected $description = 'Email the pending approver for any Certification application stalled at its current stage';

    public function handle(StalledApplicationReminder $reminders, WorkflowEngine $engine): int
    {
        $days = (int) ($this->option('days') ?: config('nureen.reminders.ga_certification.stalled_days'));
        $sent = 0;

        foreach ($reminders->findStalled('ga_certification', $days) as $application) {
            $stage = $engine->currentStage($application);

            if (! $stage) {
                continue;
            }

            $daysStalled = (int) $application->updated_at->diffInDays(now());

            $recipients = $stage->key === 'supervisor'
                ? User::where('id', GaCertificationDetail::where('application_id', $application->id)->value('supervisor_id'))->get()
                : $reminders->recipientsForCurrentStage($application);

            foreach ($recipients as $recipient) {
                $recipient->notify(new ApplicationStalled($application, $stage, $daysStalled));
                $sent++;
            }

            $reminders->recordReminder($application, $stage->key);
        }

        $this->info("Sent {$sent} stall reminder(s) for Certification.");

        return self::SUCCESS;
    }
}
