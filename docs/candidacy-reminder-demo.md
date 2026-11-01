# Candidacy expiry reminders: demo walkthrough

Every command below was run on 2026-11-01 against the seeded demo data (`candidacy:demo-seed`). `--dry-run` prints who WOULD get which milestone and variant and
writes and sends nothing, so it is safe to run any number of times. `--date=YYYY-MM-DD` pretends that day is today (allowed only with `--dry-run`, or in the local environment).
Add `-v` to also list the students that are NOT reminded and why.

Run the commands from the project folder (with Docker: `docker exec uresearch-app php artisan ...`).

## Where the students stand today (2026-11-01)

`php artisan candidacy:send-reminders --dry-run` says: **would send 2** reminders to 162 students checked.

| Milestone today | Students |
|---|---|
| 3 months before the expiry date (variant A) | 2 (21010069 Chong Kang Wei, 21010128 Latha a/p Veloo; both expire 2027-01-29) |
| 2 months before | 0 |
| 1 month before | 0 |
| Late entry (several milestones due, only the latest sent) | 0 |
| Not reminded | softbound submitted 7, completed 3, inactive 3, expired 1, **paused because an appeal is in review 29** |

(The 13 students with a status of Softbound submitted / completed / inactive come from the labelled **demo overlay** and are shown as "Demo data".
Most students with an appeal expire in 2028, so their milestones are far away; the cases below use the date on which each one is due.)

## The cases

### 1. Milestone 3 (three months before)

Chong Kang Wei, 21010069, expires 2027-01-29, no appeal, no status: the 3-month date is 2026-10-29.

```bash
php artisan candidacy:send-reminders --dry-run --date=2026-10-29
```

The line for this student:

```
WOULD SEND milestone 3 month(s), variant A: 21010069 Chong Kang Wei (demo student) expires 2027-01-29
```

### 2. Milestone 2

Same student, 2026-11-29. A dry run writes nothing, so the 3-month reminder shows as skipped (late entry); a real run on 2026-10-29 first would have sent it, see case 13.

```bash
php artisan candidacy:send-reminders --dry-run --date=2026-11-29
```

The line for this student:

```
WOULD SEND milestone 2 month(s), variant A: 21010069 Chong Kang Wei (demo student) expires 2027-01-29 (late entry: skipped 3-month)
```

### 3. Milestone 1

Same student, 2026-12-29.

```bash
php artisan candidacy:send-reminders --dry-run --date=2026-12-29
```

The line for this student:

```
WOULD SEND milestone 1 month(s), variant A: 21010069 Chong Kang Wei (demo student) expires 2027-01-29 (late entry: skipped 3-month, 2-month)
```

### 4. Late entry: only the latest due milestone is sent

Same student, 2027-01-20: 3, 2 and 1 month are all due and unsent, so only the 1-month reminder is sent and the other two are recorded as skipped (never sent later).

```bash
php artisan candidacy:send-reminders --dry-run --date=2027-01-20
```

The line for this student:

```
WOULD SEND milestone 1 month(s), variant A: 21010069 Chong Kang Wei (demo student) expires 2027-01-29 (late entry: skipped 3-month, 2-month)
```

### 5. Softbound submitted stops reminders

Lee Wei Ming, 21010089, Softbound submitted on 2026-10-28 (demo overlay), expires 2027-01-29. `-v` also lists the students that are not reminded.

```bash
php artisan candidacy:send-reminders --dry-run -v --date=2026-10-29
```

The line for this student:

```
none (softbound submitted): 21010089 Lee Wei Ming (demo student) expires 2027-01-29
```

### 6. Inactive / deferred stops reminders

Amir Hakim bin Hassan, 21010111, inactive (demo overlay), expires 2027-05-29.

```bash
php artisan candidacy:send-reminders --dry-run -v --date=2027-02-28
```

The line for this student:

```
none (inactive): 21010111 Amir Hakim bin Hassan (demo student) expires 2027-05-29
```

### 7. Completed stops reminders

Chan Jia Ying, 21010121, completed (demo overlay), expires 2027-04-29.

```bash
php artisan candidacy:send-reminders --dry-run -v --date=2027-01-29
```

The line for this student:

```
none (completed): 21010121 Chan Jia Ying (demo student) expires 2027-04-29
```

### 8. Dismissed stops reminders

Nur Farah Adilah, 22001002 (a real account), dismissed.

```bash
php artisan candidacy:send-reminders --dry-run -v --date=2026-07-01
```

The line for this student:

```
none (dismissed): 22001002 Nur Farah Adilah expires 2026-10-01
```

### 9. An appeal in review pauses reminders

Hafiz bin Kassim, 21010080, appeal with the Supervisor, expires 2028-10-29 (3-month date 2028-07-29).

```bash
php artisan candidacy:send-reminders --dry-run -v --date=2028-07-29
```

The line for this student:

```
paused (appeal in review): 21010080 Hafiz bin Kassim (demo student) expires 2028-10-29
```

