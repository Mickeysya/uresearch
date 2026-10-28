<?php

namespace App\Modules\Nureen\Console\Commands;

use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Nureen\Notifications\ApplicationStalled;
use App\Modules\Nureen\Support\StalledApplicationReminder;
use Illuminate\Console\Command;

/**
 * GA Extension's share of "remind whoever needs to act" -- built on the
 * generic Support\StalledApplicationReminder, since every one of GA
 * Extension's stages (Supervisor, CGS Staff, Senior Director) is held by
 * whoever has the role, with no Supervision-style named recipient to
 * resolve separately.
 */
class RemindStalledGaExtension extends Command
{
    protected $signature = 'ga-extension:remind-stalled {--days= : Override config(\'nureen.reminders.ga_extension.stalled_days\')}';

    protected $description = 'Email the pending approver for any GA Extension application stalled at its current stage';

    public function handle(StalledApplicationReminder $reminders, WorkflowEngine $engine): int
    {
        $days = (int) ($this->option('days') ?: config('nureen.reminders.ga_extension.stalled_days'));
        $sent = 0;

        foreach ($reminders->findStalled('ga_extension', $days) as $application) {
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

        $this->info("Sent {$sent} stall reminder(s) for GA Extension.");

        return self::SUCCESS;
    }
}
