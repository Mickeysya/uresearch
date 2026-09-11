<?php

use Illuminate\Support\Facades\Schedule;

// Norhanis' RPD reminders (3 / 2 / 1 months before the candidacy deadline)
// will hang off this file. Register the command in your module, then add:
//
//     Schedule::command('rpd:remind')->dailyAt('07:00');
//
// Run `php artisan schedule:work` locally to exercise it.

// Nureen — escalation reminder for a supervision request stalled >3 days
// on one stage. See App\Modules\Nureen\Console\Commands\RemindStalledSupervisionRequests.
Schedule::command('supervision:remind-stalled')->dailyAt('08:00');
