<?php

use Illuminate\Support\Facades\Schedule;

// Nureen — escalation reminder for a supervision request stalled >3 days
// on one stage. See App\Modules\Nureen\Console\Commands\RemindStalledSupervisionRequests.
Schedule::command('supervision:remind-stalled')->dailyAt('08:00');
