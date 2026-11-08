<?php

namespace App\Modules\Chloe\Console\Commands;

use Illuminate\Console\Command;

/**
 * DEPRECATED, does nothing. The emailed seat-confirmation link (a 5-minute hold) was replaced by in-page seat selection on 2026-11-03 and its
 * table (workstation_pending_selections) was dropped. The shared routes/console.php still schedules this name every minute, so it stays as a
 * harmless no-op until that line is removed.
 */
class ReleaseExpiredWorkstationSelections extends Command
{
    protected $signature = 'workstation:release-expired-selections';

    protected $description = 'DEPRECATED (does nothing): seat holds no longer exist';

    public function handle(): int
    {
        $this->line('workstation:release-expired-selections is deprecated and does nothing. Seats are selected in-page; there are no holds to release.');

        return self::SUCCESS;
    }
}
