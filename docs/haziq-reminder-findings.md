# How Haziq's reminders and e-mails work (read-only investigation)

Written for: Chloe, to compare Haziq's module with her own (`App\Modules\Chloe`).

Date of the check: 2026-11-07, about 23:40 (Asia/Kuala_Lumpur). Nothing was changed, sent, seeded or migrated. Database figures are counts and dates only (SELECT). Merged code at `ca7d99e` plus my uncommitted work.

**Short answer:** his module sends **one kind of reminder**, the monthly Stage Gate grace reminder, from **one scheduled command at 07:30**. It only runs while the Docker scheduler container is running. "Push notification" in his code means the in-app **bell** (the `database` channel), not browser push. His module does **not keep e-mails itself**. This local database holds **no Stage Gate data**, so nothing here proves it ran on time.

## 1. His module

- Folder: `app/Modules/Haziq/` (GRA, GA, Stage Gates, Allowance Eligibility, TA Hours). `git log --author=Haziq` touches only `app/Modules/Haziq` (105 files) and `app/Modules/Core` (14 files). His main commit is `b628417` (2026-11-05).
- **Commands:** one, `stage-gate:evaluate`, in `Console/Commands/EvaluateStageGates.php:17`. It calls `StageGateEngine::evaluateAll()` (`EvaluateStageGates.php:23`).
- **Jobs, listeners, mailables:** none. A search of the folder finds no `Job`, no listener, no `Mailable` and no `MessageSent`.
- **Notifications (17), all in `Notifications/`:** the reminder one is `StageGateGraceReminder.php`. The rest are event notices (submitted, rejected, correction requested, letter issued, interview scheduled, stage gate completed, allowance withheld, termination triggered, appeal submitted/approved/rejected, allowance flagged/updated/confirmed, stage awaiting review).
- The engine that sends the reminder is `Support/StageGateEngine.php` (982 lines).

## 2. How his reminders are triggered

