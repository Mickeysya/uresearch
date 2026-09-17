# UResearch 2.0 — status

What exists, what doesn't, and what to do next. Update this in the same commit
as the work it describes.

**Legend:** `[x]` done · `[ ]` not started · `[~]` partial

---

## At a glance

| Area | State |
|---|---|
| Infrastructure (Docker, setup scripts, config) | done |
| Core (auth, RBAC, workflow engine, uploads, UI) | done |
| Student dashboard (5 stat cards + 4 live panels) | done |
| Database notifications (in-app feed + unread count) | done |
| Norhanis — Travel | done · reference implementation |
| Norhanis — Claims | done |
| Norhanis — Publication | done |
| Norhanis — RPD (reminders · appeals · dismissals) | done |
| Nureen — GA Extension · Attendance · Supervision · Certification | done · matches `docs/scope/nureen.md` |
| Hani — Examiner pool + Nomination, lifecycle closure, admin screen, conflict detection T2, Re-viva | done · internal/external pool split merged 2026-09-17 |
| CGS dashboard (5 stat cards + 5 live panels) | done |
| Admin dashboard (5 cards + 4 panels, system health) | done |
| Notification feed (`/notifications`) | done |
| Audit log (`/admin/audit-logs`, spatie/activitylog) | done |
| Jason — Hardbound Submission · Appeal · Appointment Letters | done |
| Chloe — Workstation · Candidacy Reminder / Appeal / Dismissal | scoped, not started |
| Haziq — GRA · GA · Stage Gates · Allowance | scoped, not started |
| **Cross-module overlaps** | **4 unresolved — see below** |
| Automated tests | **157**, all green — covering the engine, the seams, the CSP, the import, the profile, RPD’s three flows, Travel’s branch, Nureen’s and Hani’s chains, and Jason's three rules (signature gate, resubmit guard, appeal once-only) |
| **Runs end to end** | yes — verified 2026-09-09, re-verified 2026-09-12 |
| Last reviewed | 2026-09-17 — Jason's merge, a per-owner outstanding-issues audit in each section below, and every open item in Norhanis's and Jason's sections closed |

---

## Verified working

Run end to end on 2026-09-09 (Ubuntu 24.04 / WSL2, PHP 8.3.6, MySQL 8.4.11):

- [x] `./setup.sh` completes from a clean clone and an empty volume
- [x] All migrations run (9 at the time, 18 now); 11 accounts and 5 examiners seeded
- [x] Every route registers, including every module — auto-discovery works
      (20 at the time, 85 now)
- [x] International travel routes through all four approvers; the student's
      stepper shows four steps
- [x] Local travel shows two steps and finishes at the Chair — same form,
      different chain
