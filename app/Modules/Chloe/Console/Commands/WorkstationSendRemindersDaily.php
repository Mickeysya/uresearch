<?php

namespace App\Modules\Chloe\Console\Commands;

use Illuminate\Console\Command;

/**
 * 08:00 REMINDERS (Asia/Kuala_Lumpur): the Workstation key collection reminder (day 3) and the key return reminder. Sends e-mail and bell
 * notices only; it changes no seat or key status. Running it twice sends nothing twice (reminder_sent_at / return_reminder_sent_at).
 */
class WorkstationSendRemindersDaily extends Command
{
    protected $signature = 'workstation:send-reminders';

    protected $description = 'Daily 08:00 reminders: key collection reminder and key return reminder';

    public function handle(): int
    {
        $this->call('workstation:remind-locker-keys', ['--only' => 'remind']);
        $this->call('workstation:process-releases', ['--only' => 'reminders']);

        return self::SUCCESS;
    }
}
