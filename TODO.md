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
| Hani — Examiner pool + Nomination, lifecycle closure, admin screen, conflict detection T2, Re-viva | done |
| CGS dashboard (5 stat cards + 5 live panels) | done |
| Admin dashboard (5 cards + 4 panels, system health) | done |
| Notification feed (`/notifications`) | done |
| Audit log (`/admin/audit-logs`, spatie/activitylog) | done |
| Jason — Hardbound Submission · Appeal · Appointment Letters | done |
| Chloe — Workstation · Candidacy Reminder / Appeal / Dismissal | scoped, not started |
| Haziq — GRA · GA · Stage Gates · Allowance | scoped, not started |
| **Cross-module overlaps** | **4 unresolved — see below** |
| Automated tests | 71, covering the engine, the seams, the CSP, the import, the profile, RPD’s three flows, Travel’s branch, and Nureen’s and Hani’s chains |
| **Runs end to end** | yes — verified 2026-09-09, re-verified 2026-09-12 |
| Last reviewed | 2026-09-15 — Hani's and Norhanis' merges, see third pass |

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

- [ ] **Still open from that pass:** `WorkflowEngine::decide()` queues the
      notification *inside* its DB transaction, so a broker outage turns a
      valid approval into a rollback. Move the `notify()` after the commit
      (or onto `DB::afterCommit()`) — an approval is the durable thing, the
      email is best-effort. This is what turned a config mistake into data
      loss, so it is worth closing regardless.
- [ ] **Still open:** the approver dashboard's "Recent activity" table is
      unscoped — it lists the 8 most recently updated applications for every
      module the role owns a stage in, with no filter on student, supervisee
      or department, so a supervisor sees other supervisors' students by name
      and status. Same theme as the queue-scoping gap below, different file
      (`DashboardController`).

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
- [ ] Run the suite through Sail (`./vendor/bin/sail artisan test`), which is
      what the README documents — the app container ships `pdo_sqlite`. A bare
      `php artisan test` on an Ubuntu host fails with `could not find driver
      (sqlite)` until `sudo apt install php8.3-sqlite3`; that is a host
      convenience, not a project requirement.
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
- [ ] **`PublicationController.php:61` — 500 instead of a validation error.**
      `count($request->input('authors', []))` runs *before* `validate()`, so a
      posted `authors=foo` throws an unhandled `TypeError` and the student gets
      a blank 500. Fix is one cast: `count((array) $request->input('authors', []))`.
      (Norhanis)
- [ ] **`ClaimsController.php:73` — `claim_balance` can go negative.**
      `less_cash_advance` is validated `min:0` but never against the summed
      total, so an advance larger than the claim stores a negative balance and
      walks it through all four approvers. (Norhanis)
- [ ] **`ExaminerNominationController::tieUpExaminers()` fires on any approval,
      not the final one.** Harmless while the chain has one stage — but
      `ExaminerNominationWorkflow`'s own docblock plans a second stage for
      touchpoint 2, and the day it lands both examiners get tied up for 180
      days at the *first* approval. Guard it with
      `$application->refresh()->status === Application::STATUS_APPROVED`. (Hani)
- [ ] **`ReVivaController.php:34` — N+1 on the create form.** `latestCycle()`
      runs one query per student, so `/re-viva/new` costs one query per
      postgraduate in the system. One `whereIn` keyed by student replaces the
      loop. (Hani)
- [ ] **`public/css/uresearch.css:504,515` — two unscoped element selectors.**
      `form > button[type="submit"]` now centres the login button and the
      submit on all five of Nureen's module forms; `input[type="file"]`
      restyles every file input in the app. `conventions.md` asks for at least
      two classes on anything added to a shared sheet. (Norhanis)
- [x] **Done: the publication "fix" migration is deleted** — see the first
      item in this list. It was not the tidiness problem it looked like; it
      was fatal on every database built from scratch. (Norhanis)
