<?php

namespace App\Modules\Chloe\Console\Commands;

use App\Modules\Chloe\Support\CandidacyReminderEngine;
use Illuminate\Console\Command;

/**
 * Daily 07:25 Asia/Kuala_Lumpur (after candidacy:close-expired-appeals at 07:20), scheduled from the module provider.
 * --dry-run lists who would get which milestone and variant and writes nothing. --date=YYYY-MM-DD pretends today is that day; it is allowed only
 * with --dry-run or in the local environment, so it can never change what a real run sends.
 */
class SendCandidacyExpiryReminders extends Command
{
    protected $signature = 'candidacy:send-reminders {--dry-run : print what would be sent, write and send nothing} {--date= : treat this date (YYYY-MM-DD) as today}';

    protected $description = 'Send the candidacy expiry reminders (3, 2 and 1 month before the expiry date) that are due today';

    public function handle(CandidacyReminderEngine $engine): int
    {
        $dry = (bool) $this->option('dry-run');
        $date = $this->option('date');

        if ($date !== null) {
            if (! $dry && ! app()->environment('local')) {
                $this->error('--date can only be used with --dry-run, or in the local environment.');

                return self::FAILURE;
            }

            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date) || ! checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
                $this->error('--date must be a real date written YYYY-MM-DD.');

                return self::FAILURE;
            }
        }

        $today = CandidacyReminderEngine::today($date);
        $this->line(($dry ? '[dry run] ' : '').'Today for this run: '.$today->toDateString().' (Asia/Kuala_Lumpur)');

        $result = $engine->run($today, $dry);
        $stops = [];
        $sent = 0;

        foreach ($result as $r) {
            $who = "{$r['student']['id']} {$r['student']['name']}".($r['student']['demo'] ? ' (demo student)' : '').' expires '.$r['student']['expiry'];

            if ($r['action'] === 'send') {
                $sent++;
                $late = $r['skipped'] ? ' (late entry: skipped '.implode(', ', array_map(fn ($m) => $m.'-month', $r['skipped'])).')' : '';
                $this->line(($dry ? 'WOULD SEND ' : 'SENT ')."milestone {$r['milestone']} month(s), variant {$r['variant']}: {$who}{$late}".(! empty($r['demo_data']) ? ' [Demo data]' : ''));
            } elseif ($r['action'] === 'pause') {
                $stops['paused: '.$r['reason']] = ($stops['paused: '.$r['reason']] ?? 0) + 1;
                $this->getOutput()->isVerbose() && $this->line("paused ({$r['reason']}): {$who}");
            } elseif ($r['action'] === 'stop' || $r['reason'] !== 'no milestone due') {
                $stops[$r['reason']] = ($stops[$r['reason']] ?? 0) + 1;
                $this->getOutput()->isVerbose() && $this->line("none ({$r['reason']}): {$who}");
            }
        }

        ksort($stops);
        $this->info(($dry ? 'Would send ' : 'Sent ')."{$sent} reminder(s) to ".count($result).' students checked. Not sent: '.($stops ? implode('; ', array_map(fn ($k, $v) => "{$k} {$v}", array_keys($stops), $stops)) : 'none').'.');

        return self::SUCCESS;
    }
}
