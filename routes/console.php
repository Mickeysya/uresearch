<?php

use Illuminate\Support\Facades\Schedule;

// Norhanis — RPD deadline reminders at 3 / 2 / 1 months before each active
// candidacy's deadline. See App\Modules\Norhanis\Console\Commands\RemindRpdCandidates.
Schedule::command('rpd:remind')->dailyAt('07:00');

// Nureen — escalation reminder for a supervision request stalled >3 days
// on one stage. See App\Modules\Nureen\Console\Commands\RemindStalledSupervisionRequests.
Schedule::command('supervision:remind-stalled')->dailyAt('08:00');