- [ ] Minor: `items` and `authors` are validated `array|min:1` with no `max`,
      so a crafted post can insert unbounded rows. `ExaminerAdminController`
      pulls the whole examiner pool into memory and filters in PHP — fine at
      FYP scale, worth knowing.

- [ ] Confirm `laravel/framework: ^12.0` in `composer.json` is still the
      version the team wants; bump if you prefer newer.
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
- [ ] **The four short forms gained the least.** Three or four fields behind a
      two-step wizard is an extra click for a review of something already on
      screen; the three long ones are where the win is. Kept for consistency
      and because a confirmation before something goes to four approvers is
      defensible — but if the team dislikes it, reverting one is deleting its
      `<fieldset class="fstep">` wrapper and the include. Worth asking the
      other five what they think after a demo.
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
- [ ] **No custom date picker, deliberately.** The panel is browser chrome and
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
- [ ] **That plate is 2.7 MB against the light one's 138 KB** — 20x, on every
      dark-mode page load. It is 2400x1792 for a `background-size: cover`
      layer, so it can be resized and recompressed well under 300 KB with no
      visible loss (GD is available in the app container). Worth doing before
      the FYP demo, especially on conference wifi.
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
- [x] Verified in both themes, headless at 1440x950: login, student dashboard,
      CGS dashboard, admin dashboard, application tracking, travel form,
      attendance upload, at-risk list, notification feed, profile and the
      audit log. Every screen in the app now has been looked at, not assumed.
- [ ] `docs/conventions.md` now has a **Design** section: the token groups,
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
  - [ ] **Open:** the dismissal's termination email is not written yet. The
        Registry stage closes the candidacy and timestamps it, but the actual
        notification to the student is still to do — `RpdDeadlineApproaching`
        is the pattern to copy.

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

- [~] **Supervision** — supervisor appointment requests
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

- [~] **GA/GRA Certification Letter**
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
declared but unused by any built module, and unseeded — see the cross-cutting
note below.

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
        instead of overwriting them. Which stage the rejection happened at
        is what separates the two endings: returned at `cgs_review` and the
        student resubmits, rejected at `cgs_approve` and their only route is
        the appeal chain. A true "returned, still open" outcome is still
        worth having in Core — see Cross-cutting — but nothing here is
        blocked on it now.

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
        erase, so an upheld appeal instead unlocks the resubmission form for
        the submission it names. Only two things make a submission
        resubmittable: CGS returned it at `cgs_review`, or an appeal against
        it was upheld.
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
  decisions (`jason.md` §1.4) — `approval_history` already logs
  approve/reject with who and when; logging views and uploads and giving
  admin a search UI over it is a Core change, not something to build inside
  this module folder.
- The Admin Dashboard (`jason.md` §5.5) — already listed, unowned, under
  "Team" below.

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
- [ ] Pagination on queues and the tracking page; both currently `->get()`
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
- [ ] Give `/calendar` and `/documents` real screens. Both are still
      `PageController` placeholders that the dashboard links to.
      (`/notifications` and `/profile` are done — see below.)
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
- [ ] Seed a `senior_exec_cgs` test account — no seeded user has this role.
      Only Jason's Appeal Hardbound Submission ends there now (Hardbound
      Submission itself was collapsed to the Non-Exec alone). The appeal was
      tested against an account created directly in the local database, so
      **the seeder still needs this line** before anyone else can rule on an
      appeal — or the ruling stage could move to `dean_pgr`, who is seeded:
      `$this->user('Encik Rahim Senior Exec', 'seniorexec@utp.edu.my', Role::SENIOR_EXEC_CGS, ['department' => 'CGS']);`
      Left to whoever owns the seeder rather than edited from a module folder.
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

- [ ] **`Core\Http\Controllers\PageController` — 8 methods, one shape.**
      Every one returns `view('core::pages.placeholder', [...])` with a title
      and a description; nothing else differs. A `const PAGES = [slug => [title,
      description]]` and one `show(string $page)` method behind a single
      `/{page}` route does the same job in about a third of the 96 lines, and
      deleting a placeholder becomes deleting one array entry instead of a
      method plus a route. Do it when the first of these gets a real screen.
