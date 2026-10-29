<?php

namespace App\Modules\Chloe\Console\Commands;

use App\Modules\Chloe\Services\WorkstationAllocator;
use Illuminate\Console\Command;

/**
 * The periodic half of the 5-minute confirmation window's safety net — see
 * WorkstationAllocator::releaseExpiredSelections(), which this just calls.
 * The other half runs inline whenever seats are listed or a token is used,
 * so this is a backstop for a hold nobody happens to look at again, not the
 * only thing standing between an expired hold and a seat stuck Pending.
 */
class ReleaseExpiredWorkstationSelections extends Command
{
    protected $signature = 'workstation:release-expired-selections';

    protected $description = 'Release workstation seats whose 5-minute confirmation window has expired';

    public function handle(WorkstationAllocator $allocator): int
    {
        $released = $allocator->releaseExpiredSelections();

        $this->info("Released {$released} expired workstation selection(s).");

        return self::SUCCESS;
    }
}
