<?php

namespace App\Modules\Nureen\Console\Commands;

use App\Modules\Nureen\Support\AttendanceSyncRunner;
use Illuminate\Console\Command;

/**
 * The automated half of attendance ingestion -- the manual upload page's
 * "Manual fallback" is the other half and does not go through this.
 *
 * Scheduled nightly from Nureen\ModuleProvider::boot() and reachable on
 * demand from the "Sync Now" button on the upload page
 * (AttendanceController::syncNow(), which calls AttendanceSyncRunner
 * directly rather than Artisan::call() -- this command's registration only
 * happens for console requests, so a web request could not find it that
 * way). Idempotent: every source feeds the same AttendanceIngestor that
 * already upserts on (student_id, period_end) via
 * AttendanceRecord::recordPeriod(), so running this twice in a row never
 * duplicates a record -- the second run just reports everything as
 * "updated" instead of "added".
 */
class SyncAttendance extends Command
{
    protected $signature = 'attendance:sync {--source= : Override the configured source: inbox, api or simulated}';

    protected $description = 'Pull attendance records from the configured source and ingest them';

    public function handle(AttendanceSyncRunner $runner): int
    {
        $run = $runner->run($this->option('source'));

        $this->info(
            "[{$run->source}] Read {$run->rows_read}. Added {$run->rows_added}, "
            ."updated {$run->rows_updated}, rejected {$run->rows_rejected}. "
            ."{$run->rows_newly_at_risk} newly flagged at-risk."
        );

        return self::SUCCESS;
    }
}