- [ ] **`recentActivities()` is written twice**, ~40 lines each in
      `CgsDashboard` and `AdminDashboard`. The three queries behind them are
      identical (`ApprovalHistory` latest-N, `Application` latest-N submitted,
      and — admin only — `User` latest-N students); only the row text and
      whether enrolments are included differ. A shared `activityFeed()` in
      `Concerns\BuildsPanels` taking the mapping closures per source would cut
      ~35 lines and stop the two feeds drifting the way the last pair did.
- [ ] **`CgsDashboard::latestRecords()` and `AdminDashboard::latestAttendance()`
      are the same one-line wrapper under two names** — both
      `remember(<key>, fn () => $source->latestPerStudent())`, differing only in
      the memo key. This is the exact duplication the 2026-09-12 dedup pass was
      supposed to end; it survived because the wrappers were left behind after
      their bodies moved into `AttendanceRecord::latestPerStudent()`. Delete
      both and call the contract directly.
- [x] **Half-closed by `tokens.css` (2026-09-15).** The *values* now have one
      home, so the sheets can no longer disagree about what "the error red" or
      "a gap" is. What is below still stands for the *selectors*.
- [ ] **The nine-way dashboard CSS split has leaked.** Splitting by *screen*
      rather than by *component* means 25+ class names are now defined in two
      to four sheets each — `.sdash-stat` in four, `.sdash-card` in three,
      `.sdash-legend`, `.adm-tile`, `.cgs-donut`, `.notif-row` in two — so
      which rule wins depends on the include order in
      `partials/stylesheets.blade.php`, which is why `conventions.md` has to
      say "do not reorder". A component-shaped split (or moving the shared
      `.sdash-*` shell into one sheet the screen sheets only extend) removes
      the ordering dependency. Not worth a big-bang rewrite; worth doing for
      `.sdash-stat` alone, which four sheets touch.
- [ ] **`Support\Role::DAC` and `Role::PANEL_EXAMINER` are declared and never
      used** — no stage, no seeded account, no reference anywhere in `app/`.
      Delete them or give them a module. (Also noted under Core gaps below.)
- [ ] **17 `.gitkeep` files in Jason's, Chloe's and Haziq's empty module
      folders.** `ModuleServiceProvider` discovers directories that exist, so
      it creates nothing and needs nothing pre-made; the folders appear when
      the first real file lands. The `ModuleProvider.php` and `routes.php`
      stubs *are* worth keeping — they claim the folder so six people don't
      collide — but the empty-directory markers under them buy nothing.
- [ ] `Console\Commands\SeedDemoData` re-implements scenario lookup and
      validation that `DemoDataSeeder::SCENARIOS` could answer itself. The
      command earns its place for `--list` and for not having to type
      `--class=`; the 20 lines of hand-rolled `array_key_exists` + error
      formatting in the middle of it do not.

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
- [~] Automated tests — the harness exists and 53 tests cover the parts that
      break quietly: both authorisation locks, approve/reject outcomes,
      Travel's conditional routing, the `stages(null)` superset, that every
      registered module's routes actually exist, and the attendance contract
      including its degradation path, plus Attendance's import, Supervision,
      Certification, Claims, Publication, Examiner Nomination and Re-viva.
      **Still wanted:** Travel, GA Extension, Attendance Appeal and Conflict
      Detection have no feature test of their own, and neither does
      `DocumentStore`'s allow-list. Each owner writing one for their own
      module is the cheap way to get there — `tests/Feature/<You>/` is where
      it goes.
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
8. Jason's three chains are built. The "return to student" engine question
   was settled without a Core change (see his section); the
   `senior_exec_cgs` account still needs seeding before anyone other than
   Jason can walk the Hardbound chains.
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
