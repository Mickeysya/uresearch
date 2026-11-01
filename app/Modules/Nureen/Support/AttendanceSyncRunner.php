<?php

namespace App\Modules\Nureen\Support;

use App\Modules\Nureen\Models\AttendanceSyncRun;

/**
 * Runs one attendance sync: picks the source, ingests it, records an
 * AttendanceSyncRun. Shared by the `attendance:sync` command and the
 * "Sync Now" button, rather than the button going through `Artisan::call()`
 * -- commands are only registered when `runningInConsole()` (see
 * ModuleProvider::boot()), so a web request cannot find one that way.
 */
class AttendanceSyncRunner
{
    public function __construct(
        protected AttendanceIngestor $ingestor,
        protected AttendanceEarlyWarning $earlyWarning,
    ) {}

    public function run(?string $sourceOverride = null): AttendanceSyncRun
    {
        $source = AttendanceSourceFactory::make($sourceOverride);

        $run = AttendanceSyncRun::create([
            'source' => $source->label(),
            'started_at' => now(),
        ]);

        $summary = $this->ingestor->ingest($source->pull());

        // The risk check -- original rule plus the end-of-semester
        // projection -- for every student, after every sync, and the
        // automatic early-warning emails. Every student, not just the ones
        // in this pull: a projection moves as the semester does.
        $warnings = $this->earlyWarning->run();

        $run->update([
            'finished_at' => now(),
            'rows_read' => $summary->read,
            'rows_added' => $summary->added,
            'rows_updated' => $summary->updated,
            'rows_rejected' => $summary->rejected,
            'rows_newly_at_risk' => $warnings['newly_at_risk'],
            'rejection_reasons' => $summary->reasons,
        ]);

        return $run;
    }
}
