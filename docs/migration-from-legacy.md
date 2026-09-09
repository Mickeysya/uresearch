# What changed from the raw-PHP version, and why

The original portal was four divergent copies of a raw PHP/MySQL app — one at
the repo root and one per person, each with its own `schema.sql`. This records
what each change fixed, so nobody reintroduces a problem we already solved.

## Structural

| Was | Now |
|---|---|
| Four copies of the codebase in four folders | One Laravel app; each person owns one folder under `app/Modules/` |
| Three incompatible `schema.sql` files | Per-module migrations; nobody edits a shared schema |
| `modules/db_connect`, `login`, `sidebar` … with no `.php` extension | Regular Laravel files; the extension bug cannot recur |
| `sidebar.php`: a hardcoded if/elseif over 7 roles and 14 filenames | Built from `ModuleRegistry`; new modules appear on their own |
| 14 near-identical approval pages | One `WorkflowEngine` |
| Everyone loading `.sql` into their own XAMPP by hand | `docker compose up -d` — one identical database |

## Correctness

**Three conventions for "where is this application".** Travel/Publication/Claims
used display strings in `current_stage` and advanced `status` through
`endorsed → reviewed → approved`. GA Extension used snake_case keys and held
`status='pending'` throughout. Examiner nomination set `current_stage` at
submit time. The shared tracking page understood only the first, so GA
applications rendered as *Claims*, stuck on step 1 forever.
→ `status` now says only open/done/dead; `current_stage` holds a `Stage::key`.

**`decision='reviewed'` written into an ENUM that lacked it.**
`ga_extension_cgs.php` posted a value MySQL would reject in strict mode and
silently blank otherwise. → `decision` is VARCHAR; the verb comes from the
`Stage`. MySQL now runs in strict mode (`docker/mysql/my.cnf`) so this class of
bug fails loudly.

**Travel form wrote columns that existed in no schema.** Norhanis' form posted
`type_of_request` and `other_request_specify`; no `schema.sql` defined them, so
every travel submission failed. → Both are in the migration.

**Stage logic duplicated between routing and display.**
`travel_chair_approval.php` decided the next stage; `my_applications.php`
independently rebuilt the stage list. → Both read `stages()`.

**`get_current_index()` returned 1 for anything pending**, so any module not
advancing `status` showed as stuck on step 1. → `progress()` derives state from
the declared chain.

**No supervisor→student link and no department column.** Every supervisor saw
every pending application in the system. → `users.supervisor_id`,
`users.department`, `users.faculty`.

**No examiner pool.** `last_examination_date` was captured and never read, so
the 90-day cooling-off period was never enforced. → `examiners` with a derived
four-state machine (`Examiner::state()`).

## Security

**No authorisation on any approval page.** Every page checked only that
*somebody* was logged in, so a student could open `travel_dean_approval.php`
and grant final approval to their own application. → `role:` middleware plus a
second check inside `decide()` against the stage the application is actually on.

**`fix_test_passwords.php` was web-reachable** and ran
`UPDATE users SET password_hash = ?` with no `WHERE` — any visitor reset every
account in the system. → Deleted. Test accounts come from a seeder that only
runs from the CLI.

**Stored XSS across all 12 approval pages** — 64 raw `echo $row[...]` of
student names, destinations and reasons, plus `$_SESSION['name']` on the home
page. → Blade `{{ }}` escapes by default.

**Arbitrary file upload.** `ga_extension_form.php` did `mkdir(0777)`, kept the
original filename, ran no MIME or extension check, and wrote under the webroot
— a `.php` upload was remote code execution. → `DocumentStore`: extension
allow-list, size cap, random filename, private disk, streamed back through an
authorised controller.

**SMTP credentials committed** in `send_email.php`. → `.env`, git-ignored;
Mailpit locally so no real credentials are needed at all.

**No rate limiting on login**, and the form distinguished "wrong password"
from "no such user". → Five attempts per email+IP per minute, one message for
both failure modes.

## Still to build

The scan of the legacy code found these described in the FYP documents but
absent from the code. They are nobody's regression — they were never written.

| Module | Owner | Note |
|---|---|---|
| RPD (reminders, appeals, dismissals) | Norhanis | the largest gap; needs a scheduled command — `routes/console.php` has the hook |
| Publication, Claims | Norhanis | approval chains only; both are straightforward with the engine |
| Attendance + predictive at-risk | Nureen | needs a UTrace data source decision |
| Supervision, GA/GRA certification | Nureen | certification needs Dompdf (already in `composer.json`) |
| Conflict detection (touchpoint 2) | Hani | needs the CGS faculty-list compilation screen |
| Re-viva monitoring | Hani | 5-level outcome scale, 6-month and 1-year deadlines |
