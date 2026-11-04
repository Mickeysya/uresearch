<?php

namespace App\Modules\Chloe\Console\Commands;

use Illuminate\Console\Command;

/**
 * 07:30 REFRESH (Asia/Kuala_Lumpur), data only: key collection auto-cancel, vacate-period auto-release and the candidacy-expiry / dismissal
 * termination triggers. Any notice a student is owed for one of these changes is queued for 08:00 (Support\ScheduledDelivery), when
 * workstation:send-reminders and candidacy:send-reminders send. Content, recipients and de-duplication are those of the two older commands it calls.
 */
class WorkstationRefreshDaily extends Command
{
    protected $signature = 'workstation:refresh-daily {--dry-run : Print what would happen; change nothing}';

    protected $description = 'Daily 07:30 refresh: auto-cancel uncollected keys, release ended vacate periods, start automatic terminations (notices go out at 08:00)';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $this->call('workstation:process-releases', ['--only' => 'refresh'] + ($dry ? ['--dry-run' => true] : []));

        if (! $dry) {
            $this->call('workstation:remind-locker-keys', ['--only' => 'cancel']);
        }

        return self::SUCCESS;
    }
}
