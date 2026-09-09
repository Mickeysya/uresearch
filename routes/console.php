<?php

use Illuminate\Support\Facades\Schedule;

// Norhanis' RPD reminders (3 / 2 / 1 months before the candidacy deadline)
// will hang off this file. Register the command in your module, then add:
//
//     Schedule::command('rpd:remind')->dailyAt('07:00');
//
// Run `php artisan schedule:work` locally to exercise it.
