<?php

namespace App\Modules\Chloe\Console\Commands;

use Illuminate\Console\Command;

/**
 * DEPRECATED, kept only because the shared routes/console.php still schedules `candidacy:remind` at 07:00 and a shared file is not ours to edit.
 * It sends NOTHING: reminders are now sent by candidacy:send-reminders (08:00), which de-duplicates and follows the Handbook rules. Running both
 * would send twice.
 */
class SendCandidacyReminders extends Command
{
    protected $signature = 'candidacy:remind';

    protected $description = 'DEPRECATED (does nothing): replaced by candidacy:send-reminders';

    public function handle(): int
    {
        $this->line('candidacy:remind is deprecated and sends nothing. Reminders are sent by candidacy:send-reminders (daily 08:00).');

        return self::SUCCESS;
    }
}
