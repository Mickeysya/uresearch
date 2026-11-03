<?php

use Illuminate\Support\Facades\Schedule;

// Nureen — escalation reminder for a supervision request stalled >3 days
// on one stage. See App\Modules\Nureen\Console\Commands\RemindStalledSupervisionRequests.
Schedule::command('supervision:remind-stalled')->dailyAt('08:00');

// RPD reminders at 3/2/1 months before the deadline (norhanis.md Module 4).
// Early, so the mail is waiting when the student opens their inbox. The
// command is idempotent per milestone -- see SendRpdReminders for how.
Schedule::command('rpd:remind')->dailyAt('07:00');

// Chloe — locker key reminder (day 3) and auto-cancellation (day 7) sweep.
// See App\Modules\Chloe\Console\Commands\RemindOverdueLockerKeys.
Schedule::command('workstation:remind-locker-keys')->dailyAt('08:00');

// Chloe — monthly-from-3-months-out study candidacy expiry reminders.
// See App\Modules\Chloe\Console\Commands\SendCandidacyReminders.
Schedule::command('candidacy:remind')->dailyAt('07:00');

// Chloe — daily refresh of the study candidacy dismissal candidate list.
// See App\Modules\Chloe\Console\Commands\GenerateCandidacyDismissals.
Schedule::command('candidacy:generate-dismissals')->dailyAt('07:15');

// Chloe — releases a workstation seat whose 5-minute email confirmation
// window has expired. Every minute, not daily: the whole point of this
// command is to free the seat again close to the moment it expires, not
// hours later. See App\Modules\Chloe\Console\Commands\ReleaseExpiredWorkstationSelections.
Schedule::command('workstation:release-expired-selections')->everyMinute();

// Chloe — the automatic-release pipeline for a dismissed student's seat
// (14/7/3-day notices, auto-release on the day) plus locker key return
// reminders. After candidacy:generate-dismissals (07:15), though not
// strictly dependent on it -- CGS confirms a dismissal by hand, on its own
// schedule, not this job's. See
// App\Modules\Chloe\Console\Commands\ProcessWorkstationReleases.
Schedule::command('workstation:process-releases')->dailyAt('07:30');