**Scheduled, once a day, from his own ModuleProvider:**
- `Haziq/ModuleProvider.php:48-51`: inside `$this->app->booted(...)`, `$schedule->command('stage-gate:evaluate')->dailyAt('07:30');` (line 50).
- **No** `withoutOverlapping()`, **no** `onOneServer()`, **no** `->timezone()`. The time is read in the app timezone (`config/app.php:13`, default `Asia/Kuala_Lumpur`).
- There is nothing in `routes/console.php` or `app/Console` for Haziq. (`routes/console.php` has only other people's lines: 7, 11, 15, 19, 25, 33.)

**Also triggered by a user action (inline):**
- `StageGateEngine.php:195` (`doRecomputeApplication`) re-evaluates the gates straight away when a student's correction changes `service_start_date`. That is the only non-scheduled path to `evaluateGate()`. No page load or model observer sends a reminder.

**How the reminder decides (`StageGateEngine.php`):**
- `evaluateAll()` (`:83-99`) loops every gate of an approved application that is not completed. `evaluateGate()` (`:443-489`) works out the state from **today's date** (`Carbon::today()`, `:447`; `match` at `:460-465`).
- In `grace_period` or `grace_expired` it calls `sendMonthlyGraceReminder()` (`:480-482`).
- That method (`:640-657`) starts from `last_reminder_sent_at` plus one month (or from the deadline month), and **sends one reminder for every owed calendar month up to this month** (`:642-654`). It then sets `last_reminder_sent_at = now()` (`:656`); the column is a `date` (migration `2026_04_01_000560_create_stage_gates_table.php:48`).

**If the computer or Docker was off at 07:30:**
- Laravel's scheduler does not replay a missed minute, so **that day's run is skipped**.
- His logic is date- and state-based, so the **next run catches up**. A gap inside one month sends nothing extra. If a whole month boundary was missed, it sends **one burst, one notification per missed month** (`:650-654`), each as a separate identical e-mail and bell entry.
- The guard is a date on the gate, not a database constraint. The notifications are sent **before** the gate is saved (`:651-652` then `:656`, saved at `:488`), so a crash in between would repeat them next run. This is an observation about the code order, not something I reproduced.

**External services:** none. A search of `app`, `config`, `routes`, `resources`, `public`, `composer.json` and `package.json` finds no Power Automate, web push, VAPID, service worker, Pusher, Reverb, broadcasting, Firebase or OneSignal. Nothing runs outside Docker.

**What runs the schedule:** the `scheduler` container (`docker-compose.yml:67`, `command: php artisan schedule:work` at `:76`, `TZ: Asia/Kuala_Lumpur` at `:84`). It was added to Haziq's branch in `b628417`, but it already existed in my commit `48817d6` (2026-11-03). The version in the repo now came through the merge `89b7385`.

## 3. What "push notification" means in his code

- All 17 notifications use `via()` = `['mail', 'database']` (for example `StageGateGraceReminder.php:23-26`). That is e-mail plus the **in-app bell** (the `notifications` table).
- No `broadcast`, no web push, no service worker, no VAPID keys anywhere (search above). **There is no push notification in the browser or phone sense.**
- All 17 implement `ShouldQueue` (for example `StageGateGraceReminder.php:17`), so both channels are sent by the **queue worker**, not by the scheduler itself. If the worker is down they wait in Redis. Redis has no named volume (`docker-compose.yml`, `redis` service, no `volumes:`).

## 4. "E-mails are always kept"

- **His module keeps nothing.** There is no table of sent mail, no `MessageSent` listener, no `.eml` copy, and no log mailer in his folder.
- What actually exists:
  - **The bell copy** in the `notifications` table (title and body text only, not the e-mail). It is in MySQL on the named volume `mysql-data` (`docker-compose.yml:105`, `:154`), so it survives restarts.
  - **The e-mail itself** goes to **the same Mailpit as mine** (`MAIL_HOST=mailpit`, port 1025). Before my change that Mailpit kept messages in a temporary file with a 500-message limit and lost them on every restart. It is persistent now (`docker-compose.yml:131-144`, volume `mailpit-data`, `MP_DATABASE=/data/mailpit.db`, `MP_MAX_MESSAGES=0`). Nothing written before today's recreation is recoverable.
- **Jason's `MessageSent` listener** (`app/Modules/Jason/Listeners/RecordExaminerPackDelivery.php`, registered at `app/Modules/Jason/ModuleProvider.php:53`, added in `3ba56ba`, 2026-09-17) only stamps `pack_sent_at` on one examiner row for appointment-letter packs. It is **Jason's**, not Haziq's, and it is not an archive.
- **My archive** (`Chloe/ModuleProvider.php:91`, `Support/MailArchive.php`) listens to `MessageSent` for **every** module, so Haziq's e-mails are archived too once my branch is merged (module column would read `haziq`).

## 5. Evidence in the database (2026-11-07)

| Check (SELECT, counts only) | Result |
|---|---|
| Notifications of any `Haziq` class, ever | **0** (also 0 in the last 14 days) |
| `StageGateGraceReminder` rows | **0** |
| `stage_gates`, `gra_ga_student_profiles`, `allowance_payment_logs` | **0 rows each** |
| Applications with `gra_application` / `ga_application` | **0** |

So this database has **no evidence either way** for days 3 to 7 Nov: his module has never had data here. His module also has **no tests** in the repository (no test file mentions `StageGate`).

My side, same days (`candidacy_reminder_log`): 1 row on each of 29 Oct, 30 Oct, 31 Oct, 1 Nov and 2 Nov, and **none from 3 to 7 Nov**. The scheduler container was only (re)started on 7 Nov (`2026-11-07T14:48 UTC` after my restart; created 3 Nov), and the queue/scheduler were down at 08:00 today.

## 6. Comparison

| | Haziq (Stage Gate grace reminder) | Mine (Candidacy expiry / Workstation) |
|---|---|---|
| Trigger | `dailyAt('07:30')`, no timezone, no overlap guard (`Haziq/ModuleProvider.php:50`) | `dailyAt('08:00')` with `timezone('Asia/Kuala_Lumpur')` and `withoutOverlapping()` (`Chloe/ModuleProvider.php:119-120`) |
| Catch-up after a missed day | Next run sends every owed monthly reminder, as a burst (`StageGateEngine.php:650-654`) | Next run on or after the milestone date sends only the latest milestone and records the earlier ones as skipped (`CandidacyReminderEngine.php:21-23`, `:164`, `:208`) |
| Guard against double send | `last_reminder_sent_at` date on the gate, saved after sending (`:656`, `:488`) | Database unique key per student, expiry date and milestone (`2026_11_03_000100_create_candidacy_status_overlay_and_reminder_log_tables.php:38-39`); a second run hits the constraint and sends nothing (`:213`) |
| Channels | `mail` + `database` (bell), queued | `mail` + `database` (bell), queued; anonymous recipients mail only (`CandidacyExpiryReminder.php:36-38`) |
| Where mail is kept | Nowhere of his own; Mailpit (now persistent) and the bell text in `notifications` | Same Mailpit, plus the permanent archive table and `.eml` files (`chloe_sent_mails`) |
| Needs Docker running | **Yes**: scheduler and queue containers | **Yes**: same containers |

**Does his approach work when the laptop is off?** No. It works only while the scheduler and queue containers are running at 07:30. A missed run is not replayed by Laravel; the **next** run after the laptop is back catches up because the logic reads today's date and a "last sent" marker. Mine behaves the same way, with a safer guard. Neither depends on an outside service, so neither can fire on its own while the machine is off.

**What I could copy (proposals only, nothing changed):**
1. **Nothing from his reminder logic.** Mine already catches up and has a stronger, database-level guard.
2. **Catch-up at start-up.** Because both of my reminder commands are safe to run twice, the scheduler container could run `candidacy:send-reminders` and `workstation:send-reminders` once when it starts, then `schedule:work`. A laptop opened at 10:00 would then still send that day's reminders.
3. **Tell Haziq** (for his own module) about the order at `StageGateEngine.php:651-656` versus `:488`, and that `last_reminder_sent_at` is only a date. I did not test it.

## 7. What I could not find

- Any proof that his reminders "ran on time" on 3 to 7 Nov: his tables are empty here.
- Any Stage Gate test in the repository.
- Any web push, broadcasting or outside service.
- Any record of his e-mails other than Mailpit and the bell text.