### 10. Returned appeal: variant C

Daniel Robertson, 21010013, appeal returned by the Supervisor, expires 2028-10-29.

```bash
php artisan candidacy:send-reminders --dry-run --date=2028-07-29
```

The line for this student:

```
WOULD SEND milestone 3 month(s), variant C: 21010013 Daniel Robertson (demo student) expires 2028-10-29
```

### 11. No further appeal possible: variant B (12 of 12 months used)

Ravindran a/l Rajan, 21010010, 6 + 6 months approved, expires 2028-09-29 (3-month date 2028-06-29).

```bash
php artisan candidacy:send-reminders --dry-run --date=2028-06-29
```

The line for this student:

```
WOULD SEND milestone 3 month(s), variant B: 21010010 Ravindran a/l Rajan (demo student) expires 2028-09-29
```

### 12. No further appeal possible: variant B (Dean rejected the last appeal)

Shalini a/p Rajan, 21010029, rejected by the Dean, expires 2028-09-29.

```bash
php artisan candidacy:send-reminders --dry-run --date=2028-06-29
```

The line for this student:

```
WOULD SEND milestone 3 month(s), variant B: 21010029 Shalini a/p Rajan (demo student) expires 2028-09-29
```

### 13. Expired: no reminder

Nur Farah Adilah, 22001002, expired on 2026-10-01 (today is after the expiry date).

```bash
php artisan candidacy:send-reminders --dry-run -v --date=2026-11-01
```

The line for this student:

```
none (expired): 22001002 Nur Farah Adilah expires 2026-10-01
```

### 14. Coursework students are excluded

No demo student has a Coursework programme (the Workstation demo data is all Full-Time/Part-Time research), so there is nothing to show on the demo data without changing a
Workstation row, which this work does not do. It is covered by the automated test `test_coursework_students_get_nothing_and_part_time_research_students_do`
(`tests/Feature/CandidacyReminderEngineTest.php`): a programme text containing "Coursework" gets no reminder, "Part-Time" research does.

### 15. Idempotent second run (and the real run)

A real run writes the log and sends the e-mail (to Mailpit locally). It needs `--date` in the local environment, because that is how a past/future day is simulated:

```bash
php artisan candidacy:send-reminders --date=2026-10-29    # first run: sends the 3-month reminder to the students due that day
php artisan candidacy:send-reminders --date=2026-10-29    # second run: "Sent 0 reminder(s)" (nothing is sent twice)
```

Look at the e-mails in Mailpit (http://localhost:8025). To undo everything the demo run created (the log rows and the demo data), run `php artisan candidacy:demo-clear`
and then `php artisan candidacy:demo-seed` again; the log rows of demo students are removed by `candidacy:demo-clear`.

## The status of a demo student

`php artisan candidacy:demo-softbound 21010069` marks that demo student "Softbound submitted today" in the demo overlay (add `--date=`, `--completed`, `--inactive`, or `--remove`).
`php artisan candidacy:check-status-source` counts how many students resolve from the shared source, the demo overlay, the candidacy record, or nowhere.
The overlay never applies to a real student, and the shared Softbound table is not read until its name and fields are filled in `config/candidacy.php` (`status_source_table`).

## Daily schedule (Asia/Kuala_Lumpur)

Two moments each morning. **07:30 REFRESH** changes data only; **08:00 REMINDERS** sends e-mail and bell notices. Every notice that a scheduled job owes a student goes out at 08:00:
the state change is made at 07:30 and its notice is queued with a delay until 08:00 the same day (`Support\ScheduledDelivery`). A run after 08:00 (manual, or a late scheduler) delivers at once;
page loads and people's own actions are never delayed. Running a job twice sends nothing twice (`reminder_sent_at`, `return_reminder_sent_at`, `candidacy_reminder_log`).

| Time | Command (defined in `ModuleProvider`) | What it does |
|---|---|---|
| 07:30 | `candidacy:close-expired-appeals` | closes returned appeals whose candidacy expired (student notice held until 08:00) |
| 07:30 | `workstation:refresh-daily` | key collection auto-cancel (day 7), vacate-period auto-release, candidacy-expiry / dismissal termination triggers (termination notice held until 08:00) |
| 08:00 | `workstation:send-reminders` | key collection reminder (day 3) and key return reminder |
| 08:00 | `candidacy:send-reminders` | the 3 / 2 / 1 month candidacy expiry reminders |

Older names still work: `workstation:process-releases` and `workstation:remind-locker-keys` run the refresh part and then the reminders part (inside the refresh context, so nothing they send
before 08:00 goes out early); `candidacy:remind` is a deprecated no-op. `routes/console.php` is shared and still lists the older entries (07:00 `candidacy:remind`, 07:30
`workstation:process-releases`, 08:00 `workstation:remind-locker-keys`); they are harmless duplicates and the owner of that file can delete them. Other modules' own jobs (e.g. `rpd:remind`,
`supervision:remind-stalled`) are not ours.
