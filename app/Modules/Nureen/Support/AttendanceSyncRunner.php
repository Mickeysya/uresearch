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
    public function __construct(protected AttendanceIngestor $ingestor) {}

    public function run(?string $sourceOverride = null): AttendanceSyncRun
    {
        $source = AttendanceSourceFactory::make($sourceOverride);

        $run = AttendanceSyncRun::create([
            'source' => $source->label(),
            'started_at' => now(),
        ]);

        $summary = $this->ingestor->ingest($source->pull());

        $run->update([
            'finished_at' => now(),
            'rows_read' => $summary->read,
            'rows_added' => $summary->added,
            'rows_updated' => $summary->updated,
            'rows_rejected' => $summary->rejected,
            'rows_newly_at_risk' => $summary->newlyAtRisk,
            'rejection_reasons' => $summary->reasons,
        ]);

        return $run;
    }
}
