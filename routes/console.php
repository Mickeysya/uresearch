<?php

use Illuminate\Support\Facades\Schedule;

// Nureen — escalation reminder for a supervision request stalled >3 days
// on one stage. See App\Modules\Nureen\Console\Commands\RemindStalledSupervisionRequests.
Schedule::command('supervision:remind-stalled')->dailyAt('08:00');

// RPD reminders at 3/2/1 months before the deadline (norhanis.md Module 4).
// Early, so the mail is waiting when the student opens their inbox. The
// command is idempotent per milestone -- see SendRpdReminders for how.
Schedule::command('rpd:remind')->dailyAt('07:00');

// Chloe — reminder for a locker key left uncollected past the grace period.
// See App\Modules\Chloe\Console\Commands\RemindOverdueLockerKeys.
Schedule::command('workstation:remind-locker-keys')->dailyAt('08:00');

// Chloe — monthly-from-3-months-out study candidacy expiry reminders.
// See App\Modules\Chloe\Console\Commands\SendCandidacyReminders.
Schedule::command('candidacy:remind')->dailyAt('07:00');

// Chloe — daily refresh of the study candidacy dismissal candidate list.
// See App\Modules\Chloe\Console\Commands\GenerateCandidacyDismissals.
Schedule::command('candidacy:generate-dismissals')->dailyAt('07:15');
