<?php

namespace App\Modules\Nureen\Support\Contracts;

use Illuminate\Support\Collection;

/**
 * Where `attendance:sync` pulls rows from -- a folder, an API endpoint, or
 * simulated data for a demo with no real UTrace access. The manual upload
 * page is deliberately not one of these: it is kept as its own flow
 * ("Manual fallback"), sharing only the row-ingestion logic
 * (Support\AttendanceIngestor), not this interface.
 *
 * Every implementation returns the same normalised shape the ingestor
 * already expects from a parsed sheet row: matric_no, period_end,
 * sessions_attended, sessions_total, and optionally a line/identifier for
 * error messages.
 */
interface AttendanceSource
{
    /**
     * @return Collection<int, array{matric_no: mixed, period_end: mixed, sessions_attended: mixed, sessions_total: mixed, line?: int|string}>
     */
    public function pull(): Collection;

    /** Human label for the sync summary and the upload page's status panel. */
    public function label(): string;
}