- [x] A student POSTing to the decide route gets 403 (route middleware)
- [x] The Dean is refused on an application still at the Supervisor stage
      (the engine's second check) — both authorisation locks work
- [x] Six notification emails delivered to Mailpit

Three defects were found and fixed in the process: a missing `bootstrap/cache`,
`User::is()` colliding with Eloquent's `Model::is()`, and conditional stages
being invisible to the roles that owned them. See `git log`.

### Second pass — 2026-09-12

- [x] **Fixed: every approve/reject returned HTTP 500.** `REDIS_HOST` was in
      `docker-compose.yml` but in neither `.env` nor `.env.example`, and the
      `artisan serve` HTTP worker does not inherit Compose's `environment:`
      overrides — it falls back to `.env`, then to `config/database.php`'s
      `127.0.0.1`, where no Redis listens. Queueing `ApplicationDecided` threw
      `RedisException`, and because that `notify()` sits inside
      `WorkflowEngine::decide()`'s transaction, the whole decision rolled back:
      no history row, no stage advance, no readable error. Same failure mode
      the team had already patched for `DB_HOST`/`MAIL_HOST` in `setup.sh`;
      Redis arrived later and never got the same treatment.
      `REDIS_HOST`/`REDIS_PORT` are now in `.env.example`, and `sync.sh`
      backfills them into an existing `.env`.
- [x] Walked a full local-travel chain end to end again after the fix:
      submit → supervisor endorses → chair approves → `approved`, two history
      rows, two notification emails in Mailpit.
- [x] Re-confirmed both authorisation locks: a student POSTing to the decide
      route gets 403, and a Chair acting on a row still at the Supervisor
      stage is refused with the row left unmoved.

- [x] **Closed 2026-09-17:** `WorkflowEngine::decide()` now hands the student
      notification to `DB::afterCommit()`, and `notifyStudent()` reports and
      swallows a failure rather than 500ing a request the system accepted.
      That covers the outage above *and* the opposite failure: `RpdAppeal`,
      `RpdDismissal` and `AppointmentLetter` wrap `decide()` in a transaction
      of their own, and a notification dispatched inside it announced an
      approval that the outer transaction could still roll back.
      `DB::afterCommit()` defers to the **outermost** commit and runs inline
      when there is no transaction, so both directions are answered by one
      call. `tests/Feature/Core/WorkflowNotificationTest` holds it: both tests
      fail if the `notify()` goes back inside the transaction body.
      Checked every other notification site — Nureen's attendance and
      certification mails and Jason's three already sit outside their
      transactions, so the engine was the only one.
- [x] **Closed 2026-09-17: that table no longer exists.** The approver
      dashboard was rebuilt and its unscoped "Recent activity" list was
      replaced by "Your recent decisions", which reads `approval_history`
      filtered to this approver's own rows. The underlying exposure — an
      approver seeing rows that are not theirs — survives through the queues
      themselves and is tracked once, under "Scope approver queues to the
      right people".

### Third pass — 2026-09-15 (post-merge review)

Three merges landed on `develop`: Hani's Re-viva, examiner pool admin and
conflict detection (`f9bb220`), Norhanis' Claims and Publication (`6a0b711`),
and `b9ceb5c` joining the two. ~2,800 lines. Both were read against the hard
rules and **both pass**: no writes to `status`/`current_stage` outside the
engine, no columns on shared tables, no `{!! !!}`, every upload through
`DocumentStore`, every route behind `role:` middleware, all three new inline
scripts carry `@cspNonce`, no `Core/` or cross-teammate edits, both sides of
the `docs/module-keys.md` conflict resolved correctly, no files lost in the
merge, and `route:list` boots clean. What the review did turn up:

- [x] **Fixed: the whole test suite was broken by a migration.** All 38
      non-trivial tests failed with `no such column: "conference_or_journal_name"`,
      thrown out of
      `2026_09_14_231801_fix_publication_details_and_authors_columns.php`.
      That migration drops the columns the *original* publication create
      migrations made — but those create migrations had already been edited
      to the final column set, so on any database built from scratch the
      columns it tries to drop have never existed. It was only ever valid on
      a machine that had run the old create migrations first.
      **It therefore broke every fresh migrate**, not just the tests:
      `./setup.sh` on a clean clone, `./reset.sh`, `migrate:fresh`, and
      `./sync.sh` for anyone who had not already migrated. Deleted — the
      create migrations already carry the right shape, and Laravel ignores a
      `migrations` row whose file is gone, so a machine that already ran it
      needs nothing. **42 tests pass again** (48 with the two new files below).
- [x] **Done 2026-09-17: the suite runs through Sail — 152 passed.**
      `./vendor/bin/sail artisan test` is the documented way and the app
      container ships `pdo_sqlite`; a bare `php artisan test` on an Ubuntu
      host fails with `could not find driver (sqlite)` until
      `sudo apt install php8.3-sqlite3`, which is a host convenience, not a
      project requirement. One real trap found on the way: 13 storage tests
      failed with `UnableToCreateDirectory ... storage/framework/testing/disks`
      because 244 files under `storage/` and `bootstrap/cache` were owned by
      **root** — left behind by someone running artisan as root in the
      container (`docker compose exec` defaults to root; `sail` runs as uid
      1000). Sail then could not write into its own testing disk. Fixed, and
      the fix if it happens again on anyone's machine:

      ```bash
      docker compose exec laravel.test chown -R sail:sail storage bootstrap/cache
      ```

      Prefer `./vendor/bin/sail artisan ...` over `docker compose exec` for
      exactly this reason — it runs as the right user.
- [x] **Tests added for both merges** — the claims money path, Publication's
      pre-validation author read, the examiner tie-up and re-viva's
      one-open-cycle rule. Each was confirmed to fail against the unfixed code
      before the fix went in.
- [x] **`tests/` reorganised by owner, one file per feature.** It was four
      flat `<Person>ModulesTest` files that would only ever grow, and six
      people adding chains to them means six people editing the same files.
      Now `tests/Feature/<Person>/<Feature>Test.php`, so a new module is a new
      file in your own folder — the same property `app/Modules/` has. Shared
      cast in `Tests\Support\MakesUsers`. Same 53 tests, same 268 assertions.
      See `docs/conventions.md`.
- [x] **Closed 2026-09-17, verified in the code: `PublicationController.php:61`.**
      `count((array) $request->input('authors', []))` — the cast turns a
      scalar into a one-element array so it fails the `array` rule properly
      instead of throwing a TypeError. Original report:
      `count($request->input('authors', []))` runs *before* `validate()`, so a
      posted `authors=foo` throws an unhandled `TypeError` and the student gets
      a blank 500. Fix is one cast: `count((array) $request->input('authors', []))`.
      (Norhanis)
- [x] **Closed 2026-09-17, verified in the code: `ClaimsController.php`.**
      An advance larger than the total now throws a `ValidationException`
      after validation, where the total is known. Original report:
      `less_cash_advance` is validated `min:0` but never against the summed
      total, so an advance larger than the claim stores a negative balance and
      walks it through all four approvers. (Norhanis)
- [x] **Closed 2026-09-17, verified in the code.** It now checks
      `$application->refresh()->status === STATUS_APPROVED`, so it fires when
      the chain finishes rather than when this approver says yes — which
      survives the second stage that workflow's docblock plans. Original
      report: it fired on any approval,
      not the final one.** Harmless while the chain has one stage — but
      `ExaminerNominationWorkflow`'s own docblock plans a second stage for
      touchpoint 2, and the day it lands both examiners get tied up for 180
      days at the *first* approval. Guard it with
      `$application->refresh()->status === Application::STATUS_APPROVED`. (Hani)
- [x] **Closed 2026-09-17, verified in the code: `ReVivaController::create()`.**
      Every student's latest cycle comes from one query keyed by student.
      Original report: N+1, because `latestCycle()`
      runs one query per student, so `/re-viva/new` costs one query per
      postgraduate in the system. One `whereIn` keyed by student replaces the
      loop. (Hani)
- [x] **Closed 2026-09-17, verified in the code.** Both are scoped to
      `.app-form` now. Original report:
      `form > button[type="submit"]` now centres the login button and the
      submit on all five of Nureen's module forms; `input[type="file"]`
      restyles every file input in the app. `conventions.md` asks for at least
      two classes on anything added to a shared sheet. (Norhanis)
- [x] **Done: the publication "fix" migration is deleted** — see the first
      item in this list. It was not the tidiness problem it looked like; it
      was fatal on every database built from scratch. (Norhanis)
- [x] **Closed 2026-09-17, verified in the code:** `items` is `max:100` and
      `authors` is `max:50`. Original report: no `max`,
      so a crafted post can insert unbounded rows. `ExaminerAdminController`
      pulls the whole examiner pool into memory and filters in PHP — fine at
      FYP scale, worth knowing.

- [x] **Confirmed 2026-09-17: staying on `laravel/framework: ^12.0`.**
      `^12.0` already floats to the newest 12.x — the lock is on 12.69.2, and
      `composer update` picks up every 12.x release without touching
      `composer.json`. The only thing a "bump" could mean is Laravel 13, which
      is a framework major in the middle of an FYP with five other people
      pulling this repo: new deprecations, a `laravel/sail` and
      `maatwebsite/excel` compatibility check, and a re-run of all 152 tests,
      for no feature this project needs. Revisit after the demo, if at all.
- [ ] Have each teammate run `./setup.sh` on their own machine — the first run
      is the memory-hungry one, and their laptops differ.

---

## Done

### Infrastructure
- [x] `docker-compose.yml` — MySQL 8.4, phpMyAdmin, Mailpit
- [x] MySQL strict mode (`docker/mysql/my.cnf`) so bad data errors instead of truncating
- [x] `setup.sh` (idempotent first-time setup) and `reset.sh`
- [x] **Fixed: `setup.sh` destroyed data on every re-run.** Its header and the
      README both promised it was safe to re-run, while the script ended in
      `migrate:fresh --seed` — which drops every table. Anyone re-running it
      to fix a container problem lost their whole database. It now runs
      `migrate` against an existing database and seeds only on a genuine first
      install; `./reset.sh` remains the deliberate way to start over.
- [x] `scripts/env.sh` — helpers shared by `setup.sh` and `sync.sh`, so the
      two cannot drift. Holds the generic "copy any key .env.example has that
      .env lacks" backfill: `setup.sh` previously hard-coded a repair per key,
      which is why `REDIS_HOST` was missed and took down every approval.
- [x] `sync.sh` — run after a `git pull` or branch switch. Installs deps when
      `composer.lock` changed, copies new `.env.example` keys into your own
      git-ignored `.env`, runs pending migrations, clears Blade/config/route
      caches left over from the previous branch, and restarts the queue worker
      (`queue:work` holds the app in memory and otherwise keeps running
      pre-pull code). `--check` reports without changing anything.
- [x] **Fixed: `sync.sh` reported "MySQL is not running" at a healthy MySQL**
(2026-09-17). Six checks in the script grepped
      `docker compose ps --status running` for a container name. That needs
      three things to line up at once: a Compose new enough to know
      `--status` (2.6+), a working directory resolving to the same project
      that owns the containers, and a table shape the grep still matches. On
      a teammate's Mac one of them didn't, so every check reported "not
      running" while `docker ps` showed `uresearch-mysql` up and healthy for
      an hour — and the script aborted before migrations. All six now call
      one `container_up()` helper that asks `docker inspect` about the
      `container_name` the compose file pins: no project resolution, no flag
      support, no text parsing. It is also stricter where it counts — a
      container that declares a healthcheck (mysql, mailpit) must be
      *healthy*, not merely running, because migrations fired at a MySQL
      still booting fail in a way that reads like a schema bug. Containers
      without one (app, queue) fall back to plain state.
- [x] `.env.example` matching the compose file — no configuration needed
- [x] Laravel 12 skeleton: `artisan`, `bootstrap/`, `public/index.php`, `routes/`
- [x] `config/` — app, auth, database, mail

### Core — shared foundation
- [x] `User`, `Application`, `ApprovalHistory`, `ApplicationDocument` models
- [x] `Support\Role` — 13 roles, VARCHAR-backed so adding one needs no migration
- [x] `Support\Stage` — machine key + label + acting role + decision verb
- [x] `Contracts\WorkflowModule`, `Contracts\ProvidesLinks`
- [x] `Services\WorkflowEngine` — the only writer of `status` / `current_stage`
- [x] `Services\ModuleRegistry` — module discovery, sidebar and dashboard source
- [x] `Services\DocumentStore` — allow-list, size cap, random name, private disk
- [x] `Middleware\EnsureRole` + a second role check inside `decide()`
- [x] `Notifications\ApplicationDecided` — queued, fires on every decision
- [x] Login with rate limiting and non-enumerable failure messages
- [x] Dashboard (student + approver, Chart.js), application tracking, document download
- [x] `ApprovesApplications` trait — approve/reject for any module in one line
- [x] Migrations: users, sessions, cache, jobs, applications, approval_history, application_documents
- [x] Blade: layouts, registry-driven sidebar, stepper, status badge, decision form, shared queue partial
- [x] Norhanis' stylesheet carried over unchanged, additions appended below a marked line
- [x] Seeder — 11 accounts covering every role, 5 examiners in all four states
- [x] Module auto-discovery (`ModuleServiceProvider`)

### Core — student dashboard (2026-09-12)

Built to the design in `Sample/Student_Dashboard_UI.png`. Every figure is a
live query — there is no placeholder data anywhere in the views.

- [x] `Services\StudentDashboard` — one named method per panel, so swapping a
      data source is a one-method edit with no view/route/controller change
- [x] Five stat cards: Attendance, Predicted Attendance, Active Applications,
      Unread Notifications, Upcoming Tasks
- [x] "My Application Status" — driven by `ModuleRegistry`, so a teammate's new
      module appears here the moment a student submits one, with no edit
- [x] "Attendance Overview" — SVG gauge (no charting library) with band ticks,
      legend and status line all reading `StudentDashboard::attendanceBands()`,
      so the arc and the legend cannot drift apart
- [x] "Recent Notifications" and "Upcoming Tasks"
- [x] Single-screen layout: the page never scrolls, each panel body scrolls
      internally; grid reflows 5→3→2→1 columns, unlocking to normal page
      scroll below 1100px
- [x] `Application::reference()` — derived display reference (`GAEX-2026-00012`),
      never stored, so there is no column and nothing to keep in sync
- [x] `notifications` table + `ApplicationDecided` on the `database` channel —
      until now every notification was mail-only, so nothing the app sent was
      readable back inside the app. This is why `/notifications` was a
      placeholder. Additive: a notification opts in via its own `via()`.
- [x] `AttendanceRiskEvaluator::project()` — straight-line projection of the
      attendance trend, in the same "small explicit rule" spirit as
      `isAtRisk()`. Returns null rather than inventing a number when there is
      too little history.
- [x] Graceful degradation: every panel query runs through
      `StudentDashboard::safely()`, so a dead query or a missing module holds a
      skeleton placeholder for that panel instead of 500-ing the page.
      Verified by renaming the `notifications` table away mid-request — page
      still 200, two panels held, everything else rendered.
- [x] Load animation: cards rise in staggered from first paint, gauge sweeps,
      value counts up. No artificial delay — the page is server-rendered, so
      the data is already in the HTML.
- [x] Stylesheet cache-busted (`?v=<mtime>`), so a CSS change always reaches
      the browser instead of silently serving a stale cached copy
- [x] Favicon + a fixed `UResearch` tab title on both layouts

Two values on this dashboard are **derived rules, not stored facts** — worth
knowing before anyone builds on them:

- **Predicted Attendance** projects 4 periods ahead because there is no
  semester model to count toward. The design says "End of Semester"; when an
  intake/semester table exists, that becomes a real horizon.
- **Upcoming Tasks** is derived from real state (rejected applications, and
  at-risk attendance with no open appeal) because there is no deadlines table.
  Replace the body of `StudentDashboard::tasks()` and the panel keeps working.

### Core — CGS dashboard (2026-09-12)

Built to `Sample/CGS_TEAM_DASHBOARD.png`. Nureen's documented slice of the CGS
dashboard — verification queues and at-risk alerts. Every figure is a live
query; there is no placeholder data in the views.

- [x] `Services\CgsDashboard` — one named method per panel, `safely()` on every
      query so a dead source holds a skeleton instead of 500-ing the page
- [x] Five figures with month-over-month deltas: Total Applications, Pending My
      Action, Approved, Rejected, Active Students. A null delta renders nothing
      rather than a fake 0% when there is no prior month to compare against.
- [x] Workload Overview — SVG donut, segments drawn with `stroke-dashoffset`
      inside one rotated group, animated in. Fed by
      `ModuleRegistry::queuesForRole()`, so a new module appears by itself.
- [x] Pending Actions, Attendance Alerts, Recent Activities, Quick Shortcuts
- [x] Attendance Alerts reuses `StudentDashboard::attendanceBands()`, so a
      student reading "Good" is counted as Good by CGS. The 80% figure in the
      footer is the separate compliance threshold, deliberately not a band.
- [x] Recent Activities merges `applications` (submissions) and
      `approval_history` (decisions) rather than adding a third activity table
- [x] Single-screen on desktop at any height — no min-height floor, every
      vertical dimension in `vh`; verified fitting down to a 650px viewport
- [x] CGS sidebar: registry-driven Applications tree (so Travel, which CGS owns
      at `cgs_review`, cannot be forgotten), Attendance Monitoring tree
      including the CSV upload, and five honest placeholder screens
- [x] `Role::cgsTeam()` / `User::isCgs()`; CGS-only routes gated by role, not
      only hidden from the sidebar
- [x] Shared `partials/count-up.blade.php` — the gauge, donut and stat figures
      all animate through one script

### Core — admin dashboard, notifications, audit log (2026-09-12)

- [x] **Admin dashboard** to `Sample/ADMIN_DASHBOARD.png` — `Services\AdminDashboard`,
      five figures, Recent Activities, System Overview, Applications by Status,
      Quick Actions. Same single-screen contract as CGS.
      Two deliberate departures from the mockup, both because no scope
      document describes them: **no Courses screen** ("course" appears in none
      of the six scope docs, there is no `courses` table, and
      `users.programme` is free text — the card counts distinct programmes and
      says so), and **no separate Faculty screen** (every "Faculty" in the docs
      is an approver role or the FOE/FSMC attribute, never a directory).
      "Server Status" is replaced by **queue health**, which is a real signal.
- [x] Admin oversight screens are **read-only** by design —
      `queuesForRole('admin')` is empty, so acting on an application stays
      with CGS. Ten `/admin/*` routes, all gated by `role:admin` as well as
      hidden from the sidebar.
- [x] **Notification feed** (`/notifications`) — real for every role, grouped
      by day, filter tabs, mark-one and mark-all, pagination. Replaces the
      placeholder. Rows are POST forms because following one marks it read.
      Reachable from the dashboard panel too, which marks read and continues
      to the application.
- [x] **Audit log** (`/admin/audit-logs`) — `spatie/laravel-activitylog`.
      Closes `docs/scope/jason.md` §1.4, which `approval_history` could not:
      that table records decisions only, so a document *view* or *upload* left
      no trace. Now logged from `DocumentController`, `DocumentStore`,
      `WorkflowEngine` and `LoginController`.
- [x] **Charts are Chart.js**, the team's documented choice — both donuts, the
      status bars and the attendance gauge. Tooltips render as a `<div>` on
      `<body>` so nothing can clip them, and they are positioned away from
      what they describe. `chartjs-plugin-datalabels` prints the value above
      each bar.
- [x] **Attendance upload reads .xlsx** as well as .csv (`maatwebsite/excel`).
      CGS exports xlsx from UTrace; before this someone converted every file
      by hand.
- [x] Sidebar identity card, per-role (student / CGS / admin); collapsed, only
      the avatar and sign-out icon remain.
- [x] Stylesheet split into four files — `uresearch.css` (Norhanis' original,
      untouched), `layout.css`, `sidebar.css`, `dashboard.css`. The split is
      lossless: the concatenation hashes identically to the single file it
      replaced, so cascade order is unchanged. **Load order matters; do not
      reorder the `<link>` tags.**
- [x] **`dashboard.css` split again (2026-09-12).** It had grown to 2,519
      lines — the only file in the repo over 800 — and was carrying four
      unrelated screens plus every shared chart rule. Now nine sheets, each
      owning one thing: `dashboard-banner`, `dashboard-student`,
      `dashboard-gauge`, `dashboard-states`, `dashboard-cgs`,
      `notifications`, `dashboard-admin`, `charts`, `sidebar-identity`.
      Largest is 526 lines.
      Lossless the same way as before, and verified the same way: the nine
      files concatenated in load order normalise to a byte-identical hash
      against the original. One block moved — `sidebar-identity` now loads
      after the chart sheets instead of between them — and that was only done
      after confirming it shares no selector with anything it crosses.
      The sheet list now lives once in `core::partials.stylesheets`, included
      by both layouts, instead of being duplicated in each.
      New styling goes in the sheet that owns that screen; anything shared by
      all three dashboards goes in `charts.css` or `dashboard-states.css`.

### Core — design system and dark mode (2026-09-15)
- [x] **`public/css/tokens.css`** — the app had a brand but no system: 67
      distinct hard-coded hex colours across thirteen sheets against eleven
      brand variables, 47 font sizes, 16 border radii, 1002 px literals, and
      `#D0342C` written out 27 times. The success green had already forked into
      two values. Now one file holds the navy/gold ramps, four surface depths,
      four text weights, three border weights, one status set (`-fg`/`-bg`/
      `-border`, contrast-checked at WCAG AA), a 1.125 type scale, a 4px space
      grid, and radius/elevation/motion scales.
      Loads immediately after `uresearch.css` and redefines its eleven `:root`
      variables, so the 286 `var()` usages already written across the other
      sheets pick the new values up without being touched. `--navy` (UTP) and
      `--gold` are fixed; everything else derives from them.
- [x] **Dark mode**, toggle in the top bar. Every colour token is declared
      twice — explicit `data-theme`, and `prefers-color-scheme` for anyone who
      has not chosen. No stored choice means no attribute at all, so the app
      follows the OS until someone actually presses the toggle; the choice then
      persists in `localStorage` and is applied before first paint, the same
      way the collapsed sidebar already was.
      110 hard-coded declarations across ten sheets were converted to tokens to
      make it work. The chart tooltip and the six stat-card category discs are
      deliberately still literal — both are saturated surfaces carrying white
      text and read correctly on either ground.
- [x] **All seven student application forms are now wizards (2026-09-15).**
      Publication was 29 fields on one scroll, Claims 18, Travel 10 — the
      complaint was that "My Applications" forms were one long messy form.
      `core::partials.form-stepper` turns a form into a stepped one from two
      edits: `data-stepper` on the `<form>`, and each section wrapped in
      `<fieldset class="fstep" data-label="...">`. It builds the progress
      rail, Back/Continue, and a **generated review step** — read back out of
      the form's own controls, so it cannot drift from the fields and no
      module has to write one.
      **Client-side on purpose.** The form still POSTs once, to the same
      route, with the same fields; no controller and no `validate()` call
      changed. A server-side wizard needs session state, partial validation
      and resume logic, and a student cannot tell the difference.
      **It degrades**: nothing is hidden until the script runs, so with
      JavaScript off each form is exactly what it was. The per-step gate is
      `checkValidity()` only; the server re-checks everything.
      **A rejected submission reopens on the failing step**, found via
      `.is-invalid`/`.field-error` — landing on step 1 when the error is on
      step 3 is the classic way a wizard wastes someone's time.

      | Form | Fields | Steps |
      |---|---|---|
      | Publication | 29 | Paper · Cost · Authors · Documents · Review |
      | Claims | 18 | Claim details · Expenses · Review |
      | Travel | 10 | Trip details · Contact & documents · Review |
      | GA Extension | 4 | Extension details · Supporting document · Review |
      | Supervision | 3 | Supervisor · Supporting document · Review |
      | GA Certification | 4 | Request details · Review |
      | Attendance Appeal | 3 | Appeal details · Review |

- [x] **Documents and Calendar are real pages now (2026-09-15).** Both were
      `PageController` placeholders.
      **Documents** (`/documents`) lists `application_documents` — no new table,
      no new storage; the files were always there, just only reachable through
      whichever application they hung off. Filterable by module, searchable by
      name/type/reference/student. The visibility rule moved out of
      `DocumentController::show()` onto `ApplicationDocument::visibleTo()` and a
      matching `scopeVisibleTo()`, because **two copies of an access rule is how
      a list ends up naming files the download route then refuses** — or worse,
      the other way round. One definition, two callers, and a test asserting a
      student sees only their own.
      **Calendar** (`/calendar`) is a month grid over dates the modules already
      own, plus a "Coming up" list that looks a year ahead — a month view alone
      never answers "what is next": open it in March and December's deadline is
      invisible. New optional contract `SuppliesCalendarEvents`, discovered by
      `ModuleRegistry` exactly like `ProvidesLinks`, so **Core names no
      teammate's class**. Suppliers: RPD deadlines + the 3/2/1 reminder marks
      (Norhanis), travel windows, conference dates, GA appointment end
      (Nureen), re-viva correction/hardbound deadlines (Hani). A supplier that
      throws is skipped and reported rather than blanking the page for
      everyone. There is deliberately **no `events` table** — a second copy of
      dates already recorded is only a second thing to keep in sync.
- [x] **Stepper sweep (2026-09-15).** Checked every form in the repo; **two**
      warranted it and the rest do not:
      **Attendance upload** — its headings already read "1.", "2.", "3.", so it
      was a three-step procedure written as one scroll. Steps 1 and 2 carry no
      fields, which the stepper handles: the gate passes trivially and the
      generated review skips a step with nothing filled in.
      **Examiner nomination** — Candidate / Examiners / Notes were three
      distinct decisions on one page.
      **Left alone, on purpose:** the profile page (two *independent* forms —
      a wizard would break both), login (2 fields), the queue decision forms
      (inline per-row actions), the documents search, and "Add an examiner"
      (5 fields that are all one question, "who is this person"). A wizard
      around a single coherent group is an extra click for nothing.
      **Both "Add an examiner" screens revisited on 2026-09-17** — see the
      entry below; one outgrew the rule, the other was asked for.
- [x] **Examiner Pool made a real dashboard (2026-09-15).** It sat in a
      `.card.card-wide`, which caps at 680px — that is why the fourth stat card
      wrapped onto its own row and a seven-column table scrolled sideways on a
      1900px monitor. Full-width page now (1320px cap), stat cards on
      `auto-fit` so they stay even at any width instead of leaving a 3+1
      orphan, the filter bar as its own surface, the table on `.data-table` in
      a bordered well. Also fixed while there: the state badges carried
      hard-coded light-mode hexes and stayed light on the dark theme — they use
      the status tokens now.
- [x] **The wizard re-laid out (2026-09-15).** The first cut kept the form in
      a 680px card with the rail stacked under the page heading, which wasted
      most of a desktop screen and repeated the title above a rail that
      already said where you were. Now `core::partials.form-stepper` re-casts
      the card it finds: heading into a band at the top with the step counter
      beside it, rail into a **left-hand column**, fields into the rest at up
      to 1080px. Done in the script rather
      than in nine Blade files — the markup contract is still `data-stepper`
      + `.fstep`, so a module gets the new layout without editing its view.
      Steps now slide in **from the side you came from**, so the motion says
      which way you are travelling; `prefers-reduced-motion` turns it off in
      both the script and the CSS. Each review group gained an **Edit** link
      and finished steps in the rail are clickable, so a wrong answer on step
      one is one click away instead of three Backs.
- [x] **Fixed same day: the wizard's two-column field grid.** It filed every
      label into column one and every control into column two with no row gap
      between them, because a step is a plain run of siblings — nothing pairs
      a label to its control, so the grid had no single field to place.
      Removed rather than repaired: two columns need a wrapper per field in
      nine Blade files across four people's folders, which is more churn than
      a second column of inputs is worth. Fields now run one per row with
      `--space-6` between them and a ~34em measure cap, so a text input stops
      stretching the full pane width and looking like a textarea.
- [x] **A date picker, after all (2026-09-15).** The earlier entry below
      argued against one and that argument still holds for the usual version —
      a widget that *replaces* the native input and re-implements typing,
      locale, mobile and screen-reader support. This is the other kind:
      `core::partials.date-picker` leaves `<input type="date">` in place as the
      value, the thing the form posts and the thing validation reads, and only
      draws a panel over it. Coarse pointers are skipped entirely (the OS wheel
      beats any panel on a phone), typing is never intercepted, and with the
      script gone the field is exactly what it was. All arithmetic is UTC —
      going through local time is how a picker lands a day out west of the
      server. Included once at the layout level, so every module's date fields
      get it without knowing it exists; `data-no-picker` opts out.
      **Month and year are dropdowns**, not a label between two arrows: most
      dates here are weeks away and arrows reach those fine, but a part-time
      PhD's `candidature_start_date` is years back, and thirty-six clicks on
      an arrow is a penalty, not a picker. The year list is driven by the
      field's own `min`/`max` — a travel date carries `min=today` and offers
      forward years, a candidature start carries `max=today` and offers back
      ones — falling back to ten years either side, and always widened to
      include whatever value is already in the field.
- [x] **Navigation stopped flashing (2026-09-15).** Every navigation is still a
      full page load — this is Blade, not an SPA — but the white flash between
      documents is gone: `@view-transition { navigation: auto; }` in
      `layout.css` cross-fades old to new. One at-rule, no JavaScript, no
      dependency, no client-side router to keep in sync. The sidebar and the
      UTP header are given `view-transition-name`s so they sit out the fade
      and stay visually fixed, which is what stops it reading as a reload.
      Firefox navigates exactly as it does today.
- [x] **Decided 2026-09-17: the four short forms keep the wizard.** Three or
      four fields behind a two-step wizard is an extra click for a review of
      something already on screen, and the three long forms are where the win
      is — but a confirmation step before something goes to four approvers is
      defensible on its own, and one form behaving differently from the other
      twelve costs more to explain than the click costs to make. This is a
      taste call, not a defect, so it is closed rather than left open: the
      revert is still one form at a time, deleting that view's
      `<fieldset class="fstep">` wrapper and its `@include` of
      `core::partials.form-stepper`. Reopen it if the demo audience trips on
      it — not before.
- [x] **Fixed while building it: `el.hidden` had silently stopped working on
      buttons.** `layout.css` gives `button` a `display`, and an author rule
      beats the UA sheet's `[hidden] { display: none }` at any specificity —
      so both "Continue" and "Submit" rendered on every step. There is now one
      global `[hidden] { display: none !important }`, which is the one place
      `!important` earns it: `hidden` has to mean hidden whatever a component
      declares.
- [x] **Controls polished (2026-09-15).** `select` drops the native arrow for
      a chevron on the same grid as everything else, so a row of selects
      finally lines up with the text inputs above it; the chevron turns navy
      on focus with the border. Date/time fields get a full-height picker
      target, dimmed until hover, and an empty one shows `mm/dd/yyyy` at
      placeholder weight rather than as if it were filled in.
      Icons needed no work — they were already `stroke="currentColor"`
      throughout, which is why they themed correctly from the start.
- [x] **No custom date picker, deliberately.** The panel is browser chrome and
      already follows the theme through `color-scheme`. Replacing it means
      300-odd lines of JavaScript plus keyboard, locale and screen-reader
      handling, to land somewhere worse than the native control on a phone.
      Revisit only if CGS actually asks for something the native one cannot
      do, like disabling weekends or marking deadlines.
- [x] **Dark background plate** — `public/images/background_utp_dark.jpeg`,
      supplied by Sharvin, replaces the gradient the dark theme started with.
      Only `background-image` is swapped; size, position, attachment and
      repeat are already set on `body` by `uresearch.css` and carry over, so
      the two plates stay in register.
- [x] **Done 2026-09-17: the plate is 2712 KB → 226 KB**, 2400x1792 → 1280x955
      at JPEG quality 80, resized with GD in the app container. That is a 12x
      cut and it now sits just above the light plate's 138 KB rather than 20x
      over it. 1280 wide matches the light plate exactly, and the layer is
      `background-size: cover` under an 0.86 scrim, so there is nothing to see
      at any window size. Same filename, so no CSS changed — but it is the
      same URL, so anyone who loaded the old one gets it from cache until a
      hard refresh.
- [x] **The shared shell redesigned** in `layout.css`: cards, the full set of
      form controls (including the ones nobody had styled — search, tel, url,
      time, file), three button kinds, status badges, four flash tones,
      the stepper, two table weights, attachments, the decision trail, the top
      bar, scrollbars and selection. **One focus ring on every control** —
      the old sheet set `outline: none` and changed a border colour instead,
      which is invisible to anyone tabbing through a form.
      Module views inherit all of it: they already use `.card`,
      `.card-container-inline` and plain `<form>`, so nothing under
      `app/Modules/<Person>/` was edited.
- [x] **Chart.js themed too.** Chart.js paints to a canvas, so CSS reaches
      none of it — every gridline, tick and data label is a string handed to
      the library, and those strings were hard-coded. On the admin dashboard
      that left the value above each bar at `#23283A`, near-black on a dark
      page and unreadable; the two doughnuts cut their segment gaps in
      `#FFFFFF`, which read as white spokes across a dark card.
      `chartjs.blade.php` now exposes `Chart.uresearchToken(name, fallback)`,
      which reads the same custom properties `tokens.css` defines. Charts pass
      it as a **function**, not a value: Chart.js re-resolves scriptable
      options on `update()`, so a `MutationObserver` on `data-theme` plus a
      `prefers-color-scheme` listener repaints every live instance with
      `update('none')` — no animation replay, and no chart needs to know how
      it is themed.
- [x] **The type scale is fluid** (2026-09-17, merged to `develop`). No second scale was added: `--text-md` became
      `clamp(13px, 0.66rem + 0.24vw, 14px)` and the other seven sizes are
      ratios of it, so the whole scale shrinks as one instead of each page
      picking its own numbers. It resolves to exactly the old px at ≥1440px
      and floors at 13px, so nothing got smaller than readable. The `rem` in
      the middle term is deliberate — a size in pure `vw` shrinks as the user
      zooms in. `--page-max: clamp(880px, 92vw, 1320px)` joined it for page
      width. Caveat worth knowing before anyone calls this "global": most of
      the app still hardcodes px (`layout.css` alone is ~1900 lines), so a
      page becomes fluid only as its owner swaps literals for `var(--text-*)`.
      `app/Modules/Hani/.../examiner_admin/index.blade.php` is the worked
      example — zero `font-size` literals left.
- [x] **Two sidebar bugs, both in shared rules** (same commit).
      `.nav-subitem` had `white-space: nowrap` and nothing else, so a long
      queue label ("Appeal Hardbound Submission") ran off the panel mid-word
      with no sign there was more — it now clips with an ellipsis, and the
      two subitem links that render module labels carry a `title`.
      `.nav-item-flat` was `padding-left: 24px`, so an icon-less link under
      "Actions" started 32px left of every other nav label; it is `56px` now
      (24 padding + 20 icon + 12 gap), which lines them up. Both fixes are in
      `public/css/sidebar.css`, so they reach every role at once.
- [x] Verified in both themes, headless at 1440x950: login, student dashboard,
      CGS dashboard, admin dashboard, application tracking, travel form,
      attendance upload, at-risk list, notification feed, profile and the
      audit log. Every screen in the app now has been looked at, not assumed.
- [x] `docs/conventions.md` now has a **Design** section: the token groups,
      why `--navy` and `--accent-solid` are separate, and the rule that no
      other sheet may contain a colour literal.

### Docs
- [x] `README.md`, `CLAUDE.md`, `LEGACY.md`, this file
- [x] `docs/` — architecture, adding-a-module, conventions, module-keys,
      migration-from-legacy, email-service-integration,
      tech-stack-and-architecture-report
- [x] `docs/scope/` — six per-person scope documents + `technical.md`.
      `chloe.md` and `haziq.md` written 2026-09-12 from their interim-report PDFs,
      which are kept alongside in `docs/scope/chloe/` and `docs/scope/haziq/`.
- [x] `.claude/agents/` — module-builder, legacy-porter, core-guard, security-reviewer
- [x] A README in every module folder

---

## Norhanis — Travel · Publication · Claims · RPD

- [x] **Travel** — full chain, with conditional routing (local stops at the
      Chair; international continues to CGS and the Dean). Reference module.

- [x] **Publication** — Supervisor → Chair → Non-Exec CGS → Senior Director CGS
  - [x] `publication_details` + `publication_authors` tables (repeatable authors)
  - [x] Letter of Undertaking flag and its document
  - [x] `PublicationWorkflow`, controller, 3 views
  - Straightforward: a four-`Stage` chain, no conditional routing.

- [x] **Claims (student)** — Supervisor → Chair → Non-Exec CGS → Manager CGS.
      Project Director's payment processing was decided to stay a manual,
      post-approval step outside the app — not modelled as a `Stage` — so the
      chain ends at Manager CGS.
  - [x] `claims_details` + `claims_items` (repeatable expense rows)
  - [x] Server-side total / balance calculation — never trust the posted total
  - [x] Receipt uploads via `DocumentStore`

- [x] **RPD Candidacy (2026-09-15)** — all three flows, off one masterlist.
  - [x] `candidacies`: one row per student, unique on `student_id`. The
        deadline is **stored, not recomputed** — 8 months FT / 12 PT seeds it,
        and after that an approved appeal owns it. Recomputing on read would
        silently erase every extension ever granted.
  - [x] **Reminders** — `rpd:remind`, scheduled daily at 07:00. Fires at the
        3/2/1-month marks to the student *and* their supervisor.
        Firing once is the whole problem: the command runs daily, so the guard
        is `rpd_reminder_logs` with a unique index on
        `(candidacy_id, milestone)`, and the log row is written **before** the
        send so a duplicate key skips it rather than a check-then-write race
        letting two runs both through. Milestones are not cumulative — a
        candidacy entered six weeks out gets the 1-month reminder, not a
        backlog of the two it already missed.
  - [x] **Appeals** (`rpd_appeal`) — Supervisor → Chair → Non-Exec CGS → Dean.
        On the Dean's approval `RpdAppealController::grantExtension()` moves the
        masterlist, banks the months against the ceiling, writes `new_deadline`
        onto the appeal, and **clears the reminder log** — those rows record
        sends against the *old* deadline, so leaving them means the new one is
        never reminded about. All inside the engine's own transaction.
  - [x] Twelve-month extension ceiling, enforced server-side, with `max:` on
        the validator set to what is *left* rather than the constant.
        `norhanis.md` names no ceiling; twelve matches `chloe.md`'s parallel
        study-candidacy appeal so the two agree by default —
        `Candidacy::MAX_EXTENSION_MONTHS` is the one place to change it.
  - [x] **Dismissals** (`rpd_dismissal`) — CGS opens, then Dean → Faculty →
        Registry. CGS is deliberately **not a stage**: they author the case,
        the same shape as Hani's `re_viva`. Registry approval closes the
        candidacy and stamps `terminated_at`.
  - [x] `Role::FACULTY` added — `docs/module-keys.md` had it as undecided.
        One line in `Support\Role`, seeded as `faculty@utp.edu.my`. Jason's
        modules can reuse it. **This is a Core change** — raise it at the next
        team sync.
  - [x] Masterlist at `/candidacies` (CGS, filterable) and `/my-candidacy`
        (the student's own clock), both declared through `ProvidesLinks`.
  - [x] 12 tests: the window arithmetic including month-end clamping, the
        reminder firing exactly once, late entry not replaying missed
        milestones, the ceiling, the deadline actually moving on the Dean's
        approval, an intermediate rejection leaving it alone, and the
        dismissal chain end to end.

**Outstanding — audited 2026-09-17, then closed the same day.** Her four
modules are the most complete in the repo and all four carry tests (Travel 6,
Publication 2, Claims 4, RPD 12). The one thing that was genuinely unfinished
is now built:

- [x] **Done 2026-09-17: the dismissal's termination email is written.**
      `Notifications\CandidacyTerminated` (mail + database, queued, copied from
      `RpdDeadlineApproaching`) is sent to the student when the Registry
      approves — `norhanis.md` 4.3 makes "Registry sends termination email"
      the *point* of that stage, and the chain stopped one notification short
      of it. It names the termination date, the RPD deadline that was missed
      and the grounds CGS recorded, and links to the decision record. Sent
      **after** the transaction commits, not inside `closeCandidacy()`, for
      the reason the engine's own notify learnt the hard way. Student only:
      the Dean, Faculty and Registry all saw the case in their queues; the
      person who had not is the one it happens to. Covered by
      `test_registry_approval_closes_the_candidacy_and_emails_the_student`,
      which renders the mail for real inside the assertion so a broken
      `toMail()` fails rather than passes — confirmed to fail without it.

Checked and clear: `rpd:remind` is registered and due daily at 07:00; an
approved appeal moves the deadline, banks the months against the ceiling and
clears the reminder log inside the engine's own transaction. Deliberately not
built, and correctly so: Claims' Project Director payment step (manual,
outside the app, agreed with the AE) and her own Chart.js bottleneck view —
the shared CGS workload donut already answers that question, pending
overlap #3.

---

## Nureen — Attendance · GA Extension · Supervision · Certification

- [x] **GA Extension** — Supervisor → CGS Staff → Senior Director CGS, with a
      mandatory supporting document enforced at validation

- [x] **Attendance Record** — the predictive piece
  - [x] **UTrace data source: CSV upload**, not a live API — this is a real
        FYP with no UTP system access. CGS staff export from UTrace and
        upload the CSV at `/attendance/upload`. Ingestion is isolated to
        `AttendanceController::upload()`; a live API can replace it later
        without touching the risk/alerting logic. Documented for the FYP
        report as future work pending UTP granting API access.
  - [x] `attendance_records` table (`student_id`, `period_end`,
        `sessions_attended`, `sessions_total`) + percentage, always derived
        from the two counts server-side, never trusted from the CSV
  - [x] At-risk rule (`Support\AttendanceRiskEvaluator`): below the 80%
        threshold outright, OR declining across the last two uploaded
        periods and within 5 points of it — the early-warning half
  - [x] Proactive alerts to student and supervisor (`AttendanceAtRisk`,
        queued) — fires once, on the transition into at-risk, not on every
        upload
  - [x] CGS "At-Risk" dashboard (`/attendance/at-risk`)
  - [x] **Downloadable template** (`/attendance/template`, .xlsx or
        `?format=csv`). The importer demands an exact header row and the only
        way to learn that was to get it wrong; CGS now starts from a correct
        sheet with two filled-in examples. Both formats and the importer's own
        header check read `Support\AttendanceSheet::COLUMNS`, so the template
        cannot drift from what upload() accepts — there is a test that
        downloads the template and feeds it straight back.
  - [x] **Import hardening.** `period_end` is parsed through
        `AttendanceSheet::parseDate()`, which accepts ISO, the slashed and
        dotted forms Excel writes when a CSV is re-saved, .xlsx date cells and
        Excel serial numbers — and rejects `31/13/2026` rather than rolling it
        over. Slashed dates are read day-first, matching every date the portal
        displays. Rows are also checked for `sessions_total > 0` and
        `sessions_attended <= sessions_total`.
  - [x] **Skipped rows say why.** The upload used to report a bare count, so a
        file that half-worked gave no clue which line to open. Each skipped row
        now names its sheet line and the reason, the first five are shown, and
        it renders as a *warning* — the good rows are saved either way.
        Needed a new `.message-warning` (in `layout.css`; `uresearch.css` is
        Norhanis' and is not edited in place) and a third branch in
        `core::partials.flash`.
  - [x] **Fixed: re-uploading a period could 500 and roll back the whole
        upload.** `updateOrCreate` matched on the bare date string while the
        `date` cast writes `period_end` with a zeroed time, so the match relied
        on MySQL coercing `'2026-08-31'` to `'2026-08-31 00:00:00'`. Where that
        coercion does not happen the lookup misses, the insert hits
        `unique(student_id, period_end)`, and the transaction takes every good
        row down with it. Now `AttendanceRecord::recordPeriod()`, which looks
        up with `whereDate()` — compiled per driver. Found by the test, not in
        production.
  - [x] Attendance appeal workflow (`attendance_appeal`, single stage to
        Non-Executive CGS), reachable from a student's "My Attendance" page

- [x] **Supervision** — supervisor appointment requests. Closed 2026-09-17:
      every bullet below is done and the module's own scope is complete. The
      tilde had been standing in for the Core queue-scoping gap, which is
      not Nureen's to close — it is tracked under "Correctness gaps in Core"
      and affects every module, so leaving it on her line only hid it.
  - [x] Request → Supervisor → CGS eligibility review
  - [x] **Closed: required documentation** (`nureen.md` Module 3 asks for
        "Student submits request *with required documentation*"). The form now
        takes a mandatory supporting document through `DocumentStore`, so it
        lands on the private disk under a random name and is served back only
        by the authorised download route. No migration: uploads belong in
        `application_documents`, not on the detail table. The supervisor's
        queue eager-loads them, so the shared queue partial lists the file.
  - [x] Specific feedback on rejection (the standard remarks field)
  - [x] Reminder escalation when an approval stalls
        (`supervision:remind-stalled`, scheduled daily at 08:00; tracks
        `supervision_details.reminded_at` so a stall is only nagged once per
        period)
  - [x] On CGS approval, sets `users.supervisor_id`
        (`SupervisionController::decide()`)
  - [x] **Closed a gap the engine doesn't handle generically yet:** at the
        "supervisor" stage the student has no `supervisor_id` for
        `WorkflowEngine::queue()` to scope by, so by default any
        supervisor-role user would see and could act on every request, not
        just the ones addressed to them. `SupervisionController` filters its
        own queue view and overrides `decide()` to check the specific
        `requested_supervisor_id` before delegating to the engine. This is a
        module-local patch, not a fix to the underlying queue-scoping gap —
        see "Correctness gaps in Core" below, still open for every module.

- [x] **Attendance feeds the student dashboard** — the gauge, both attendance
      stat cards and the at-risk task all read `attendance_records` live.
      `AttendanceRiskEvaluator` gained `project()` for the predicted figure.

- [x] **GA/GRA Certification Letter** — closed 2026-09-17; generate, format and dispatch are all in.
  - [x] **Closed: the letter is now dispatched** (`nureen.md` Module 4 asks
        for "generate, format, **and dispatch**"). `Notifications\CertificationIssued`
        emails the student with the PDF attached from the private disk, and
        also writes to the in-app feed. The letter itself stays an
        `ApplicationDocument` behind the authorised route — the email is a
        copy, not the record — and a missing file degrades to a mail without
        an attachment rather than a failed job.
  - [x] Field completeness check (validation), GA vs GRA declared by the
        student and checked by CGS at the `cgs_verify` stage
  - [x] Approver endorsement stage (Senior Director CGS, `senior_director`)
  - [x] PDF generation with Dompdf on final approval
  - [x] Stored via `DocumentStore::storeGenerated()` — new, small, additive
        method added to Core for this (the only Core change in this work);
        Jason's Hardbound/Appointment modules can reuse it for their own
        generated PDFs rather than re-implementing the same storage logic
  - [x] Attached as an `ApplicationDocument`, so the existing download route
        and permission check apply unchanged — verified a second student
        cannot fetch another's certificate (403)


**Outstanding — audited 2026-09-17, cleared the same day.** Every sub-item
above is closed, all four chains work end to end, and all four now carry
tests (Attendance 8, GA Extension 6, Attendance Appeal 5, Supervision 2,
Certification 1). Nothing is open in this section:

- [x] **GA Extension now has one** (2026-09-17) —
      `tests/Feature/Nureen/GaExtensionTest.php`, 6 cases. Document
      completeness is the module's point per `nureen.md` Module 2, so three
      of them are the ways an incomplete request must bounce: no supporting
      document, a new end date that is not after the current one, and a
      one-line "justification". The other three walk the chain, asserting the
      position after *every* decision rather than only at the end —
      supervisor endorsement leaves it on `cgs_verify`, CGS verification
      leaves it on `senior_director`, and only the Senior Director closes it.
      That middle stage is precisely what the legacy app got wrong, and a
      test that only checked the final status would not have caught it. A
      rejection is checked to hold the stage it died on, so the trail still
      names who stopped it.
- [x] **Attendance Appeal now has one** (2026-09-17) —
      `tests/Feature/Nureen/AttendanceAppealTest.php`, 5 cases. One stage
      means the first approval is the last one, with no second approver to
      catch a mistake, so that is asserted directly. The important one is
      ownership: `attendance_record_id` is a student-supplied id, and the
      check in `store()` is all that stops one student attaching another
      student's flagged period to their own appeal — 403, and nothing
      written. Also covers that the document is optional here (unlike GA
      Extension and Supervision) but still lands on the private disk under a
      random name, and that a general dispute with no record named is valid.
- [x] **Both tildes resolved** (2026-09-17). Certification closed outright.
      Supervision closed too, with the reason written on its own line: the
      Core queue-scoping gap it was standing in for belongs to Core, not to
      her module, and keeping it as a tilde here hid a problem every module
      has behind one person's name.

Checked and clear: `supervision:remind-stalled` is registered and due daily at
08:00; `AttendanceAtRisk`, `SupervisionRequestStalled` and
`CertificationIssued` all implement `ShouldQueue`, and both the `queue` worker
and Redis are up in Compose — so none of them silently no-op the way a queued
notification does on a box with no worker.

---

## Hani — Examiner Nomination · Conflict Detection · Re-viva

- [x] **Examiner pool** — `examiners` with the four-state machine; state is
      derived, so an examiner leaves the gap automatically at 90 days
- [x] **Nomination** — supervisor nominates main + backup for their own
      candidates; AE approves. Touchpoint 1 enforced in the dropdown and again
      on submit.
- [x] **Examiner lifecycle closed** — `ExaminerNominationController::decide()`
      overrides the trait to set `assigned_until` on both examiners when the
      AE approves; a new "Pending Evaluation" screen (AE) marks the
      evaluation complete, clearing `assigned_until` and stamping
      `last_examination_date`, which starts the real 90-day gap. No migration
      needed — both columns already existed.
- [x] **Conflict detection, touchpoint 2** — `/examiner-nomination/conflicts`
      (AE) compiles active nominations by the examiner's faculty and flags
      any examiner nominated from more than one department. Read-only, never
      auto-rejects. No migration — reused `examiners.faculty` and
      `users.department`/`faculty`.
- [x] **Re-viva monitoring** — `re_viva` module: CGS Staff logs the
      re-corrected thesis once it reaches them (`/re-viva/new`, Non-Exec CGS,
      not a student self-service upload — an eligibility-aware student picker
      mirrors the examiner dropdown), stamping `re_viva_details.resubmission_at`
      with the 6-month/1-year deadlines computed and stored at that moment;
      AE advances a 4-stage stepper (Report sent → Under panel review →
      Report received → Consolidation scheduled); AE then records the
      5-level outcome on a separate "Re-viva Outcomes" screen, which never
      touches `applications.status`/`current_stage`. Level 4 is not modeled
      as a loop in the Stage graph — CGS logs a new `re_viva` application the
      next time that student's thesis reaches them, and `ReVivaController`
      links it to the prior cycle via `re_viva_details.previous_cycle_id`.
      New table:
      `re_viva_details`. Verified end to end (submit → 4-stage advance →
      level-4 outcome → second cycle opens and links correctly → third
      submission blocked while cycle 2 is open).
- [x] Examiner admin screen (`/examiners`, Non-Exec CGS) — add an examiner,
      toggle Unavailable. Redesigned to a stat-card + filter/search + paginated
      table layout, reusing the `.sdash-stat` shell already global via
      `partials/stylesheets.blade.php` rather than adding a new one. Marking
      an examiner Unavailable now goes through a reason modal; the reason is
      shown back on the list and cleared on reactivation. One migration:
      `examiners.unavailable_reason` (nullable text).
- [x] **Internal and external are two different records now** (2026-09-17, merged to `develop`). CGS keeps two spreadsheets, and the external one carries
      nine columns the internal one has no equivalent of. `/examiners` grew a
      tab strip (All / Internal / External, each with its count) and the
      table's columns follow the tab; the stat cards and the department
      filter scope to it too. Migration
      `2026_09_17_000100_add_external_details_to_examiners_table`:
      `faculty_approval`, `institution`, `sector` (technical/research),
      `expertise`, `utp_cluster`, `years_experience`, `msc_graduated`,
      `phd_graduated`, `first_examination_date` — all nullable, all external
      only. Three sheet columns deliberately got **no** column: "Date 2nd" is
      `last_examination_date`, which already drives the 90-day gap and would
      drift if copied; "Student Name" (and the internal sheet's "Remarks") is
      derived from `examiner_nominations` in
      `ExaminerAdminController::studentsByExaminer()`, so the pool cannot
      contradict the nominations; "Remark" is empty in both issued sheets and
      `unavailable_reason` already shows under the badge. The split is
      enforced, not just displayed — `institution` is
      `required_if:type,external`, and `store()` strips
      `Examiner::EXTERNAL_FIELDS` when an internal examiner is saved, so an
      internal row can never hold half an external record. New:
      `tests/Feature/Hani/ExaminerPoolTest.php`, 9 cases covering each tab's
      columns, tab-scoped counts, the derived student column, the strip and
      the validation. (11 as of 2026-09-17 — the add form's stepper contract
      and the disabled external block.)
      `app/Modules/Hani/README.md` was updated once the branch merged.
- [x] **Tab switching stopped looking like a page reload** (same commit).
      Every tab is a real GET — the portal is server-rendered and
      `layout.css` already cross-fades navigations via the native View
      Transitions API. The flash came from the root snapshot including the
      page header, the tab strip and the filter bar, which are identical on
      every tab. Each now carries its own `view-transition-name`, the same
      way `.sidebar` and `.main-content-header` already did, so only the stat
      counts and the table animate. Firefox has no cross-document view
      transitions and navigates as before. Speculation-rules prerendering was
      considered and rejected: the page renders in 20–30ms, so it would buy
      ~40ms in exchange for a CSP exception, a second render per hovered tab
      and Chrome-only behaviour.


**Outstanding — audited 2026-09-17.** The examiner lifecycle and the re-viva
stepper are solid. Five things are open, and the first is the one that
matters:

- [ ] **Level 5 does nothing.** `ReVivaController::recordOutcome()` validates
      `between:1,5`, writes the level and the remarks, and that is the end of
      it. `hani.md` calls level 5 "Dismissal (Terminal state)" — but nobody is
      notified, no candidacy is closed, and nothing hands off to Norhanis'
      `rpd_dismissal` chain, which is the machinery built for exactly this
      ending. Decide which of the two modules owns a failed re-viva before
      either of you builds anything.
- [ ] **Levels 1–3 record a number, not corrections.** `hani.md` asks for
      "pass with varying degrees of correction tracking"; the table holds
      `outcome_level` plus a free-text `outcome_remarks`. Either that is
      enough for the report — say so here — or levels 1–3 need a correction
      deadline of their own, the way `re_viva_details` already stores the
      6-month and 1-year ones.
- [ ] **Conflict detection has no feature test.** Touchpoint 1 is covered by
      `ExaminerNominationTest`; touchpoint 2
      (`/examiner-nomination/conflicts`) has none, and it is much the harder
      query of the two — cross-department duplicates across active
      nominations.
- [ ] **Touchpoint 2 is built for a different actor than the scope names.**
      `hani.md` puts it at the "CGS Management Compilation Stage", with the
      Dean or CGS Management deciding the substitution; the screen is gated to
      the Academic Executive. One of the two is stale — confirm with CGS and
      correct whichever it is.
- [ ] **Re-appointment letters were left behind in the pivot.** `hani.md` §3
      lists re-appointment letters and evaluation-report PDFs for external
      panel examiners. Jason's `appointment_letter` generates both, but only
      for a *first* appointment; nothing generates them for a re-viva panel.
      Either re-viva reuses his CGS preparation step or it needs its own —
      and that is a conversation with Jason, not a change in either folder
      alone.

---

## Jason — Hardbound Submission · Appeal Hardbound Submission · Appointment Letters

Scope defined in `docs/scope/jason.md`. Three chains, three `module_type` keys
already claimed in `docs/module-keys.md`. `docs/scope/hani.md` records that
Hani originally pitched Hardbound Submission and Appointment Letters and
handed both off after workshops with CGS — this module folder is that
handoff, not a duplicate of her scope.

None of the three chains need a new `Support\Role` entry — `chair`,
`non_exec_cgs`, `senior_exec_cgs`, `academic_exec` and `dean_pgr` already
exist and already carry this shape of chain elsewhere. `senior_exec_cgs` is
used by exactly one built stage — this module's appeal ruling — and was
unseeded until 2026-09-17; it is `seniorexec@utp.edu.my` now.

- [x] **Hardbound Submission** (`hardbound_submission`) — Supervisor →
      Chairman of the Viva Voce Examination (the `chair` role) → Non-Executive
      CGS. The first two *sign* the Confirmation of Correction to Thesis as
      they approve (below). The
      spec's Senior Executive sign-off was dropped on 2026-09-14: a
      completeness check does not need it, and with no `senior_exec_cgs`
      account seeded it stalled every submission for everyone but one machine.
  - [x] `hardbound_submission_details` table: thesis title, matric number,
        programme, supervisor. Captured at submission rather than read back
        off `users`, so a candidate who later changes programme or
        supervisor does not retroactively change what CGS reviewed.
  - [x] Both CGS forms are reproduced from the issued originals. The
        **Hardbound Thesis Submission (UTP/CGS/021)**, ORIGINAL and STUDENT'S
        COPY, is generated pre-filled with the student's name, matric and
        programme for them to complete, sign and upload — the student signs
        this one themselves. The **Confirmation of Correction to Thesis
        (UTP/CGS/017A)** is generated from the details the student supplies
        (programme, viva date, supervisor, co-supervisor, title) and carried
        through the chain.
  - [x] **Electronic signatures on the Confirmation.** 017A's signatories
        are the Supervisor, the Internal/External Examiner and the Chairman
        of the Viva Voce Examination. The Supervisor and the Chairman
        (`chair`) each upload a signature image once at "My Signature"
        (`hardbound_signatures`, private disk); every approval regenerates
        the Confirmation with that approver's signature and the approval date
        stamped into their block, replacing the archived copy, so the
        application always carries one current version. Approving at those
        two stages without a signature on file redirects to the upload page;
        rejecting needs none. The signature and date come from the
        `approval_history` row the engine wrote, so the stamped form and the
        audit trail cannot disagree. **The Examiner block is left for a
        physical signature and official stamp** — examiners have no login.
        CGS has no block on 017A; its signature belongs on paper in 021's
        office block.
  - [x] Non-Exec review screen — forward to Senior Exec, or return to the
        student with mandatory comments
  - [x] Every stage's approve/reject emails the student (the engine's
        `ApplicationDecided`); CGS's approval also issues an acknowledgement
        receipt PDF via `DocumentStore::storeGenerated()`
  - [x] Resubmission form for a returned application
  - [x] **Open question, resolved without a Core change:** the spec wants
        "return to student, application stays open," which the engine has no
        outcome for. Taken the second of the two options offered here: a
        return is a rejection at `cgs_review`, and the resubmission form
        clones it into a fresh application carrying `resubmission_of_id`
        back to the returned one. That needed no `WorkflowEngine` change —
        reject already terminates, and the cloning is module code — and it
        keeps each attempt, its reviewer and its remarks on the record
        instead of overwriting them. **Corrected 2026-09-17:** this bullet
        used to say the stage separated two endings — returned at
        `cgs_review` and the student resubmits, rejected at `cgs_approve`
        and the appeal is their only route. There is no `cgs_approve` stage
        in this chain and has not been since the Senior Executive sign-off
        was dropped on 2026-09-14, so **every rejection in it is a return**
        and the student may resubmit whichever stage returned it. That is
        also the right behaviour: a supervisor saying "these are not the
        corrections the panel asked for" should send the student back to
        their corrections, not to an appeal at CGS. A true "returned, still
        open" outcome is still worth having in Core — see Cross-cutting —
        but nothing here is blocked on it now.

- [x] **Appeal Hardbound Submission** (`hardbound_appeal`) — only filable
      once a Hardbound Submission has been rejected/returned
  - [x] `hardbound_appeal_details` table, FK'd to the originating
        `hardbound_submission` application
  - [x] Appeal memo upload + written justification
  - [x] Non-Exec: compile the Dean PFR report (Dompdf) from the appeal memo
        and the original submission, then forward to Senior Exec. The
        Non-Exec's remarks are the recommendation printed in the report, so
        they are required at that stage.
  - [x] Senior Exec: ruling (accept/reject) — **on accept the original is
        not rewritten.** `applications.status` belongs to WorkflowEngine and
        the original rejection is a decision on the record, not a mistake to
        erase. **Corrected 2026-09-17:** this used to claim an upheld appeal
        "unlocks the resubmission form", and that only a `cgs_review` return
        or an upheld appeal made a submission resubmittable. Neither is what
        the code does or what `jason.md` §3 asks for. What the appeal chain
        produces is the thing §3.3 names — a deliberation, a Dean PFR report
        and a formal ruling emailed to the student — not a permission the
        student did not already have. A returned submission is resubmittable
        because it was returned; the appeal is the route for a student who
        wants the return itself ruled on rather than complying with it.
  - [x] Auto-email the ruling to the student — the engine's
        `ApplicationDecided`, same as every other chain
  - [x] A submission may only be appealed once, and only if it has not
        already been replaced by a resubmission — enforced in the module,
        not by a database constraint

- [x] **Appointment Letter & Report Management** (`appointment_letter`) —
      Chair of Department (the spec's "Faculty Department") → Academic
      Executive (the spec's "Faculty Academic") → Dean of PGR
  - [x] A nomination is the candidate's **examiner panel**, not one
        examiner: `appointment_details` holds the candidate side (degree,
        programme, supervisor, thesis title) and `appointment_examiners`
        holds one row per panel member — at least one internal and one
        external, enforced at nomination. The student it's for is
        `applications.student_id` (the candidate), same pattern as Hani's
        `examiner_nominations` — no second student FK on the detail table.
  - [x] Chair picks the panel from an examiner list
        (`appointment_examiner_pool`, kept by Chairs and CGS at
        "Examiner List") the same way they pick the candidate; a new examiner
        is registered there first. Deliberately separate from Hani's
        `examiners` pool so a change to hers cannot break a letter here.
        Nominations copy the chosen rows, so editing or removing a list
        entry never rewrites a letter already issued.
  - [x] Nomination is filed against the chain, not a stage of it — same
        shape as Hani's supervisor nomination; AE endorse or
        reject-with-comments; Non-Exec CGS prepares the pack; Dean
        approve/reject
  - [x] CGS preparation generates **two documents per examiner** from the
        CGS templates — the Appointment Letter (internal and external
        variants, with acknowledgement slip, conflict-of-interest declaration
        and thesis receipt confirmation) and the Thesis Evaluation Report
        form (UTP/PPS/024) — and archives them, so the Dean approves
        documents that already exist. On Dean approval each examiner is
        emailed their own two.
  - [x] **Open question, resolved:** every other module's notification goes
        to the student, a system user with an account. This one's final
        recipient is an external examiner with no login, so the PDF is sent
        with a plain `Mail\AppointmentLetterMail`, not the
        `ApplicationDecided` notification path. The engine's built-in
        `ApplicationDecided` still fires to the candidate only on every
        decision, same as the trait's default — the Chair (the nomination's
        filer, who owns no stage in the chain) is not separately notified.
  - [x] Archive the generated letter as an `ApplicationDocument` (written
        directly to the private disk — `DocumentStore::attach()` only takes
        an already-uploaded file, not generated bytes) so the existing
        download route and permission check apply

Not Jason's to (re)build — already exists in Core, or already tracked
elsewhere in this file:
- RBAC/login, the progress stepper, and email notifications
  (`jason.md` §1.1–1.3) — done; see "Core" above.
- A full audit-log admin viewer covering every view/upload, not just
  decisions (`jason.md` §1.4) — **built in Core and done**, at
  `/admin/audit-logs` (`spatie/laravel-activitylog`), logging from
  `DocumentController`, `DocumentStore`, `WorkflowEngine` and
  `LoginController`. `approval_history` records decisions only, so a
  document *view* or *upload* left no trace; that is what this closes. It
  was always a Core change rather than something to build inside this
  module folder — this line used to say it was still outstanding.
- The Admin Dashboard (`jason.md` §5.5) — already listed, unowned, under
  "Team" below.


**Re-audited 2026-09-17 (second pass).** All three chains are built,
registered and routable: 23 routes, 3 workflows, 9 migrations, 20 views. The
first pass found four things. Five more turned up on this pass, four of them
defects that no test could have caught because there were no tests. Six were
fixed then; the remaining three were closed on 2026-09-17 — see below.

Fixed on this pass:

- [x] **His module could not be tested at all.** Three queries ordered by
      `FIELD(examiner_type, 'internal', 'external')`, which is a MySQL
      extension with no SQLite equivalent, and the suite runs on SQLite in
      memory by design. Every screen touching `appointment_examiners` threw
      `no such function: FIELD` the moment a test rendered it. Replaced with
      a portable `CASE WHEN examiner_type = 'internal' THEN 0 ELSE 1 END` in
      `ExaminerPoolController`, `AppointmentLetterWorkflow::summary()` and
      `AppointmentDetail`. Same class of bug as the `whereDate()` one in
      `AttendanceRecord::recordPeriod()`: SQL that happens to work on one
      driver.
- [x] **"Add another examiner" never worked.** The nomination form's script
      was written as a bare `<script>`. `script-src` is `'self'` plus the
      request nonce with no `unsafe-inline`, so the browser refused to run
      it: Add and Remove did nothing, and the page reported no error. Now
      `<script @cspNonce>`.
- [x] **The queue's workload chart never drew.** It pulled Chart.js from
      `cdn.jsdelivr.net`, and `script-src` allow-lists no external host, then
      ran an un-nonced inline block. Now `@include('core::dashboard.partials.chartjs')`
      (self-hosted from `public/js`, as the report documents) with a nonced
      init, and the bar colour reads `--navy` through `Chart.uresearchToken()`
      so it survives the dark theme.
- [x] **None of his four fill-in screens used the shared stepper.** Norhanis,
      Nureen and Hani all use `core::partials.form-stepper`; Jason's were the
      only long forms still rendering as one scroll. Converted: Hardbound
      Submission (3 steps), Appeal (3), Panel Nomination (2), CGS Pack
      Preparation (3). Hardbound's first step is the UTP/CGS/021 download,
      lifted out of a bulleted list above the form and made a real
      `btn-secondary` button, because a link sitting above a long form is
      exactly what a student scrolls past. Same shape as the attendance
      upload's "Get the template" step. No controller changed. The form still posts once to
      the same route with the same fields, `$request->validate()` is
      untouched, and without JavaScript every step is visible and it degrades
      to the single page it was. The generated review step now names each
      examiner row properly, because the rows carry `id`/`for` pairs that the
      renumbering JS keeps in step.
- [x] **He has tests now**, though not the ones the first pass asked for:
      `tests/Feature/Jason/FormsTest.php`, 4 cases covering the stepper
      contract on all four forms and the chart no longer coming from a CDN.
      (7 as of 2026-09-17 — the Examiner List wizard on its own page, the
      return trip out of the nomination form, and the signature preview
      staying inside the Content-Security-Policy.)
      Render tests, deliberately: the stepper is client-side, so what breaks
      server-side is the markup contract, and a missing `data-stepper` or an
      unclosed step renders perfectly and produces no wizard.
- [x] **`cgs_prep` added to the stage-key table** in `docs/module-keys.md`.

Were open, all three closed 2026-09-17 (the third by rewriting the prose
rather than the code — read it before assuming the appeal chain changed):

- [x] **Closed 2026-09-17 by the Chair dashboard's "Panels you filed" panel**,
      which lists each one with the stage it is sitting on. Original report:
      `jason.md` §4.2 says the Academic Executive's rejection "routes back to
      Faculty Dept with comments". It does not route anywhere the Chair can
      look: `/appointment-letter/queue` is gated to
      `academic_exec, non_exec_cgs, dean_pgr`, `/applications` is
      student-only, and `AppointmentLetterController` notifies the candidate
      rather than the filer (recorded as a resolved open question above, but
      it is what leaves this hole). So a Chair submits a panel and it
      disappears: no list, no status, no rejection comments. Found auditing
      the Chair's sidebar on 2026-09-17 — everything a Chair *can* reach is
      linked, and this is the one thing they should be able to reach and
      cannot. Wants a "My Nominations" screen for the filer, or the
      `ApplicationDecided` notification extended to whoever submitted.

- [x] **Done 2026-09-17: `seniorexec@utp.edu.my` is seeded** (Puan Hasnah,
      `Role::SENIOR_EXEC_CGS`, password `password` like every other account),
      so `HardboundAppealWorkflow`'s `cgs_approve` stage has somebody able to
      rule on it and an appeal can reach an outcome. **This is one line in the
      shared `database/seeders/DatabaseSeeder.php`** — flagged here rather
      than done quietly, because that file is everyone's: it is an added line
      in the middle of the account list, so git merges it cleanly unless
      somebody else adds an account in the same place. Anyone who already has
      a database needs `./reset.sh` or `php artisan db:seed` to get the
      account. Also added to the README table, along with `faculty@` which
      was seeded but never documented.
- [x] **Closed the class of bug, not just this instance.**
      `ModuleContractsTest::test_every_stage_role_in_every_module_has_a_seeded_account`
      seeds `DatabaseSeeder` and walks `stages(null)` for **every registered
      module**, asserting somebody exists in each stage's role. A stage whose
      role nobody holds is invisible — no error, no empty queue anybody
      notices, applications just stop — and every other test makes its own
      users, so nothing looked at the seeder. Confirmed to fail before the
      line above was added, naming the module, the stage and the role.
- [x] **Resolved 2026-09-17 by rewriting the paragraph, not by tightening
      `resubmittableFor()`.** The code is right and the prose was stale. With
      the Senior Executive sign-off dropped from the submission chain on
      2026-09-14, the chain is Supervisor → Chairman → Non-Exec CGS and there
      is no terminating rejection left in it: every rejection is a return, so
      "any rejected submission not yet replaced" is exactly the right set.
      Tightening it would have meant a student whose *supervisor* said the
      corrections were not done had to appeal to CGS before fixing them,
      which contradicts `jason.md` §2.1 and is worse behaviour besides. The
      appeal is not a precondition for resubmitting and §3 never said it was
      — what it produces is the Dean PFR report and a formal ruling (§3.2,
      §3.3). Two bits of code carried the same stale claim and were corrected
      with it: the resubmit backstop's 403 read "CGS rejected this
      submission. File an appeal before resubmitting.", a rule the system
      does not have and an `abort` that could never fire anyway, and the
      upheld-appeal banner said "The student may now resubmit" when they
      always could.
- [x] **Done 2026-09-17: `tests/Feature/Jason/HardboundRulesTest.php`, 4
      cases** covering all three — the signature gate on approval (and that
      rejecting is deliberately *not* gated, since a rejection signs
      nothing), the resubmit guard (not yours, not returned, not twice) and
      the appeal's once-only rule (not twice, and not after the submission
      has been replaced instead). A fourth covers the resubmission's
      mandatory response to comments. Each was confirmed by breaking the rule
      it guards and watching it fail: the gate bypassed, the guard removed,
      the `whereNotIn` dropped. The resubmit case asserts the specific 403
      *message*, because the shared backstop below it also returns 403 and a
      status-only assertion would not notice the guard going missing.

Known and tracked elsewhere: the student sidebar drops his "Resubmit #N"
links (Core gap below; worked around by putting them on the Hardbound page).
Deliberately out of scope and correctly so: the Senior Exec sign-off on
Hardbound (dropped 2026-09-14), the Admin Dashboard (§5.5, unowned) and the
CGS Lifecycle Monitor (§5.3, blocked on overlap #3). The audit viewer
covering views and uploads (§1.4) is not out of scope — it is built, in
Core, at `/admin/audit-logs`.

---

## Chloe — Workstation · Study Candidacy Reminder · Appeal · Dismissal

Scope in `docs/scope/chloe.md`, summarised from her FYP I interim report.
Requirements came from one interview with **Mr. Amirul Hariz Yunus** of CGS on
23 June 2026. Four keys proposed in `docs/module-keys.md`, none claimed yet.

**Read the overlap section before starting.** Modules 2–4 are the same three
shapes as Norhanis' RPD reminders / appeals / dismissals, applied to study
candidacy rather than the RPD milestone.

- [ ] **Workstation Management** (`workstation`) — the odd one out: *not* an
      approval chain, so it will not use `WorkflowEngine`. Closest existing
      pattern is Attendance's non-workflow half.
  - [ ] `workstations` table (seat id, location, occupant, locker key) and a
        live seat map students pick from
  - [ ] Concurrency: two students choosing the same seat at once — one gets a
        confirmation, the other a rejection. Needs a unique constraint plus a
        transaction, not a check-then-write.
  - [ ] Locker key issue / return tracking, the thing that currently has no
        follow-up at all
  - [ ] CGS manual override for exceptional cases

- [ ] **Study Candidacy Reminder** (`candidacy_reminder`)
  - [ ] `candidacies` table — **the same table Norhanis' RPD module needs.**
        Build it once, together.
  - [ ] Daily scheduled command; reminders monthly from 3 months before expiry
  - [ ] **Record what was sent** — the as-is process keeps no record at all,
        and this is the one thing CGS explicitly asked for
  - [ ] Stop conditions: appeal submitted, softbound approved, student
        inactive or dismissed

- [ ] **Study Candidacy Appeal** (`candidacy_appeal`) — Supervisor → Programme
      Chair → CGS verification → Dean of PGR
  - [ ] Enforce the **twelve-month maximum appeal duration** in code
  - [ ] Recalculate the deadline on the Dean's approval
  - [ ] **Needs the "return with comment" outcome** the engine does not have.
        Jason's Hardbound review had the same gap and shipped around it — a
        return is a rejection plus a cloned resubmission (see his section) —
        so this is now the one chain waiting on the Core change.
  - [ ] Confirm whether "Programme Chair" is the existing `chair` role

- [ ] **Dismiss Exceeded Study Candidacy** (`candidacy_dismissal`)
  - [ ] Generate the candidate list from candidacy records for CGS to confirm
  - [ ] Submission to Registry stays manual and out of scope; automate only
        the student notification after Registry confirms

- [ ] **Out of the team's stack:** her report specifies Power Automate / n8n,
      Copilot Studio and Outlook. The scheduled checks map onto Laravel's
      scheduler and the mail onto the existing path; **the AI Academic
      Guidance Assistant has no home in the current stack** and needs a team
      decision.

---

## Haziq — GRA · GA · Stage Gates · Allowance Eligibility

Scope in `docs/scope/haziq.md`, summarised from his FYP I interim report.
Folder `app/Modules/Haziq/` created 2026-09-12 with the standard scaffold.
Two keys proposed in `docs/module-keys.md`, neither claimed yet.

**Read the overlap section before starting** — this scope collides with
modules Nureen has already built and shipped.

- [ ] **GRA Application** (`gra_application`) — Admin/GRS Exec → Supervisor →
      Senior Director
  - [ ] `gra_details` table + Sections B–F (Project Details, GRA Details,
        Academic Qualification, Working Experience, Publications)
  - [ ] Document metadata (type, upload date, version, status) per application
  - [ ] Reminder Engine for missing or failed documents
  - [ ] **Two-status supervisor stage** ("In Process" → "Settled") — the
        engine has one positive outcome per stage, so this is either two
        `Stage`s with the same role, or a detail-table column. Two stages is
        almost certainly right and needs no Core change.
  - [ ] Offer Letter + Admission Letter on approval, via
        `DocumentStore::storeGenerated()` — already exists, do not re-implement

- [ ] **GA Application** (`ga_application`) — Admin/CGS eligibility →
      Research Centre interview → Admin/CGS decision
  - [ ] `ga_details` table + interview result (Pass/Fail + notes)
  - [ ] **`Research Centre` is not a role yet** — add to `Support\Role`
  - [ ] GA Offer Letter + Program Offer Letter on approval

- [ ] **Stage Gate Monitoring** — *not a `WorkflowModule`.* The engine models
      a chain of approvals that terminates; this is a recurring deadline check
      against an already-approved record. Own tables + a scheduled command,
      same shape as `supervision:remind-stalled`.
  - [ ] `stage_gates` table: student, stage number, due date, completed date
  - [ ] RPD (Stage 1) is **recoverable** — grace period, then allowance
        suspended, then **back-paid** from the month after grace ended if the
        milestone is eventually completed
  - [ ] Stages 2–4 (PhD) are **not recoverable** — grace period, then
        automatic termination, final
  - [ ] **Stage 1 IS the RPD that Norhanis' module owns.** This is a
        dependency, not a duplicate: the stage gate cannot know the RPD is
        complete unless the RPD module records it. Agree the shared
        `candidacies` / RPD-completion source before building either.

- [ ] **Allowance Eligibility** (reporting only, no submissions)
  - [ ] `allowance_payment_log` — month, student, paid/not paid, amount
  - [ ] Daily job rebuilding the eligibility list
  - [ ] **Back-payment restates history.** "Suspended, then back-paid from the
        month after grace ended" means the log is rewritten retroactively, not
        appended to. Model this explicitly before writing the job — it is the
        subtlest rule in the portal.

- [ ] **AI status chatbot** — LLM API fed live application records rather than
      a static knowledge base. No home in the current stack; see the AI note
      under Cross-cutting.

---

## Cross-module overlaps — unresolved

Every scope document was cross-read on 2026-09-12. Four genuine collisions and
two dependencies came out of it. **None is a bug today** — most of the colliding
work is unbuilt — but each one becomes expensive the moment two people write
code against it. Settle them as a team before the next module starts.

### 1. Haziq's GRA/GA vs Nureen's shipped GA modules — the urgent one

`app/Modules/Nureen` already ships and works:

| Built | Key | Chain |
|---|---|---|
| GA Extension & VISA | `ga_extension` | Supervisor → CGS Staff → Senior Director CGS |
| GA/GRA Certification Letter | `ga_certification` | CGS Staff → Senior Director CGS, Dompdf on approval |

`haziq.md` Module 1 includes **GRA Extension** through its own chain
(Admin/GRS Exec → Supervisor → Senior Director), and both his modules generate
letters on approval. These are the same real-world processes described from two
directions, and one side is already built and tested.

- [ ] **Decide:** does Haziq own the GRA/GA *application* while Nureen keeps
      *extension* and *certification*? Or does the GA/GRA lifetime move wholesale
      to Haziq and Nureen's two modules fold into it?
- [ ] Whatever is decided, `ga_extension` and `ga_certification` are already in
      `applications.module_type` on real rows. Renaming either needs a data
      migration, so prefer keeping the keys and changing the owner.

### 2. Chloe's candidacy trio vs Norhanis' RPD trio

Both have a reminder scheduler, a multi-stage appeal ending at the Dean, and a
dismissal list routed to the Registry. They are genuinely **different
deadlines** — Chloe's is overall study candidature, Norhanis' is the Research
Proposal Defence — but the machinery is near-identical.

| | Norhanis (RPD) | Chloe (Study Candidacy) |
|---|---|---|
| Reminders | 3 / 2 / 1 months before deadline | monthly from 3 months before expiry |
| Appeal chain | Supervisor → Chair → Non-Exec CGS → Dean | Supervisor → Programme Chair → CGS → Dean |
| Dismissal | Non-Exec CGS → Dean → Faculty → Registry | CGS confirms list → Registry (manual) |

- [ ] **Decide:** one shared candidacy/deadline engine that both configure, or
      two independent implementations? A shared `candidacies` table is the
      minimum — both need programme type, start date and a computed deadline.
- [ ] Confirm whether "Programme Chair" (Chloe) and "Chair of Department"
      (Norhanis) are the same person, i.e. the existing `chair` role.

### 3. Five people claim a CGS dashboard

`nureen.md` (verification queues + at-risk alerts), `norhanis.md` (Chart.js
bottleneck view), `hani.md` (examiner availability + re-viva progress),
`jason.md` (§5.3 CGS Non-Exec / Senior Exec), `chloe.md` (operational
dashboard: pending appeals, reminder status, workstation occupancy).

**One CGS dashboard is now built** (2026-09-12) to Nureen's documented slice.
It is registry-driven, so a new module's queue appears in the Applications
tree, the Workload donut and Pending Actions automatically.

- [ ] **Decide:** does everyone else add a *panel* to the existing dashboard,
      or does each module get its own screen? Panels are the cheaper answer and
      the layout already supports them.
- [ ] Same question for the **Admin Dashboard**, claimed by `hani.md`,
      `jason.md`, `norhanis.md` and `technical.md`, and built by nobody.

### 4. Two AI assistants, neither in the team's stack

- `chloe.md` — AI Academic Guidance Assistant (Microsoft Copilot Studio),
  answers policy/procedure questions. Explicitly makes no decisions.
- `haziq.md` — status chatbot (LLM API) fed the student's live records.

Both sit outside Laravel · MySQL · Dompdf · SMTP.

- [ ] **Decide:** one assistant with two intents, or two? And which provider —
      this is the only part of the system with no agreed technology.

### Dependencies (not collisions, but ordering constraints)

- [ ] **Haziq's Stage 1 *is* Norhanis' RPD.** The stage gate cannot know the
      milestone is complete unless the RPD module records completion. Norhanis
      must land the RPD data model before Haziq's stage gates can work.
- [x] **Jason's Appointment Letters and Hani's examiner pool — resolved as
      two lists on purpose.** Appointment Letters has its own
      `appointment_examiner_pool` (kept at "Examiner List" by Chairs and CGS)
      rather than reading Hani's `examiners`: hers serves her nomination and
      conflict-detection chain, and a column change there must not be able
      to break a letter. Nominations snapshot the chosen rows, so neither
      list can rewrite a letter already issued. If the team later wants one
      shared list, the snapshot means Jason's side can switch source without
      a data migration.

---

## Cross-cutting

### Sidebar completeness, audited per role (2026-09-17)

Checked by listing every GET route each role passes the middleware for and
diffing it against what the sidebar actually renders. The Chair was the one
audited in full; the rest fell out of the same query.

- [x] **The Chair's sidebar is complete.** Five queues (Travel, Student
      Claims, Publication, RPD Extension Appeal, Hardbound Submission), three
      actions (Nominate Examiner Panel, Examiner List, My Signature), plus
      Dashboard, Notification, Documents, Calendar, Help and Support and the
      profile footer. That is every screen a Chair can open. The queues come
      from `Role::CHAIR` stages in five workflows and the actions from two
      `ProvidesLinks` implementations, so none of it is hardcoded and Chloe's
      candidacy appeal will appear on its own if she uses `Role::CHAIR` (see
      her overlap note, `chloe.md:58`).
- [x] **Queues collapse into a tree at four or more** (`sidebar-approver-nav`).
      The spread is fixed by the modules, not by preference: supervisor 7,
      academic_exec 6, chair 5, dean_pgr 4, senior_director_cgs 3, and
      manager_cgs, senior_exec_cgs, faculty and registry 1 each. A flat seven
      pushed Notification and Calendar off the fold; a tree around a single
      queue is a click in front of one link. The tree opens itself when you
      are on one of its pages. Actions stay flat at every count: two or three
      unrelated tools are not a list. Non-Exec CGS owns 11 queues and 8
      actions and does not use this partial at all — `sidebar-cgs-nav` has
      its own trees.
- [x] **`/settings` was reachable by anyone signed in.** It is offered in the
      sidebar to CGS and the administrator only, and everything it describes
      (notification preferences, the attendance threshold, reminder timings)
      is system-wide configuration, but the route carried no role middleware,
      so a student could open it by URL. Now gated to `Role::cgsTeam()` plus
      `ADMIN`, matching who is offered it, with a test.
- [x] **Closed 2026-09-17.** Their own filed nominations are on the Chair
      dashboard now. See Jason's section.
- [x] **The Chair's two examiner entries are not redundant — the round trip
      between them was (2026-09-17).** Asked whether *Nominate Examiner Panel*
      and *Examiner List* are the same job listed twice. They are not: the
      list is the only screen that can add, remove or reinstate a
      `PoolExaminer`, and dropping it from the sidebar would leave removal
      with nowhere to live. What was actually wrong is that "Add the examiner
      first" was a one-way trip — a plain link off the nomination form, and
      `ExaminerPoolController::store()` then redirected to the *list*, so the
      Chair lost the candidate and every row already picked and had to
      re-enter the lot. Now the link carries `?return=nominate`, `store()`
      honours it (re-checking the role, because CGS keeps the same list and
      cannot open a Chair-only route), and the panel is saved to
      `sessionStorage` on the way out and restored once on the way in. No
      drafts table, no session key, no resume route.
      **The real duplication is one level down and already settled:** two
      examiner lists exist — Hani's `examiners` with its 90-day gap state
      machine, and Jason's `appointment_examiner_pool` — see the dependency
      note above. Worth naming the price out loud, though: an examiner added
      for a panel gets **no eligibility check at all**. Hani's on-gap /
      assigned / unavailable states do not reach Jason's list.
- [x] **Two forms became wizards, one did not (2026-09-17).**
      **Hani's Add Examiner** (`examiner_admin/form.blade.php`) is now two
      steps — the external-details migration landed the same day and took it
      to thirteen fields, past the eight `docs/conventions.md` puts the line
      at. The external block is `hidden` **and** `disabled` for an internal
      examiner now: a disabled fieldset submits none of its controls, so a
      half-typed external record cannot reach the controller, and the
      generated review cannot list values that will not be saved.
      **Jason's Add an Examiner** is two steps as well — 6 fields, so below
      the line and left alone by the 2026-09-15 sweep, but asked for. It
      needed the page restructured first: `form-stepper` re-casts the whole
      `.card` it finds the form in, so with the table still in that card the
      page heading and "Step 1 of 3" ended up hoisted above the full list
      with the wizard appended underneath.
- [x] **Examiner List split into two pages (2026-09-17).** Asked whether it
      should be tabs (list / add) or a list with an Add button to its own
      page. **Two pages**, `appointment-letter.examiners` and
      `…examiners.create`, which is what Hani's examiner pool already does
      (`/examiners`, `/examiners/new`). Adding is an action, not a second
      view of the list, so a tab strip labels it wrong; a tab holding a form
      throws away your typing the moment you look at the list; and one
      stacked page is the layout problem above. It also makes the return trip
      better — "Add the examiner first" now points at the *form*, because by
      the time you click it you already know they are not on the list.
      The list became a real list screen while it was open: full width rather
      than a 680px card, `.data-table` in a bordered well, `.status-badge` for
      Internal / External / Removed, initials avatars, and the counts as one
      quiet line instead of four stat cards — this is a handful of rows, not
      a dashboard.
- [x] **My Signature got a layout pass, not a wizard (2026-09-17).** The old
      page had the hint paragraph sitting on top of the file input (a `-8px`
      margin meant for a different context) and a bare `<input type="file">`.
      Now: a full-width drop target (a `<label>` wrapping a hidden input, so
      it still works with script off), and a picked file previews on the same
      white plate the Confirmation prints it onto — you can see the scan is
      the right way up before saving rather than after. The plate stays white
      in dark mode on purpose; black ink on a dark surface disappears.
      **The preview reads as a `data:` URI, not a `blob:` one.**
      `ContentSecurityPolicy` sets `img-src 'self' data:`, so
      `URL.createObjectURL` would have been refused and the preview would
      have silently never appeared. `FormsTest` guards this.
      **My Signature was left alone.** One file input; a wizard would make it
      "Step 1 of 2" where step 2 reviews a filename, under a line claiming it
      goes to an approver — which the page itself contradicts ("Upload it
      once; replace it any time").
- [x] **`form-stepper` gained one option, in `Core` (2026-09-17).** The
      generated review's line of prose was hardcoded to "Once submitted it
      goes to the first approver and you cannot edit it", which is true of
      every application form and false on the two screens above — neither
      files anything. `data-stepper-review="..."` on the `<form>` overrides
      it; the default is unchanged, so no existing form moves, and it is set
      with `textContent`, so an override cannot inject markup. Second line in
      the same file: the review skipped `field.disabled`, which is the
      control's *own* attribute only — a control switched off by a
      `<fieldset disabled>` reads back `false`. Now `:disabled`, which is a
      strict superset, so again nothing existing changes.
      **Both are Core edits and affect all six people**, which is why they
      are recorded here rather than buried in a module. Reverting either is a
      one-expression deletion.

### Two dashboards, two chart types (2026-09-17)

- [x] **The Supervisor's chart is a doughnut, not the Chair's bar.** Every
      dashboard reaching for the same chart type is how a portal ends up
      looking like one report repeated. The two screens ask different
      questions and now draw them differently: the Chair's is about a **desk**
      (how long has work been sitting — four ordered bands, which is a bar),
      the Supervisor's is about **people** (is my cohort healthy — parts of a
      whole, which is a ring with the headcount in the middle).
      Bands come from `StudentDashboard::attendanceBands()`, so a student
      reading "Good" on their own dashboard is counted as Good here; two
      thresholds that have to agree are two that can disagree.
      **"Not recorded" is its own slice**, not a dropped row: a supervisor
      whose candidates have no attendance on file should see that rather than
      a chart quietly describing three of their twelve students.
      Datalabels are on for the bar and off for the ring, which is the
      distinction `chartjs.blade.php` already documents — numbers stamped
      across four slices are noise.
- [x] **The doughnut panel fits rather than scrolls.** A ring and four legend
      rows are a known, fixed amount of content, so the panel is sized to
      hold it: the legend keeps its height and the ring takes what is left,
      shrinking with the panel (charts.css already forces the canvas to 100%
      of its box, so sizing the box is the whole job).
      That needed a second answer to the question the one-screen layout asks
      every panel, so there are now two and a panel declares one:
      `approver-scroll` for a list of unknown length, `approver-fit` for
      content that is known and must not scroll. `PageShellTest` accepts
      either and rejects neither.
      **Found while proving that guard bites:** it was a `str_contains` over
      the whole file, so the words appearing in a partial's own explanatory
      comment satisfied it. It matches inside a `class="..."` attribute now.
      A guard a comment can satisfy is not a guard.

### The approver dashboards: a layout bug and the missing chart (2026-09-17)

- [x] **Panels were painting over each other.** `.sdash` is locked to the
      viewport at >=1201x700 (`dashboard-student.css`) and makes that work
      with `grid-template-rows: ... minmax(0, 1fr)` plus panels that scroll
      internally. The Chair and Supervisor screens were given
      `display: flex`, which throws those rows away **while the height
      stays** — so four children were squeezed into one viewport and
      overflowed on top of one another.
      **Fixed by making the lock work rather than by lifting it** — a
      dashboard belongs on one screen when the screen is big enough, which is
      the same requirement the student and CGS dashboards are built to. Flex
      is kept and made to do the grid's job, which suits these two better:
      the alert strip is conditional, and `flex: 1 1 0` on the panel rows
      shares out whatever the banner, the cards and sometimes the alerts
      leave, with nothing having to know how many children there are. An
      explicit `grid-template-rows` would need re-counting every time a strip
      appears or disappears.
      Five panels split **three then two**, the shape the CGS screen already
      uses; `auto-fit` works the columns out from the child count, so neither
      row declares a number. Panels are a header that stays and a body that
      scrolls, the bodies named `*-scroll` so `dashboard-states.css` hides
      the bar — a visible scrollbar inside a one-screen layout is exactly
      what that rule exists to stop. Rows `stretch` rather than `start`, or a
      short panel leaves the rest of its row empty, and an empty panel
      centres its message in the space it owns.
      Below 1201x700 the lock lifts and the page returns to natural height:
      no layout fits five panels into 500px and stays readable.
      Guarded — `PageShellTest` fails on a panel with no `*-scroll` body.
- [x] **What actually broke it, twice: the cascade, not the layout.**
      `layout.css` is linked **before** the dashboard sheets, and
      `dashboard-student.css` has `.sdash { display: grid }`. A single-class
      `.sdash-chair { display: flex }` written in `layout.css` therefore lost
      at equal specificity and never applied: the container stayed a
      three-row grid, five children landed in squashed implicit rows, and
      every panel in the first row collapsed to its header because
      `.sdash-card-body` is `flex: 1 1 auto` and shrinks to nothing when its
      parent does. Two rounds of fixing the *symptom* followed. Written
      `.sdash.sdash-chair` (0,2,0) it works whatever the sheet order.
      Found in the same pass: `.approver-stats { grid-template-columns }` was
      **dead code** — `.sdash-stats` is a wrapping flex row, and the property
      does nothing on a flex container. The cards look right either way,
      which is exactly why it went unnoticed.
      Guarded: `PageShellTest` now fails on any `.sdash-*` rule in
      `layout.css` that does not qualify itself. Verified by planting one.
      Also removed: `.approver-panel`'s duplicated `display: flex` and head
      rules. `.sdash-card` is already a flex column with a shrink-proof head
      and a `flex: 1 1 auto` body; the panel needed `min-height: 0` and
      nothing else.
- [x] **They had no chart, and now have the right one.** Not a count per
      module, which is what the generic approver dashboard drew and which
      across five or seven queues is a row of mostly-empty categories
      answering nothing. The question a Chair or a Supervisor actually has is
      not "which module" but "how bad is the backlog", so: **how long
      everything has waited, in four bands** — up to a week, one to two
      weeks, two weeks to a month, over a month. A fat green bar is a healthy
      desk and a fat red one is not, and it reads the same whether you own
      two queues or ten.
      Bands reuse the tones the stat cards and queue rows already use, so the
      same number is the same colour everywhere on the screen, and the
      colours come from the status tokens through `Chart.uresearchToken()`
      rather than literals, so they survive the dark theme. The figures are
      repeated as text beneath the canvas, which a screen reader can read and
      a canvas cannot.
- [x] **The counts moved onto the bars (2026-09-17).** They were a list under
      the chart, which is what made the panel need a scrollbar inside a
      one-screen layout. `chartjs-plugin-datalabels` is already loaded and
      opt-in, so this is `datalabels: { display: true, anchor: 'end', align:
      'top' }` and eighteen pixels of top padding for the tallest bar's
      number. Zero is drawn too: "none in this band" is information and an
      absent bar is not.
      The figures stay in the markup as a visually-hidden list, because a
      canvas is invisible to a screen reader beyond its label and a datalabel
      is pixels.
      **The tooltip already floats free** — `chartjs.blade.php` makes the
      `<body>`-appended one the default, so this chart only adds wording and
      deliberately sets no `external` of its own, which would pin it back
      inside a card that has `overflow: hidden`.
- [x] **Quick actions was two links for a Supervisor.** It rendered only what
      the modules declare through `ProvidesLinks`, which is three for a Chair
      and two for a Supervisor. The Core destinations every approver actually
      uses — Documents, Calendar, Notifications, My profile — are listed with
      them, module links first because those are the role's real work. Icons
      are matched by route prefix rather than defaulted, since a column of
      identical document icons is decoration rather than a signpost, and the
      list is a two-column grid where there is width for it.
      Routes are checked with `Route::has()` before rendering, so a link
      never 500s a dashboard if a route is renamed.
- [x] **And then it looked wrong, for one reason.** The list is the panel's
      flex child and fills its height, and a grid's default `align-content`
      is `stretch` — so the rows shared out all the leftover space between
      them and the two rows of links sat half a panel apart. `align-content:
      start` keeps the links their own size and leaves the slack at the
      bottom.
      **Then made tiles**, icon over label and centred, the shape the CGS
      dashboard's Quick Shortcuts already uses — asked for on the Supervisor
      screen, and applied to the Chair's too because it is one shared partial
      and two dashboards side by side should not disagree about what a
      shortcut looks like.
      Deliberately its **own** rules rather than reusing `.cgs-shortcut*`:
      those live in `dashboard-cgs.css` and their `clamp()` sizing is tuned
      to that screen's fixed-height row, so borrowing them would tie this
      panel's appearance to changes made for a different dashboard. Same
      shape, sized for this panel; `auto-fit` at an 8rem floor gives four
      across a half-width panel and two on a narrow one with no breakpoint.
      Seven tiles now — Help & Support joined the Core set. Not padded out to
      eight: every tile goes through `Route::has()` first, because a dead
      tile on a dashboard is worse than one fewer tile.
      **Then narrowed the PANEL, not the tiles.** The first pass shrank the
      buttons, which was the wrong axis: the complaint was that Quick actions
      took half the row from a panel with real content in it. Its row is
      `.approver-row-aside` now — `minmax(0, 1fr) minmax(0, 27rem)` — so the
      list beside it gets roughly two thirds. 27rem rather than the 23rem
      first tried: at the tile floor of 6.25rem that is four across instead
      of three, so seven tiles are two rows rather than three and the panel
      does not need scrolling to reach the last of them. Only described for the wide
      case, because below the one-screen threshold the row has already
      collapsed to one column.
      The earlier tile shrink stayed, which mattered beyond looks: at the first size they
      wrapped to two rows of tall tiles, and that was enough to push the
      bottom row of the dashboard past the fold on a screen the one-screen
      lock was supposed to fit. A 6.25rem floor fits all seven across a
      half-width panel, so the panel is one short row. The overflow was the
      symptom; the tile height was the cause.
- [x] **Five stat cards, not four, on both screens.** The fifth is
      **Overdue** — how many have waited longer than a fortnight.
      Deliberately not the same thing as Longest wait, which is a single row:
      a desk with one 40-day straggler and a desk with nineteen of them
      report the same longest wait and are not the same problem. Read off
      `ageingProfile()`, which is already memoised for the chart, rather than
      counted again — two queries that have to agree are two queries that can
      disagree.
- [x] Shared, so both screens get it from one partial, and
      `pendingOnMyStages()` was extracted while adding it — `oldestWaiting()`
      had the same "every stage I own" clause written out longhand. It
      returns **null** rather than an empty query for someone who owns no
      stages, because `where(nothing)` matches every pending application in
      the portal.

### The Supervisor gets a dashboard too (2026-09-17)

- [x] **Built**, and the Chair's screen refactored under it rather than
      copied. `Services\ApproverDashboard` (abstract) now holds everything a
      Chair and a Supervisor share — the queue list, the longest wait, the
      triage feed, the blocking alerts, the card destinations — and
      `ChairDashboard` and `SupervisorDashboard` add only what differs. The
      shared partials and CSS were renamed `chair-*` to `approver-*` for the
      same reason: they were never the Chair's.
- [x] **The panel that justifies a separate screen: "My candidates".** A
      supervisor is accountable for named students, not just for a desk, and
      "is one of my candidates in trouble" is a question no queue answers.
      Rows carry matric, programme and the attendance figure, flagged students
      sorted to the top so a supervisor with twenty candidates does not read
      twenty rows to find the two who need them.
      Attendance comes through `Contracts\SuppliesAttendance`, so **Core still
      names no module**; with no module bound the column is simply absent and
      the rest of the row renders.
- [x] **Four fixed cards, two about the desk and two about the people**:
      awaiting you · longest wait · my candidates · flagged at risk. A
      supervisor owns **seven** stages, so the generic screen gave them eight
      cards mostly reading zero — the worst case of that problem in the app.
- [x] **The signature alert already worked for them.** Supervisors sign the
      Confirmation too, and `ProvidesDashboardAlerts` is keyed off the stage
      roles, so it appeared with no change. That is the contract doing its job.
- [ ] **Left for the team, deliberately: the other six queues are still
      role-scoped.** "Awaiting you" on a supervisor's screen is portal-wide
      while "My candidates" is correctly theirs, and both sit on the same
      page. Supervision is the one queue already narrowed. The mechanism now
      exists — `queueFor()`'s `$scope` closure — so applying it is small, but
      it changes what every approver sees across four people's folders and
      this file says to agree it first. One word and it is done.

### A 500 on the Supervision queue, and the gap that let it ship (2026-09-17)

- [x] **`Collection::total() does not exist` on `/supervision/queue`.**
      Pagination landed in `queueFor()`, and `SupervisionController` was the
      one controller that did not just *read* the result — it reassigned
      `$queue['applications']` to a `->filter(...)`. A paginator forwards
      unknown calls to its underlying collection, so that handed back a plain
      `Collection` and the partial asking it for `->total()` was a 500.
      **Patching the type would have left it broken anyway**: filtering after
      paging reports the unfiltered total and pages over rows it then throws
      away. So the narrowing moved into the query. `queueFor()` takes an
      optional `$scope` closure applied before the count and the page.
      That closure is per-call and **does not settle** the "scope approver
      queues to the right people" decision below; it is the one concrete case
      that could not wait.
- [x] **The real gap: thirteen of the fourteen queues had no render test.**
      Only Travel's was ever rendered, which is why a 500 on Supervision got
      through a green suite. `QueueTest` now renders **every** module queue
      for **every** role that owns a stage on it — 20+ screens, asserting 200
      and the header. Dumb on purpose: a queue that throws, or a controller
      that quietly breaks the paginator contract, fails there whoever owns it.
- [x] **And the rule it was enforcing had no test either.** A supervisor
      seeing only requests addressed to them is the one place role-scoping is
      not enough, and nothing covered it, so the rewrite could have widened it
      silently. `SupervisionTest` now asserts a supervisor sees their own
      request, does not see a colleague's, and that the **count** follows the
      scoping.

### Two things the shell missed, found on the Chair's login (2026-09-17)

- [x] **Queue pages were not in the shell at all.** Redefining
      `.card-container-inline` standardised every screen that wrapped itself
      in one — and a queue page wraps itself in nothing, so it kept running
      the full width of the content area while every card page sat centred at
      `--page-max`. Two different pages side by side in the same app. Fixed
      where it cannot be forgotten: `layouts/app.blade.php` wraps
      `@yield('content')` in `.page-shell`, which now carries the cap, and
      `.card-container-inline` is only a bottom spacer. Guarded.
- [x] **Three queue views printed their guidance above the page title.** The
      partial renders the header, so a `<p>` written above the include lands
      above the heading and the screen reads headless — which is what "it's
      like cut off" was. `core::partials.queue` takes an optional `intro`
      now, rendered under the header. Hardbound and the Appeal moved their
      text in (their Blade conditionals became `match()`, since an argument
      has to be one value); Appointment Letter's workload chart moved *below*
      the list, where context on a queue belongs. Guarded.
- [x] **The Chair's stat cards did nothing.** Every other dashboard's cards
      end in a pill that goes somewhere and these were four dead figures.
      "Awaiting your decision" opens the fullest queue, "Longest wait" opens
      the queue holding the oldest row, "Panels you filed" goes to the
      nomination form. "Decided by you" renders a flat pill, because there is
      genuinely no screen for it yet — the same shape CGS and student cards
      already use for that case.

### Dashboards take the whole screen (2026-09-17)

- [x] **The cap is right for a document and wrong for a dashboard.** Asked
      whether the left/right gap is a good idea: it is two different
      questions. A page of prose or a form is *read*, and a 1900px line is
      genuinely hard to track back to the start of — `--page-max` exists for
      that. A dashboard is *scanned*; it is tiles, and capping it at 1320px on
      a 1920px monitor throws away a third of the screen and squeezes panels
      that have data in them. So: reading pages keep the gap, dashboards do
      not. One rule, `.page-shell:has(> .sdash)`, keyed off the root class
      every dashboard already has, so **no view changed**. `:has()` has been
      in every modern browser since 2023 and where it is missing the
      dashboard merely stays capped, which is the previous behaviour.
- [x] **The Chair's grids made properly fluid.** Stat cards on
      `minmax(clamp(210px, 18vw, 320px), 1fr)` so the floor grows on a wide
      screen instead of leaving four narrow cards marooned in a long row, and
      the panel rows on `minmax(min(100%, 26rem), 1fr)` so two panels split
      whatever width they get and stack cleanly on a tablet. No breakpoints.
- [x] **Closed 2026-09-17.** It was rebuilt on `.sdash`, so it takes the full
      screen like every other dashboard.

### Four identical sidebar links (2026-09-17)

- [x] **The Academic Executive's sidebar said "Re-viva Monitoring" four
      times.** A module contributes one queue per stage it owns for a role,
      and the nav printed the MODULE's label for each — so Hani's four
      consecutive AE stages became four links with nothing to tell them
      apart. Not a re-viva problem: any module with two stages on one role
      did it, and the CGS nav had the same code.
      Now `core::partials.queue-links`, shared by both navs: one link per
      module as before, **unless** a module owns more than one stage for this
      role, in which case it gets a tree of its own and the items are the
      **stage** names — Report Sent, Under Panel Review, Report Received,
      Consolidation Scheduled. Data-driven, so nothing in Core names re-viva,
      and a module with one stage is untouched.
      The group opens itself when you are on one of its stages, so the extra
      click is only paid coming in from elsewhere, and carries a count so the
      number of stages is visible while closed.
- [x] **The guide line was at the wrong depth**, which made the group read as
      though its items belonged to the list above it rather than to the
      module. It sits under the group's own label now (58px, where the
      trigger's text starts), with the stage links hanging off it at 76px.
      **The cause was a rule of mine leaking.** `.sidebar .nav-flat-queues
      .nav-tree-items::before`, written for the flat branch, is a *descendant*
      selector — so it also matched the items inside a nested group, at equal
      specificity and later in the file, and silently undid the nesting. The
      child combinator is what keeps a rule about the thing it is named for.
      Second cascade bug of this shape this session, after the single-class
      `.sdash-*` overrides; both were a rule that looked right in isolation
      and lost or won somewhere its author never looked.
- [x] **Flat-or-collapsed now counts modules, not stages.** The approver nav
      collapses into "Pending My Action" at four or more, and it was counting
      stages: the AE's six stages across three modules would have collapsed a
      sidebar that reads as three items.
- [x] **A bug no test could see**, because every one of the four links
      resolved to a real route and rendered perfectly. `SidebarQueuesTest`
      asserts the module name appears once as the group, each stage appears
      by its own name, a single-stage module stays a plain link, and the
      flat/tree decision counts modules. It also asserts re-viva still *has*
      several AE stages, so if that changes the test fails rather than
      quietly proving nothing.

### The Chair gets its own dashboard (2026-09-17)

- [x] **Built.** `Services\ChairDashboard` + `dashboard/chair.blade.php` and
      five partials, in the `.sdash` vocabulary the student, CGS and admin
      screens already share, with `BuildsPanels` so a dead panel costs that
      panel rather than the page.
      **What it replaced:** the generic approver screen renders one stat card
      per queue, and a Chair owns five stages — six cards with five zeros,
      over a five-category bar chart with one bar in it, and nowhere at all
      for the figure a Chair is actually measured on.
      **Now:** four fixed figures (awaiting you · **longest wait** · decided
      by you in 30 days · panels you filed), a blocking-alert strip, the
      queues as a linked list in place of the chart, the five rows waiting
      longest, the panels this Chair filed, and quick actions read from
      `linksFor()` so a teammate's new Chair screen appears without Core
      being edited.
- [x] **This closes "a Chair cannot see a nomination once they have filed
      it"** (Jason's section). A filed panel left their hands completely: not
      their queue, and `/applications` is students only. "Panels you filed"
      shows each one with the stage it is sitting on.
- [x] **`ProvidesDashboardAlerts`**, an optional companion to
      `WorkflowModule`, same shape as `ProvidesLinks`. Approving a hardbound
      thesis without a signature on file bounces you to the upload page, and
      nothing said so until you tried. That rule and `HardboundSignature`
      both live in Jason's folder and **Core must not import them**, so the
      module declares the alert and Core lays it out. Raised only for an
      approver who actually has something waiting, so it is not permanent
      furniture. One edit in `Jason/`, to `HardboundSubmissionWorkflow`.
- [x] **The approver dashboard was rebuilt (2026-09-17)**, which is what the
      Dean and the Academic Executive asked for and closes this item. It was
      the last screen on the old `.stat-cards-row` / `.dashboard-grid` markup,
      and its per-queue stat cards had the same problem for the AE (six
      queues) that they had for the Chair.
      **One screen for all of them**, not two more bespoke ones: the Dean,
      the Academic Executive, the Registry, the Faculty office and the Senior
      Executive each own nothing but a set of stages, and `ApproverDashboard`
      already derives a whole dashboard from that. `GeneralApproverDashboard`
      extends it and adds nothing, which is the point.
      **The AE's examiner screens still reach it** — Pending Evaluation,
      Conflict Detection, Re-viva Outcomes — because Hani's module declares
      them through `ProvidesLinks` and they land in Quick actions. Core
      building its own panel for data it cannot read would be the wrong arrow.
- [x] **Two figures added for them, both Core-only and both uniform.**
      **Finalised by you** — applications that ENDED at a decision of theirs —
      is the fifth card, and it is what matters most to a final approver: the
      Dean is the last stage on international travel and on an RPD appeal.
      Computed as "latest `approval_history` row is mine and the application
      is no longer pending", keyed on `MAX(id)` rather than a timestamp
      because two decisions can share a second.
      **Your recent decisions** is the only panel on any of these screens that
      looks backwards. Everything else asks what is waiting; this answers what
      an approver actually gets asked — what did you decide about mine, and
      when. Scoped to their own rows: it is not an audit screen, the
      administrator has one of those.
- [x] **Deliberately not on it:** anything department-wide. `WorkflowEngine::queue()`
      does not scope by department yet — it is the open team decision in this
      file — so a figure called "my department" would quietly be portal-wide.
      Better absent than wrong; that is where the department panels go once
      scoping lands.
- [x] **Two Carbon 3 bugs found while building it.** `diffInDays` is **signed
      and returns a float** there: `now()->diffInDays($past)` is *negative*,
      so the first cut of the "longest wait" tone read every wait as fine;
      and `$date->diffInDays()` is `41.000000002049`, which the queue row
      rendered verbatim as `41.000000002049d`. Both fixed, both guarded.

### The approval queue, rebuilt for a real backlog (2026-09-17)

- [x] **Queues are paged, searchable and sortable.** They were a plain
      `->get()` rendering every pending row fully expanded, each with its own
      textarea and two buttons. Fine for the two rows a demo has; an
      out-of-memory error and an unusable page for a department with a real
      backlog. Now 20 a page, oldest first (a queue is FIFO, and the longest
      wait is the one at risk), with `?q=` searching application number,
      student name and matric number, and `?sort=newest` to flip it.
      **Both changes are in two Core files** — `ApprovesApplications::queueFor()`
      and `partials/queue.blade.php` — so all **fourteen** queue controllers
      and **thirteen** queue views got it without one of them being edited.
      `applications` is a `LengthAwarePaginator` now rather than a Collection;
      `AbstractPaginator` forwards unknown calls to its collection, so the
      `->pluck('id')` every detail lookup does still works, and now only loads
      the details for the page being shown.
      This closes the "Pagination on queues" item in this section.
- [x] **A row is one line, and the decision form is behind a `<details>`.**
      Native disclosure, no JavaScript for the open/close. The line carries
      the application number, the student, the module's own one-line
      `summary()`, a file count, and **how long it has waited** — quiet until
      a fortnight, amber to 30 days, red past that. That last one is the
      triage signal the old screen had nowhere to put.
- [x] **Tick rows and decide them in one submission.** `POST /queue/{module}/decide`,
      generic in Core rather than a `decide-bulk` route in thirteen
      `routes.php` files across five folders. A new module gets it for free.
      **It does not bypass the engine**: every row goes through
      `WorkflowEngine::decide()` one at a time, which is still the only code
      that writes `status` or `current_stage`, and which re-checks the actor's
      role against the stage that row is actually on. So a student posting a
      list of ids decides nothing. Not atomic across the batch on purpose —
      one row someone else already decided must not roll back the nineteen
      that were fine. Capped at 100 a submission.
      `tests/Feature/Core/QueueTest.php`, 7 cases, including the two that
      matter: bulk-deciding rows that are not yours, and ids from another
      module.
- [x] **One Blade edit outside Core was required.** `Hani/re_viva/queue.blade.php`
      does not use the shared partial but does use `queueFor()`, so it now
      gets a paginator: `->count()` became `->total()` (count is this page
      only) and it needed `core::partials.pagination`, or every cycle past
      the first 20 would silently vanish with nothing to say so.

### The page shell, standardised (2026-09-17)

- [x] **Five page widths became one.** `.card` at 480px, `.card-wide` at
      680px, `.card.is-wizard` at 880px, list screens at `--page-max` and the
      dashboards at whatever the grid came to — two tabs in a row looked like
      two different products. Every card screen already wraps itself in
      `.card-container-inline`, so redefining that one class made every one of
      them full width and fluid at `clamp(880px, 92vw, 1320px)` with **no
      module view edited**. The 480/680 caps are overridden with two-class
      specificity, as `docs/conventions.md` requires for anything of
      Norhanis'.
      **Full width for the page, a measure for the text**: a subtitle caps at
      68ch and a form's direct-child fields at 46rem, because a 1320px line is
      unreadable on any monitor.
- [x] **`<x-core::page-header>` on every screen — 52 of them.** The pattern
      the Examiner List established: title, one line of context, actions on
      the right, stacking to full-width actions below 720px. It has a
      `subtitle` slot as well as the attribute, for the screens whose context
      line is a count or a condition.
      There were **four** heading shapes before: `<h2>` + `.card-divider`
      inside the card (28 screens), `.rpd-header` (3), `.notif-header` (2)
      and a bare `<h2>` (2). 27 of the first group were migrated by script
      after a dry run; the rest by hand. `.rpd-header`, `.notif-header`,
      `.rpd-page` and `.notif-page` now resolve to the page header and shell
      so nothing breaks mid-branch, and are deprecated.
      **Exempt, and the test says why:** login is on the guest layout, and the
      four dashboards open with the welcome banner, which is a hero rather
      than a page header.
- [x] **The stepper joined the shell too.** `.card.is-wizard` carried its own
      `max-width: 880px`, which is precisely how a stepper form and the list
      screen beside it ended up looking like two different products. It fills
      the shell now, and the **34em field cap is back** on the field column —
      it had been removed when the card shrank to 880px, and at up to 1320px a
      text input stretched across the card reads as a textarea. 46rem, the
      same measure as every other form.
      `form-stepper` looks for an `<h2>` inside the card to build its head
      band; with the title above the card there is none, so it puts the step
      counter into the page header instead. (Found while doing it: naming a
      Blade component in a `//` comment **invokes it** — Blade compiles
      component tags inside `<script>` too — and the view stops compiling.)
- [x] **Guarded.** `tests/Feature/Core/PageShellTest.php`, 3 cases: every
      signed-in screen opens with the shared header, no screen keeps a heading
      inside its card, and no module declares a page width of its own. A
      repo-wide scan, for the same reason `ContentSecurityPolicyTest` is one:
      a screen that quietly reverts renders perfectly.
- [x] **Light-mode secondary text failed WCAG AA.** `--text-grey` was
      `--grey-500` (#8A94A6), **3.06:1** on white against the 4.5:1 minimum,
      and it is what `.queue-meta`, `.field-hint` and every stat note use,
      most at `--text-sm` or smaller. That is the "greyed out, hard to see"
      report. Now `--grey-600` (#656E7E), **5.14:1**, still clearly secondary
      next to `--text-body`. Dark mode already passed at 5.74:1 and is
      untouched. One token, so every screen in the app lifted at once.

### House style, applied across every folder (2026-09-17)

- [x] **An empty state's way forward is a button now, not a hyperlink.**
      `.btn` was already defined in `layout.css` as an anchor styled like the
      primary button, with `text-decoration: none`, the hover, active and
      disabled states, the lot. **Nothing in the repo used it.** So seven
      empty-state links became buttons with no new CSS: filled `.btn` for the
      one real action (Add an examiner, on the Examiner List and on the panel
      nomination), outlined `.btn-secondary` for an alternative (Clear the
      filters, Back to the masterlist, Track this appeal, See the overdue
      list, File an appeal). A link inside a sentence stays a link — the rule
      already written above the button block in `layout.css`.
      One rule added to `Core`: `.empty-state .btn { margin-top }`, so the
      button is not flush against the text above it. That is the only CSS
      change; everything else is a class on an existing anchor.
      **Touches four people's folders** (`Core`, `Norhanis`, `Nureen`,
      `Jason`) because the ask was explicitly global. Each edit is one line
      and reverting one is deleting a class attribute.
- [x] **No em dash as a sentence connector, anywhere that renders.** Six
      places, all written during this session's work. A comma, a semicolon or
      a full stop says the same thing without reading as machine-written, and
      this is a project supervisors mark. Also swapped one `&mdash;`
      separating a supervisor's name from their department for `&middot;`,
      which is what the hardbound detail view already uses.
      **Deliberately not touched:** the en dash in a range (`75% – 84%`,
      `Jan – Mar`) and the bare em dash standing in for an empty table cell
      (`{{ $x ?? '—' }}`). Both are ordinary typography, and about 35 of the
      latter exist across four folders. Say the word if you want them as
      "N/A" or blank instead.
      **Also not touched: code comments.** They do not render, and sweeping
      them is a diff across every file in the repo. Worth doing as its own
      pass if anyone is reading the source.
      Guarded by `tests/Feature/Core/ProseTest.php`, which scans every Blade
      view in the repo — same shape as `ContentSecurityPolicyTest`'s scanner,
      and for the same reason: the screens a test happens to render are not
      all the screens there are.

### Correctness gaps in Core worth closing
- [ ] **The student sidebar drops module links.** `sidebar.blade.php` passes
      `$extraLinks` to the CGS and approver partials but not to
      `sidebar-student-nav`, so anything a module returns from
      `ProvidesLinks::links()` for a student is never rendered. Jason's
      "Resubmit Hardbound #N" links hit this after the 2026-09-13 merge and
      now live on the Hardbound Submission page instead. One-line fix:
      hand the student partial `$extraLinks` too.
- [ ] **Scope approver queues to the right people.** A supervisor currently
      sees every application at the supervisor stage, not only their own
      supervisees. `users.supervisor_id` exists but `WorkflowEngine::queue()`
      does not filter on it. Same for Chair and department. This needs one
      team decision — whether scoping is a `Stage` property or a hook on the
      module — and then a change in `Core`, so agree it first.
- [x] **Queues are paginated** (20 a page, searchable, sortable — see the
      section above). **The tracking page is not:** `ApplicationTrackingController`
      is still a plain `->get()`. A student with a long history loads all of
      it, which is a much smaller problem than the queues were but is the
      same fix. Original item: pagination on queues and the tracking page,
      both `->get()`
      everything, which is fine at seed scale and not at real scale.
- [x] **Fixed: `tests/` did not exist**, though `composer.json` mapped `Tests\`
      to it and required PHPUnit 11 — so `php artisan test` failed outright on
      a missing `phpunit.xml`. There is now a working harness: SQLite in
      memory, so it needs no Docker, no MySQL and no `.env.testing`, and can
      never touch a developer's own data. `php artisan test` — 13 tests at the
      time, 53 now, in under two seconds. Run it through Sail; see the third
      pass below.
- [x] **Content-Security-Policy on every HTML response (2026-09-12).** The
      portal previously sent none at all, so a single unescaped value reaching
      Blade would have let an injected `<script>` simply run. Now
      `Core\Http\Middleware\ContentSecurityPolicy`, plus `nosniff`,
      `Referrer-Policy` and `X-Frame-Options: DENY`.
      **No `unsafe-eval`** — nothing needs it. Chart.js 4.4.1 and
      chartjs-plugin-datalabels 2.2.0 were both downloaded and checked for
      `eval(` / `new Function`: zero occurrences in either, and none in this
      repo. If a future library appears to need it, replace the library.
      **No `unsafe-inline` for scripts** either. All 10 inline blocks carry a
      per-request nonce via the `@cspNonce` Blade directive, and the sidebar's
      `onclick` became an `addEventListener` — a nonce cannot allow-list an
      event-handler attribute.
      `style-src` *does* allow `'unsafe-inline'`, deliberately: 44 inline
      `style="…"` attributes carry real values (the gauge's arc, the donut's
      offset, per-card delays) and CSP has no nonce for style *attributes*.
      Style injection cannot execute script, so the exposure is far smaller.
      **Writing a new inline `<script>` without `@cspNonce` fails silently** —
      the page still returns 200 and the browser just refuses to run it — so
      `ContentSecurityPolicyTest` asserts every inline block on every screen
      carries the nonce, and that no page reintroduces an inline handler.
- [x] **Chart.js and its datalabels plugin now ship from `public/js`,
      not a CDN (2026-09-12).** Same files, same versions, minified from npm.
      Three reasons, in order of weight:
      **(1)** `script-src` names no origin at all now — an allow-listed CDN is
      a standing permission to run whatever that CDN serves, and jsdelivr
      hosts every package on npm. Nonce-only is the strict form, and it is
      what Chrome's own CSP guidance recommends over an allow-list.
      **(2)** The demo works on a flaky connection or none at all — worth
      having before the FYP presentation.
      **(3)** It ended a console warning: Chart.js's minified build points at
      a source map, DevTools requested it, and `connect-src 'self'` correctly
      refused. The map is 931 KB — four and a half times the library, for
      debugging Chart.js internals — so it is not shipped; the directive is
      stripped instead, with a note in the file saying how to restore it.
      The portal now loads **zero** off-origin resources.
- [ ] Password reset UI ("forgot password") — the `password_reset_tokens`
      table exists, no screens. Changing a password you already know is done;
      see the profile screen below.
- [x] **Fixed: Core named a module directly.** All three dashboard services
      imported Nureen's `AttendanceRecord` / `AttendanceRiskEvaluator` behind
      a `class_exists()` guard — Core reaching into a teammate's folder, which
      is the one thing the folder system exists to prevent.
      Core now declares `Contracts\SuppliesAttendance` and hands out
      `Support\AttendanceReading` (a readonly DTO, so Core never holds another
      module's Eloquent model); Nureen implements it in
      `Support\AttendanceProvider` and binds it from her own `ModuleProvider`.
      `grep 'App\Modules\(Nureen\|Norhanis\|…)' app/Modules/Core` now returns
      nothing but one explanatory comment. Degradation is unchanged: nothing
      bound means the attendance panels hold their skeletons.
      The same pattern is the template for the next module that wants a panel.
- [x] **Deduplicated the three dashboard services.**
      `safely()`, `unavailable()`, the per-request memo and `withTrend()` were
      written out identically in `StudentDashboard`, `CgsDashboard` and
      `AdminDashboard`, and had begun to drift — `CgsDashboard::latestRecords()`
      and `AdminDashboard::latestAttendance()` were the same query under two
      names. Now `Services\Concerns\BuildsPanels`. The three services lost 148
      lines between them with every figure unchanged.
      Also: `ModuleRegistry::labelFor()` replaces three copies of the
      "registered module label, else prettify the key" fallback, and
      `AttendanceRecord::latestPerStudent()` replaces three hand-built copies
      of the same self-join (CGS dashboard, admin average, at-risk list).
- [x] **Demo data is seeded (2026-09-14).** A teammate running `./setup.sh`
      used to get 11 accounts and empty dashboards: no attendance rows,
      nothing in a CGS queue, no notifications. `database/seeders/DemoDataSeeder.php`
      now fills every figure on all three dashboards, and
      `php artisan demo:seed [scenario]` (`--list` for the names) runs it.
      Deliberately **not** part of `DatabaseSeeder`, so `setup.sh` stays the
      fast, minimal, one-account-per-role path and the demo data is opt-in.
      **One caveat:** `DemoDataSeeder` imports models from Hani's, Norhanis'
      and Nureen's folders, which makes it the only central file that names
      individual modules. Jason, Chloe and Haziq will all want to edit it —
      expect conflicts there, and consider a per-module `demoData()` hook
      before three people edit it in the same week.
- [x] **Closed 2026-09-17, verified in `Core/routes.php`:** `/calendar` is
      `CalendarController` (a month grid built from dates the modules own,
      no events table) and `/documents` is `DocumentLibraryController` (every
      file this user may see, with search and filters). Neither is a
      `PageController` placeholder any more.
- [x] **Profile screen (2026-09-13)** — `/profile`, replacing the placeholder.
      `Http\Controllers\ProfileController`, `Resources/views/profile/show`,
      `public/css/profile.css`.
      **Nobody owned this.** "Profile" appears in none of the six scope
      documents; the nearest claims (`jason.md` §1.1 authentication, §5.5
      "User Management", `technical.md` §3 "System Administrators manage user
      roles") are all about an administrator editing *other people's*
      accounts, never a user editing their own. Built as Core, like the
      dashboards and the notification feed — but if the Users and Roles screen
      goes to Jason, this belongs beside it.
      **Almost everything is read-only, deliberately.** Name, email, matric
      number, programme, department, faculty, role and supervisor are
      administrative facts: a student who could edit `programme` could move
      their own candidacy deadline, and one who could edit `supervisor_id`
      could reassign their supervisor and redirect their own approval queue —
      that column is set by CGS approving a Supervision request and is the
      only thing that makes an appointment real. The screen shows them,
      explains that CGS holds them, and lets the user edit a contact number
      and their password. A test posts `role=admin` and `supervisor_id` at the
      contact form and asserts neither moves.
      Password change requires the current password, is written to the audit
      log (never the password itself), and regenerates the session id.
      Recent sign-ins are listed from the activity log `LoginController`
      already writes, so an unfamiliar time or IP is visible to the account's
      owner.
      **Layout:** identity card left, task-grouped cards right — the shape
      account screens converge on, where the profile card is the visual anchor
      and everything else is grouped by what the reader came to do. No tabs:
      with two groups, hiding one behind a tab costs a click and buys nothing.
      The first attempt capped itself at 1080px and left a third of a wide
      monitor empty; the identity card now takes a fixed 300px and the right
      column takes the slack, pairing into two columns above 1280px. Below
      1000px the card becomes a banner across the top rather than a cramped
      rail.
      The card carries real figures rather than a bio field nobody fills in —
      applications, in-progress count, attendance, supervisee count — each
      dropped when it does not apply, so CGS and admin see a short card
      instead of a row of dashes. Attendance comes through
      `Contracts\SuppliesAttendance`, which is the first use of that contract
      outside the dashboards and confirms it generalises.
- [x] **The top bar is pinned (2026-09-13).** `.main-content-header` is now
      `position: sticky; top: 0`. The sidebar beside it was already sticky, so
      scrolling a long page (the notification feed, the audit log, the
      profile) slid the UTP bar away while the sidebar stayed — the content
      boundary looked broken halfway down the page. Sticky rather than fixed:
      fixed takes it out of flow and its 64px would have to be paid back with
      padding every page would need to know about. `z-index: 5` clears page
      content (0–3) and stays under the chart tooltip's 9999, so a tooltip
      near the top of a chart still draws over the bar instead of being
      clipped by it. Anything else made sticky must clear 64px — the profile's
      identity card sits at `top: 80px` for exactly this reason.
- [ ] **Password reset ("forgot password") is still missing** — this screen
      only covers changing a password you already know. The
      `password_reset_tokens` table has been waiting since the first migration.
- [ ] A withdraw/cancel action for students on a pending application.
- [ ] A "return to submitter, application stays open" outcome for
      `WorkflowEngine::decide()` — currently only approve/reject exist.
      No longer blocking: Hardbound Submission ships a return as a rejection
      at the review stage plus a resubmission that clones the application,
      which needs no Core change. Still worth having if another chain wants
      a genuine re-open (Chloe's candidacy appeal), and it would let
      Hardbound drop the clone.
- [x] **Done 2026-09-17: `senior_exec_cgs` is seeded** as
      `seniorexec@utp.edu.my` (Puan Hasnah). Only Jason's Appeal Hardbound
      Submission ends there, and it had been tested against an account made
      by hand in a local database, so nobody else could rule on an appeal.
      The ruling stage stays with the Senior Executive rather than moving to
      `dean_pgr` — `jason.md` §3.3 is explicit about who deliberates.
      `ModuleContractsTest` now fails if any module's stage is owned by a
      role the seeder does not create, so this cannot recur silently for
      anyone's chain.
- [ ] **Actors named in scope documents that are not roles yet:** `GRS Exec`
      and `Research Centre` (Haziq), `Project Director` (Norhanis' Claims),
      `Faculty` (Norhanis' RPD dismissal, Jason). `Programme Chair` (Chloe) is
      probably the existing `chair`. Adding a role is one line in
      `Support\Role` by design — the work is deciding, not typing. The table
      in `docs/module-keys.md` tracks them.
- [ ] **`dac` and `panel_examiner` are declared but own no stage and have no
      seeded account.** Either a module needs them or they should go.

### Over-engineering worth cutting (audit 2026-09-15)

Whole-repo pass for complexity only — correctness and security findings live
in the third pass above. Ranked by size of cut. None of these are urgent; they
are what to reach for when touching the file anyway.

- [x] **Done 2026-09-17. `PageController` is a `const PAGES` and one
      `show()`.** The six methods each returned the same view with different
      strings. The routes keep their own paths, names and middleware — a
      single `/{page}` route was not on, because the CGS four sit behind a
      role gate the other two must not inherit — so each route passes its key
      with `->defaults('page', ...)` instead. Adding a placeholder is now an
      array entry plus a route line; replacing one with a real screen is
      deleting both.
- [x] **Done 2026-09-17.** The three queries moved into
      `Concerns\BuildsPanels` as `recentDecisions()`, `recentSubmissions()` and
      `recentEnrolments()`, plus a `mergeFeed()` that interleaves any number of
      already-mapped feeds newest-first. Each dashboard now only says how its
      own rows read — which is the part that legitimately differs. Named
      methods rather than the closure-keyed `activityFeed()` sketched here:
      six people have to grep this, and three obvious methods beat one clever
      dispatch table. Smaller than the ~35 lines estimated (about 20), but the
      drift it prevents was over *which rows appear at all*, which is the part
      that actually bit last time.
- [x] **Done 2026-09-17.** Both wrappers deleted. The survivor is
      `Concerns\ReadsAttendance::latestPerStudent()`, which is where it
      belonged — beside `attendanceSource()`, the other half of how Core
      reaches attendance — under one memo key instead of two.
- [x] **Half-closed by `tokens.css` (2026-09-15).** The *values* now have one
      home, so the sheets can no longer disagree about what "the error red" or
      "a gap" is. What is below still stands for the *selectors*.
- [~] **Mostly withdrawn on inspection, 2026-09-17.** Counting selectors
      made this look worse than it is. `.sdash-stat` appears in four sheets
      and `.sdash-card` in three, but they are not competing definitions of the
      same properties — they are different *aspects* layered on one class:
      `dashboard-student.css` owns the box, `dashboard-gauge.css` adds the rise
      animation and hover transition, `dashboard-states.css` adds
      `position: relative` for the skeleton overlay, `dashboard-admin.css` adds
      hover z-index. That is ordinary CSS layering, and the split is closer to
      component-shaped than the name of each file suggests. **What does stand:**
      `dashboard-states.css:79` and `dashboard-admin.css:353` both set
      `position: relative` on `.sdash-stat`. That one is *not* deletable
      either — the admin rule sets it on five selectors and only `.sdash-stat`
      is covered elsewhere, so removing it would break the other four. It now
      carries a comment saying so. The load-order dependency in
      `partials/stylesheets.blade.php` is real either way. No restructure
      warranted; a big-bang rewrite here would risk visual regressions nothing
      in the test suite would catch.
- [x] **Done 2026-09-17.** Both deleted, along with their entries in `all()`
      and `label()`. Nothing referenced either one. Whoever needs a DAC or a
      panel examiner adds the line back in the commit that uses it.
- [x] **Done 2026-09-17, and again 2026-09-17 (22 of them).** Verified first
      that every path in `ModuleServiceProvider` is guarded by
      `File::isDirectory()` or `File::exists()`, so a missing `Workflows/` or
      `Models/` is a no-op, not a warning. Chloe's and Haziq's folders stay
      claimed in git by their `ModuleProvider.php`, `routes.php` and
      `README.md` — the sub-folders appear when their first real file does.
      **Git cannot store an empty directory**, so this was never a repo
      change and there is nothing to commit: the cleanup only ever empties
      one working tree, and the folders come back in anyone else's checkout
      the moment a tool recreates them. If `find app/Modules -type d -empty`
      prints anything on your machine, `-delete` it and move on; do not
      re-file this as a regression.
- [x] **Done 2026-09-17 — the custom date picker is gone, −647 lines.**
      `core::partials.date-picker` was 380 lines of JS drawing a calendar panel
      over the native `<input type="date">`, plus 267 lines of `.dp-*` CSS in
      `layout.css` §10c. Its own header gave the reason: "on the dark theme
      that panel arrives as a bright rectangle". That stopped being true when
      `tokens.css` started declaring `color-scheme: dark` (lines 256 and 298) —
      the browser's own picker now matches the theme for free. What was left
      was restyling a 12px glyph. The native input was always the source of
      truth, so deleting the layer changes nothing that posts, validates or
      degrades.
- [ ] **`maatwebsite/excel` costs 7.9MB** (1.2M plus 6.7M of phpoffice) and is
      used by three files, for reading .xlsx uploads and writing the .xlsx
      template. `fgetcsv`/`fputcsv` cover the CSV path, which
      `nureen.md` describes as the real ingestion route. **Not cut — this
      removes a feature, not just complexity.** CGS works in Excel, and
      dropping .xlsx is Nureen's call, not an audit's.
      **Re-examined 2026-09-17 and still not cut.** Asked to patch every
      over-engineering item in the same pass as completing Nureen's modules,
      which is the contradiction: `.xlsx` upload and the `.xlsx` template are
      two of those features, `nureen.md` asks for them, and CGS exports from
      UTrace into Excel. Deleting a working feature is not a complexity fix,
      and it is not an audit's call — it needs Nureen to say the CSV path is
      enough.
- [x] **Done 2026-09-17. `laravel/tinker` is in `require-dev`.** It is a REPL;
      nothing ships it. Moved with `composer update laravel/tinker` so the
      lock moved with it — `laravel/tinker` and `psy/psysh` both sit in
      `packages-dev` now, 89 prod / 38 dev, and `composer validate` passes.
      No version changed, so this is a section move, not an upgrade. The
      stale `TinkerServiceProvider` line in `bootstrap/cache/packages.php` is
      harmless: that directory is git-ignored and discovery re-runs on
      install, so a `--no-dev` deploy never sees it.
- [x] **Done 2026-09-17.** `Console\Commands\SeedDemoData` re-implemented a
      scenario check `DemoDataSeeder::run()` was already doing — the same
      `array_key_exists` and a near-identical message, eleven lines apart from
      their original. Deleted the copy. The surviving one now calls
      `$this->command->fail()` instead of `error()` + `return`, so a bad
      scenario aborts with a non-zero exit code down *both* entry points;
      `db:seed --class=DemoDataSeeder` used to print the complaint and then
      report success. `--list` and the `--class=` shortcut are why the command
      exists and both stay. Net −9 lines, and `Core\DemoSeedCommandTest` keeps
      the exit code from quietly regressing.

### Team
- [~] **Admin module** — the dashboard, sidebar and audit log are built; the
      screens behind them are placeholders. The one that matters is **Users
      and Roles**: roles can still only be set in the seeder or phpMyAdmin,
      and it is the administrator's core job per `technical.md` and
      `jason.md` §5.5. Somebody needs to own it.
- [ ] Nine admin/CGS screens remain honest placeholders: Students,
      Applications, Attendance (x2), Reports (x3), Users and Roles, Document
      Repository. Each names what is missing; several need only a query and a
      table, since the data already exists.
- [~] Automated tests — the harness exists and **102 pass** (98 feature, 4
      unit), covering the parts that break quietly: both authorisation locks,
      approve/reject outcomes, Travel's conditional routing, the
      `stages(null)` superset, that every registered module's routes actually
      exist, and the attendance contract including its degradation path, plus
      Attendance's import, Supervision, Certification, Claims, Publication,
      Examiner Nomination, Re-viva and RPD's three flows.
      Per owner: Core 34 · Norhanis 24 · Nureen 22 · Hani 14 · Jason 4.
      **Still wanted (re-checked 2026-09-17):** Conflict Detection has no
      feature test of its own, and neither does `DocumentStore`'s allow-list.
      Jason is no longer at zero, but his 4 are render tests of his forms:
      the signature gate, the resubmit guard and the appeal's once-only rule
      are still unguarded. GA Extension and Attendance Appeal came
      off this list the same day — `GaExtensionTest` (6) and
      `AttendanceAppealTest` (5) — which is what took Nureen from 11 to 22. Travel is
      no longer on this list — `TravelTest` covers it with six. Each owner
      writing one for their own module is the cheap way to get there —
      `tests/Feature/<You>/` is where it goes.
- [ ] Deployment: hosting, real SMTP, `APP_DEBUG=false`, `php artisan
      config:cache`, a queue worker running as a service.
- [ ] UTP Single Sign-On (listed as future in `technical.md`).
- [ ] System Usability Scale evaluation — the agreed measure in `technical.md`.

---

## Suggested order

1. Get it running and walk one chain (everyone, together, once).
2. Nureen builds Supervision early — it populates `users.supervisor_id`,
   which the queue-scoping fix then depends on.
3. Agree the approver-scoping design and make that one Core change.
4. ~~Norhanis does Publication and Claims — both are quick with the engine.~~
   Done 2026-09-15 — see her section above. RPD is all that remains of her scope.
5. ~~Hani closes the examiner lifecycle before touching re-viva.~~ Done —
   see her section above.
6. ~~Nureen settles the UTrace data source before starting Attendance.~~ Done
   — CSV upload, see her section above.
7. ~~RPD and Re-viva last — they are the two hardest, and both need scheduled
   commands.~~ Re-viva is done (see Hani's section); RPD is the one large
   piece still outstanding, and still needs a scheduled command for its
   3/2/1-month reminders.
8. Jason's three chains are built, and every open item in his section was
   closed on 2026-09-17: the `senior_exec_cgs` account is seeded, the appeal
   chain's prose was corrected to match the code, and his three rules have
   tests. The "return to student" engine question was settled without a Core
   change (see his section).
9. **Before Chloe or Haziq writes any code, hold one meeting** and settle the
   four overlaps above. Haziq's is the urgent one — it collides with modules
   that already exist and have rows in the database.
10. Chloe should start with **Workstation Management**. It is the only part of
    her scope that overlaps with nobody, so it is unblocked by that meeting,
    and it is a good first module because it is not an approval chain.
11. The **"return with comment"** engine outcome is now needed by Chloe's
    candidacy appeal. Jason's Hardbound review shipped without it — a return
    is a rejection plus a cloned resubmission (see his section) — and could
    drop the clone once it exists. One design decision, one Core change.
