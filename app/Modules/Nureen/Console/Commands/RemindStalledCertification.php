<?php

namespace App\Modules\Nureen\Console\Commands;

use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Nureen\Notifications\ApplicationStalled;
use App\Modules\Nureen\Support\StalledApplicationReminder;
use Illuminate\Console\Command;

/**
 * Certification's share of "remind whoever needs to act" -- see
 * RemindStalledGaExtension, which this mirrors exactly bar the module key
 * and config path. Both stages (CGS Staff, Senior Director) are held by
 * whoever has the role, so the generic Support\StalledApplicationReminder
 * fits with no special-casing.
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

            foreach ($reminders->recipientsForCurrentStage($application) as $recipient) {
                $recipient->notify(new ApplicationStalled($application, $stage, $daysStalled));
                $sent++;
            }

            $reminders->recordReminder($application, $stage->key);
        }

        $this->info("Sent {$sent} stall reminder(s) for Certification.");

        return self::SUCCESS;
    }
}
