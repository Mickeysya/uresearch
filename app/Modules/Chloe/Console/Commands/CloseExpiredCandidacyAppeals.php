<?php

namespace App\Modules\Chloe\Console\Commands;

use App\Modules\Chloe\Services\CandidacyAppealService;
use Illuminate\Console\Command;

/**
 * Daily 07:20 (Asia/Kuala_Lumpur, scheduled from the module provider): a RETURNED appeal whose candidacy expired is
 * closed -- it can no longer be resubmitted. An appeal waiting at an approver is never closed. Safe to run twice.
 */
class CloseExpiredCandidacyAppeals extends Command
{
    protected $signature = 'candidacy:close-expired-appeals {--dry-run : List what would be closed without changing anything}';

    protected $description = 'Close returned study candidacy appeals that were not resubmitted before the candidacy expired';

    public function handle(CandidacyAppealService $service): int
    {
        $dry = (bool) $this->option('dry-run');
        $closed = $service->closeExpired($dry);

        foreach ($closed as $appeal) {
            $this->line(($dry ? 'Would close ' : 'Closed ').$appeal->displayNo());
        }

        $this->info(($dry ? 'Would close ' : 'Closed ').$closed->count().' appeal(s).');

        return self::SUCCESS;
    }
}
