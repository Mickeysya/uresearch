<?php

namespace App\Modules\Nureen\Console\Commands;

use App\Modules\Core\Models\User;
use App\Modules\Nureen\Models\GaExtensionDetail;
use App\Modules\Nureen\Notifications\ApplicationStalled;
use App\Modules\Nureen\Support\ClientCharter;
use App\Modules\Nureen\Support\StalledApplicationReminder;
use Illuminate\Console\Command;

/**
 * GA Extension's share of "remind whoever needs to act" -- built on the
 * generic Support\StalledApplicationReminder. The 'cgs_verify' and
 * 'senior_director' stages are held by whoever has the role; the
 * 'supervisor' stage is addressed to the one supervisor named on
 * GaExtensionDetail::supervisor_id, resolved here the same way
 * RemindStalledCertification does.
 */
class RemindStalledGaExtension extends Command
{
    protected $signature = 'ga-extension:remind-stalled {--days= : Remind after this many working days at any stage, instead of each stage\'s Client Charter}';

    protected $description = 'Email the pending approver for any GA Extension application stalled at its current stage';

    public function handle(StalledApplicationReminder $reminders, ClientCharter $charter): int
    {
        $days = $this->option('days') ? (int) $this->option('days') : null;
        $sent = 0;

        foreach ($reminders->findStalled('ga_extension', $days) as $application) {
            $clock = $charter->stageClock($application, $days);

            if (! $clock) {
                continue;
            }

            $stage = $clock['stage'];

            // The 'supervisor' stage is addressed to one named person
            // (GaExtensionDetail::supervisor_id), the same as
            // Certification's; every other stage to its role holders.
            $recipients = $stage->key === 'supervisor'
                ? User::where('id', GaExtensionDetail::where('application_id', $application->id)->value('supervisor_id'))->get()
                : $reminders->recipientsForCurrentStage($application);

            foreach ($recipients as $recipient) {
                $recipient->notify(new ApplicationStalled($application, $stage, $clock['working_days_taken'], $clock['charter_days']));
                $sent++;
            }

            $reminders->recordReminder($application, $clock);
        }

        $this->info("Sent {$sent} stall reminder(s) for GA Extension.");

        return self::SUCCESS;
    }
}
