<?php

namespace App\Modules\Nureen\Support\AttendanceSources;

use App\Modules\Nureen\Models\AttendanceIngestQueueEntry;
use App\Modules\Nureen\Support\Contracts\AttendanceSource;
use Illuminate\Support\Collection;

/**
 * Consumes whatever AttendanceIngestController has staged since the last
 * run. The HTTP endpoint only checks the API key and the JSON shape and
 * writes a queue row -- actually ingesting happens here, on the same
 * schedule and through the same AttendanceIngestor as every other source,
 * so there is one validation/dedup/summary path rather than a second one
 * living inside the controller.
 */
class PushEndpointSource implements AttendanceSource
{
    public function label(): string
    {
        return 'API Endpoint';
    }

    public function pull(): Collection
    {
        $entries = AttendanceIngestQueueEntry::whereNull('processed_at')->get();

        $rows = $entries->map(fn (AttendanceIngestQueueEntry $entry) => $entry->payload);

        $entries->each->update(['processed_at' => now()]);

        return $rows->values();
    }
}
