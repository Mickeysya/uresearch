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
| Norhanis — Publication · Claims · RPD | not started |
| Nureen — GA Extension · Attendance · Supervision · Certification | done · matches `docs/scope/nureen.md` |
| Hani — Examiner pool + Nomination | done |
| Hani — Conflict detection T2 · Re-viva | not started |
| CGS dashboard (5 stat cards + 5 live panels) | done |
| Admin dashboard (5 cards + 4 panels, system health) | done |
| Notification feed (`/notifications`) | done |
| Audit log (`/admin/audit-logs`, spatie/activitylog) | done |
| Jason — Hardbound Submission · Appeal · Appointment Letters | scoped, not started |
| Chloe — Workstation · Candidacy Reminder / Appeal / Dismissal | scoped, not started |
| Haziq — GRA · GA · Stage Gates · Allowance | done, follows `docs/scope/haziq.md`'s report logic · no automated tests yet |
| **Cross-module overlaps** | **1 resolved (Haziq/Nureen), 3 unresolved — see below** |
| Automated tests | 42, covering the engine, the seams, the CSP, the import, Nureen’s chains and the profile |
| **Runs end to end** | yes — verified 2026-09-09 |

---

## Verified working

Run end to end on 2026-09-09 (Ubuntu 24.04 / WSL2, PHP 8.3.6, MySQL 8.4.11):

- [x] `./setup.sh` completes from a clean clone and an empty volume
- [x] All migrations run (9 at the time, 14 now); 11 accounts and 5 examiners seeded
- [x] Every route registers, including every module — auto-discovery works
      (20 at the time, 42 now)
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

### Docs
- [x] `README.md`, `CLAUDE.md`, `LEGACY.md`, this file
- [x] `docs/` — architecture, adding-a-module, conventions, module-keys, migration-from-legacy
- [x] `docs/scope/` — six per-person scope documents + `technical.md`.
      `chloe.md` and `haziq.md` written 2026-09-12 from their interim-report PDFs,
      which are kept alongside in `docs/scope/chloe/` and `docs/scope/haziq/`.
- [x] `.claude/agents/` — module-builder, legacy-porter, core-guard, security-reviewer
- [x] A README in every module folder

---

## Norhanis — Travel · Publication · Claims · RPD

- [x] **Travel** — full chain, with conditional routing (local stops at the
      Chair; international continues to CGS and the Dean). Reference module.

- [ ] **Publication** — Supervisor → Chair → Non-Exec CGS → Senior Director CGS
  - [ ] `publication_details` + `publication_authors` tables (repeatable authors)
  - [ ] Letter of Undertaking flag and its document
  - [ ] `PublicationWorkflow`, controller, 3 views
  - Straightforward: a four-`Stage` chain, no conditional routing.

- [ ] **Claims (student)** — Supervisor → Chair → Non-Exec CGS → Manager CGS → Project Director
  - [ ] `claims_details` + `claims_items` (repeatable expense rows)
  - [ ] Server-side total / balance calculation — never trust the posted total
  - [ ] Receipt uploads via `DocumentStore`
  - [ ] Decide whether Project Director is a `Stage` or a post-approval export

- [ ] **RPD Candidacy** — the largest remaining piece, three separate flows
  - [ ] `candidacies` table: programme type, start date, computed deadline
        (8 months FT, 12 months PT), current status
  - [ ] **Reminders** at 3 / 2 / 1 months — an artisan command scheduled from
        `routes/console.php`, which already has the hook. Record what was sent
        so a reminder never fires twice.
  - [ ] **Appeals** — Supervisor → Chair → Non-Exec CGS → Dean; on the Dean's
        approval, recalculate the deadline and update the masterlist
  - [ ] **Dismissals** — initiated by Non-Exec CGS → Dean → Faculty → Registry
  - [ ] The `registry` role already exists in `Support\Role`

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

- [ ] **Close the examiner lifecycle** — *nothing currently writes
      `assigned_until` or `last_examination_date`*. The state machine reads
      them, so until an approved nomination sets `assigned_until` and a
      completed evaluation sets `last_examination_date`, every examiner stays
      Available forever. Smallest useful next task in this module.
- [ ] **Conflict detection, touchpoint 2**
  - [ ] CGS faculty-list compilation screen (merge department lists into FOE / FSMC)
  - [ ] Detect the same examiner nominated by two departments
  - [ ] Flag and surface pool availability for a manual decision — never auto-reject
  - [ ] `examiners.faculty` and `users.faculty` already exist for this
- [ ] **Re-viva monitoring**
  - [ ] Formal submission timestamp on re-corrected thesis upload — the
        6-month and 1-year deadlines both count from it
  - [ ] Discrete stepper: Report sent → Under panel review → Report received →
        Consolidation scheduled
  - [ ] 5-level outcome scale; level 4 loops back into another cycle, level 5
        is terminal. A loop is not a normal chain — think about how to model
        it before writing code.
- [ ] Examiner admin screen so CGS can add examiners and mark them unavailable
      (currently seeder-only)

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

- [ ] **Hardbound Submission** (`hardbound_submission`) — Non-Executive CGS
      → Senior Executive CGS
  - [ ] `hardbound_submission_details` table: thesis title, matric number,
        programme, supervisor
  - [ ] Thesis PDF + clearance form uploads via `DocumentStore`
  - [ ] Non-Exec review screen — forward to Senior Exec, or return to the
        student with mandatory comments
  - [ ] Senior Exec approve/reject, auto-email on approval
  - [ ] Resubmission form for a returned application
  - [ ] **Open question, resolve before building the review stage:** the
        spec wants "return to student, application stays open," but
        `WorkflowEngine::decide()` currently only knows approve (advance) and
        reject (terminate, freeze `current_stage`). Decide whether "returned"
        is a new outcome the engine needs to support, or whether a return is
        modelled as a rejection that the resubmission form clones into a
        fresh application. This is a `WorkflowEngine` change either way, so
        raise it with the team first, same as any other Core change.

- [ ] **Appeal Hardbound Submission** (`hardbound_appeal`) — only filable
      once a Hardbound Submission has been rejected/returned
  - [ ] `hardbound_appeal_details` table, FK'd to the originating
        `hardbound_submission` application
  - [ ] Appeal memo upload + written justification
  - [ ] Non-Exec: compile the Dean PFR report (Dompdf) from the appeal memo
        and the original submission, then forward to Senior Exec
  - [ ] Senior Exec: ruling (accept/reject) — on accept, decide how the
        original Hardbound Submission application gets reopened
  - [ ] Auto-email the ruling to the student

- [ ] **Appointment Letter & Report Management** (`appointment_letter`) —
      Chair of Department (the spec's "Faculty Department") → Academic
      Executive (the spec's "Faculty Academic") → Dean of PGR
  - [ ] `appointment_details` table: examiner name, institution, email,
        expertise, the student it's for
  - [ ] Chair nomination form; AE endorse or reject-with-comments; Dean
        approve/reject
  - [ ] On Dean approval: generate the Appointment Letter PDF (Appendix B
        template, Dompdf) and email it to the examiner
  - [ ] **Open question:** every other module's notification goes to the
        student, a system user with an account. This one's final recipient
        is an external examiner with no login — that's a plain `Mail`, not
        the `ApplicationDecided` notification path the rest of the engine
        uses.
  - [ ] Archive the generated letter as an `ApplicationDocument` so the
        existing download route and permission check apply

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
  - [ ] **Needs the "return with comment" outcome** the engine does not have —
        same gap as Jason's Hardbound review. One design decision covers both.
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

Scope in `docs/scope/haziq.md`. Built 2026-09-21 to the **interim report's**
logic specifically — a UTP GRA requisition/extension form and the official
Stage Gate infopack were both reviewed during design and deliberately set
aside in favour of the report's version once report vs form conflicted (see
git log for that conversation). Worth knowing before extending this module:
the official forms describe a 3-party approval chain (Project Leader →
Funding Unit CGS → Senior Director, no Supervisor stage) and a
forfeit-not-terminate consequence for every stage, both different from what
is actually built. Reconcile with CGS before this goes further than a demo.

**One Core file touched, with explicit sign-off (2026-10-11):**
`sidebar-admin-nav.blade.php` and `sidebar.blade.php` never rendered
`$queues`/`$extraLinks` for Admin — every other role's sidebar partial
already did, Admin's just never received them, because
`queuesForRole('admin')` had been empty for every module until this one gave
Admin a real stage (`admin_verify` on `gra_application`, `admin_eligibility`/
`admin_final` on `ga_application`). Fixed by passing those two variables
into `sidebar-admin-nav` and rendering a "Pending My Action" block there,
the same pattern `sidebar-approver-nav.blade.php` already uses — both
guarded by `! empty(...)`, so it's a no-op for every other module (verified:
grepped every workflow in the repo, only Haziq's two give Admin a stage).
Verified end to end by rendering `DashboardController`'s actual response for
a logged-in Admin — the page now contains "Pending My Action" and "GRA
Applicants"; all 42 pre-existing tests still pass unchanged.

- [x] **GRA Application** (`gra_application`) — Student → **Admin** (verify
      only, never rejects) → **CGS Staff** → **Supervisor** → **Senior
      Director CGS**, the latter three each a single approve/reject. CGS
      Staff added as its own stage 2026-10-11 — earlier rounds had mapped
      the role but never actually put it in the chain. Supervisor's earlier
      two-click "In Process" → "Settled" step (two `Stage`s sharing one
      role) was reverted the same day back to one stage — it rendered as a
      confusing duplicate "Supervisor" step on the stepper, and the
      corrected requirement is every post-Admin stage looks and works the
      same: one approve, one reject.
  - [x] `gra_ga_student_profiles` (one per student, not per application —
        `admission_date`/`level_of_study` must survive every extension) +
        `gra_application_details` + Sections D/E/F as `gra_academic_qualifications`
        / `gra_work_experiences` / `gra_publications`
  - [x] Offer Letter + Admission Letter on approval, via
        `DocumentStore::storeGenerated()`
  - [x] **GRA Extension reuses this same chain** —
        `application_type = 'extension'` + `parent_application_id` on the
        same `gra_application_details` table, not a second module_type.
        Extension Approval Letter on approval; does not regenerate the Stage
        Gate schedule.
  - [x] **Verification + correction flow** (2026-10-11,
        `application_correction_requests`, `HandlesApplicationCorrections`
        trait, shared by GRA and GA) — any of the four approvers (Admin,
        CGS Staff, Supervisor, Senior Director) can flag either a whole
        **section** (Project Details, GRA Details, Academic Qualification,
        Working Experience, Publications) or a single **document** as wrong,
        on any application, at any time — not just Admin, and not just
        whoever's turn it currently is. Went through two permission
        revisions the same day before landing here: first shipped as
        `isAdmin() || isCgs()` (a bug — let CGS do Admin's job
        unintentionally), tightened to Admin-only to match an earlier
        written spec, then widened again to all four approvers once you
        confirmed that's genuinely what you wanted. Deliberately never
        calls `WorkflowEngine::submit()`/`decide()` — status/current_stage
        stay exactly where they were while a request is open, verified in
        Tinker with CGS and Supervisor both successfully flagging the same
        application. The student gets a notification saying which role
        flagged it, linking to an edit form showing only the flagged
        sections/documents; one "Submit Corrections" resolves everything
        flagged in one go. Admin specifically still never rejects at
        `admin_verify` — that part is unchanged — but can also flag
        corrections same as the other three. No new `Application` status
        needed or added. **Flagging is multi-select, one submission** (same
        day) — the form is a checklist (tick any number of sections and
        documents at once), not a one-target-at-a-time dropdown; one
        submission creates one `application_correction_requests` row per
        ticked item and sends the student exactly one notification listing
        everything flagged, not one email per item. Verified in Tinker:
        ticking two sections + one document in one request produced three
        rows and one notification. **Field-level for Section B/C** (same
        day, `sectionFieldLabels()`) — those two sections are a single row
        of named inputs, so the checklist lists their actual fields
        ("Project Title", "Admission Date", ...) instead of one opaque
        "this section is wrong" box; flagging "Project Title" and "Funding
        Source" together still resolves as one Section B edit on the
        student's side. Sections D/E/F stay whole-section flags — they're
        repeatable tables with no fixed field count per student, so there's
        no fixed list of fields to offer. Verified in Tinker: two fields in
        Section B + one in Section C + a whole Section D in one submission
        produced four correctly-labelled rows.
  - [x] **Applicant list + full detail view** (2026-10-11,
        `gra-application.index` / `.show`) — went through three rounds the
        same day before landing on the final split: **list visibility and
        detail visibility are two different things.**
        - The **list** (`.index`) always shows every applicant to all four
          roles — name, programme/level, submitted date, current stage,
          status — regardless of whose turn it is. The bottleneck bar
          narrows this to one stage (`?stage=<key>`) but doesn't gate
          whether the list shows anything; that's just a filter.
        - **Full detail** (`.show` — every section, every document,
          approval history, correction flagging) is gated:
          `stageIndexFor()` places each role at its ordinal position in the
          chain (Admin 0, CGS Staff 1, Supervisor 2, Senior Director 3),
          and a pending application is only openable once its
          `current_stage` index is at-or-past the viewer's own — Admin can
          always see an application still sitting with Admin, CGS can't see
          it until Admin's released it onward. Once an application is
          approved or rejected (no longer pending), everyone can see it —
          the gate only withholds applications still in flight from
          approvers whose turn hasn't come yet.
        - The gate is enforced in `show()` itself via `abort_unless`, not
          just by the list hiding the link — a name not being a link on
          `.index` is cosmetic; the controller refusing the request
          directly is what actually matters. Verified in Tinker: with
          application #8 sitting at `admin_verify`, Admin gets a normal
          response, CGS and Supervisor both get a 403 with "This
          application has not reached your stage yet" — and separately,
          CGS's `.index` page still shows that student's name, programme
          and current stage, just not as a clickable link, with a "Not at
          your stage yet" note.
        - The decision form on `.show` only appears when it's actually the
          viewer's turn; everyone else (who can even reach the page) gets
          read-only. The existing stage-scoped `/gra-application/queue`
          screens are unchanged and still the quicker path for acting on
          your own queue.
  - [x] **Next-approver notification** (2026-10-11, `StageAwaitingReview`)
        — advancing to a new stage (without finishing the chain) now
        notifies every user holding that stage's role. Nothing in Core does
        this for any module today — `WorkflowEngine::decide()` only ever
        notifies the student. Added at the `GraApplicationController::decide()`
        level rather than as a Core change, so it doesn't affect any other
        module's chain.
  - [x] **"Pending by Stage" bottleneck chart** (2026-10-11, on
        `gra-application.index`) — a Chart.js bar, one bar per stage
        (Admin/CGS/Supervisor/Senior Director), counting applications
        currently pending there. Same library and shared defaults the
        existing dashboards use (`core::dashboard.partials.chartjs`); every
        declared stage gets a bar even at zero, so an empty stage reads as
        "nothing pending" rather than silently not appearing. **Clickable**
        (same day) — each bar sets `?stage=<key>` on the list below it, a
        filter on an already-always-visible list, not a reveal — see the
        verification + correction flow entry above for the actual access
        control, which lives on `.show`, not on whether a bar's been
        clicked.
  - [x] **Student "My Application" view + Edit button** (2026-10-11,
        `gra-application.mine`) — a read-only view of everything the student
        submitted, reusing the same `show.blade.php` template approvers get
        (no decision form, no Request Correction control — `canDecide`/
        `canCorrect` both false). An Edit button at the bottom only renders
        once there's an open `ApplicationCorrectionRequest` — before that
        there's nothing to edit, same as the original submission form never
        having one. Verified in Tinker: no button with zero open
        corrections, button appears and links to the correction edit route
        the moment one exists.
  - [x] **Notification → edit form link, Core bugfix** (2026-10-11,
        `NotificationController::destinationFor()`) — clicking any
        notification in a student's feed always redirected to the generic
        `applications.show` tracking page, regardless of what the
        notification was actually about; pre-existing, affects every
        module, not introduced by this one. Fixed with explicit sign-off:
        `destinationFor()` now checks an optional `action_url` in the
        notification's data first, only falling back to the generic page
        when absent — additive, since no notification before
        `ApplicationCorrectionRequested` set that key. Verified three ways
        in Tinker, all deterministic (not relying on the async Redis queue
        worker's timing): the notification's `toArray()` carries the
        correct `action_url`, `destinationFor()` resolves to it directly,
        and a simulated legacy notification with no `action_url` still
        falls back to the exact old behaviour.
  - [x] **Duplicate-submission guard + real navigation to "My Application"**
        (2026-10-11) — three gaps that together meant a student landed back
        on a blank "Apply GRA" form instead of seeing what they'd already
        submitted:
        1. `create()`/`store()` had no check for an existing in-flight 'new'
           application, so the blank form — and a second, duplicate
           submission — was always reachable even with one already pending
           or approved. Both now redirect to `gra-application.mine` for the
           existing one instead (rejected applications don't count — the
           student is meant to be able to reapply after that).
        2. There was no notification confirming submission at all —
           `ApplicationSubmitted`, fired on both `store()` and
           `storeExtension()`, closes that gap with an `action_url` straight
           to `gra-application.mine`.
        3. Nothing in the sidebar ever pointed at `gra-application.mine` —
           `GraApplicationWorkflow::links()` now adds "My GRA Application"
           for a student with a non-draft 'new' application, so it's
           reachable without needing a notification at all. Verified in
           Tinker: a student with an active application gets redirected off
           `create()` and sees the sidebar link; a student with none gets
           the normal blank form and no stray link.
  - [x] **Root-caused "the notification doesn't go to the form"** (2026-10-11)
        — not a code bug. `uresearch-queue` is a long-running `queue:work`
        process that had been up for days, holding the *old* in-memory
        class shape of `ApplicationCorrectionRequested` from before this
        session's multi-select refactor — Laravel workers never hot-reload
        PHP files, so every dispatch was failing
        (`Typed property ... $targetLabel must not be accessed before
        initialization`, visible in `failed_jobs`) regardless of how
        correct the current code was. Fixed with `php artisan
        queue:restart` (standard post-deploy hygiene, not a code change) and
        `queue:flush` to clear the now-unrecoverable stale jobs. Verified
        live through the real worker, not just reflection this time: a
        fresh dispatch now shows `DONE` in the queue log and lands in the
        `notifications` table with the correct `action_url`. **Worth
        remembering:** any future code change to a queued notification
        class needs `queue:restart` after it, in dev same as prod.
  - [x] **Notify the actual requester back, not "all Admins"** (2026-10-11)
        — `storeCorrection()` on both GRA and GA was still blanket-notifying
        every Admin user when a student resubmitted, left over from before
        any of CGS/Supervisor/Senior Director could flag a correction.
        Now notifies exactly the user(s) named in the resolved requests'
        `requested_by`, deduplicated. Verified live: Admin flagged one item,
        CGS flagged another on the same application, student resubmitted
        both in one go — both actual requesters got notified, confirmed via
        the live queue log and the `notifications` table, not a different
        Admin who hadn't asked for anything.
  - [ ] **Separately found, unrelated:** `php artisan test` leaves failed
        jobs behind in the shared dev Redis queue — `ApplicationDecided`
        (Core) and `AttendanceAtRisk` (Nureen), failing on a SQLite
        connection misconfiguration that predates this session entirely
        (same error signature was already in `failed_jobs` before today).
        Not touched — looks like a test-environment config issue the team
        should look at, not something specific to this module.
  - [x] **Flagging closes once a role has already decided** (2026-10-11,
        `HandlesApplicationCorrections::canStillFlag()`, now abstract —
        every controller using the trait must say what "already decided"
        means for it) — an approver could flag a correction on an
        application forever, even after they'd already approved it and
        moved it on, which doesn't make sense: their checkpoint is done.
        For GRA, closes per role the moment `approval_history` has a row
        for that role's own stage key on this application — CGS loses
        access to flag the moment CGS approves, but a later role (whose
        stage hasn't been decided yet) still has it, and everyone loses it
        once the application is no longer pending (approved or rejected —
        nothing left to gate). For GA, where only Admin can flag across two
        separate Admin-owned stages, simplified to "flagging is open for as
        long as the application is pending" rather than picking one of
        Admin's two stages as the checkpoint. Verified in Tinker on a real
        stage transition: Admin could flag before deciding their own stage,
        was correctly blocked (403) after approving it and moving the
        application to CGS, while CGS — whose stage hadn't been decided
        yet — could still flag the same application; separately confirmed
        an approved (terminal) application blocks flagging for a role whose
        own stage was never reached.
  - [x] **Student can edit and resubmit any time, not only when flagged —
        and a flag now highlights the exact field** (2026-10-11) — the
        correction screen used to 404 unless something was open and only
        showed flagged items; now it's reachable for any pending
        application regardless of whether anything is flagged, shows every
        section/document every time, and lets the student change anything,
        not just what was flagged. New nullable `target_field` column on
        `application_correction_requests` (alongside the existing
        `target_label`, which stays display-only) carries the raw field key
        so a flag on a single Section B/C input (e.g.
        `section_b:project_title`) can be matched back to that exact
        `<input>` on render, instead of only being able to highlight a whole
        section. `HandlesApplicationCorrections::describeTarget()` now
        returns `[$targetType, $targetField, $targetLabel]`;
        `GraApplicationController::sectionFieldLabels()` maps section/field
        keys to their display labels for the "Request Correction" dropdown.
        The edit view (`gra_application/correction.blade.php`,
        `ga_application/correction.blade.php`) highlights three levels: a
        single flagged field (red border + its reason directly under that
        input), a flagged section with no specific field named (whole block
        bordered), or a flagged document (same treatment, reused from the
        existing document flow) — new `.haziq-flagged` /
        `.haziq-flag-reason` / `.haziq-flagged-section` rules in this
        module's own `partials/styles.blade.php`. `storeCorrection()` always
        validates and saves every section now rather than gating on what was
        flagged, then resolves whatever was open (none, if this was a
        self-initiated edit) and messages accordingly ("Application
        updated." vs "Corrections submitted..."). Verified live in Tinker
        against application #8: Supervisor flagged `section_b:project_title`
        with a reason — the student's rendered edit form carried
        `haziq-flagged` and the reason text on that exact input, while an
        unrelated field (`funding_source`) carried neither; a self-initiated
        edit with nothing flagged saved correctly and resolved zero
        corrections; a second edit with one open correction saved and
        resolved it in the same submission. Repeated the document-level
        version of the same test on a fresh GA application (none existed
        yet, so one was created and torn down for the test) — Admin flagged
        the resume upload, the student's form highlighted only that file
        input with the reason, the transcript input stayed unhighlighted.
        Full suite re-run clean at 42/42 after.
  - [x] **Editing now stays open after approval too, not just while
        pending** (2026-10-14) — the entry above opened editing up for the
        whole time an application is pending; this extends the same thing
        past approval, for as long as the appointment is still active. New
        `canStudentEdit()` on both controllers (mirrored — GA has its own
        detail table): true while pending, true while approved and
        `candidature_status !== 'terminated'`, false otherwise (rejected,
        or terminated by a Stage Gate — both already send the student down
        the existing reapply-with-a-fresh-application path instead).
        Deliberately did **not** also open up service_start_date,
        service_end_date or proposed_allowance as new editable fields — the
        user confirmed this phase is about keeping the existing Section
        B–F/documents form open longer, not about widening what it can
        touch; those three stay fixed at initial submission, same as
        before. Flagging (`canStillFlag()`) is untouched and already
        correctly closes once an application is no longer pending — there
        is nothing left for an approver to flag once it's approved, so this
        only ever surfaces a self-initiated edit on an approved
        application, same "Application updated." message path as a
        self-initiated edit while pending. Verified live end-to-end: built
        a fresh application, approved it through all four stages, then
        edited `project_title` as the student and confirmed it saved
        (`storeCorrection()` succeeded, `GraApplicationDetail` reflected the
        new value); separately confirmed editing is still correctly
        blocked, 404, on a rejected application and on a terminated one.
  - [x] **GRA: CGS recommends the allowance, Service End Date is fully
        automated, and editing stays live against the database and Stage
        Gate throughout** (2026-10-14) — three related changes:
        1. **proposed_allowance moved from student to CGS.** No longer a
           field on the GRA submission or extension form at all —
           `gra_application_details.proposed_allowance` is now nullable
           (migration `2026_04_01_001600`, `Schema::table(...)->change()`,
           which needed `doctrine/dbal` added to `composer.json` since this
           project didn't have it — a raw-SQL first attempt broke the test
           suite entirely because it used MySQL-only syntax against the
           SQLite connection tests run on). CGS sets it as a required field
           bundled into their own Approve action at the `cgs_review` stage
           (`GraApplicationController::decide()`, validated and saved
           before `engine->decide()` runs — a missing figure blocks the
           approval). GA is untouched — no CGS/Supervisor/Senior Director
           chain for this to hook into there, student still sets it
           directly, same as always.
        2. **Internal allowance flag, CGS-only, never student-facing.**
           New `allowance_recommendation_notes` table +
           `AllowanceRecommendationNote` model — deliberately separate from
           `application_correction_requests`, not a variant of it: that
           table's whole design is "the student sees this and acts on it",
           this one is the opposite by construction. Supervisor/Senior
           Director only (`flagAllowance()`, new route restricted to those
           two roles) can leave a reason on the recommended figure; CGS
           team gets a new `AllowanceRecommendationFlagged` notification.
           Rendered on the approver-facing `show.blade.php` only — a new
           `viewerIsStudent` flag (false from `show()`, true from
           `showMine()`) keeps it out of the student's own view entirely;
           `showMine()` passes an empty collection for it rather than
           querying at all, belt-and-suspenders against ever leaking it.
        3. **service_end_date is never a form field again, anywhere.**
           Always derived — `GraApplicationDetail::computeServiceEndDate()`
           — from `service_start_date` + level (Master 12 months, PhD 24),
           for a brand-new application only; an extension's own custom
           `requested_new_end_date` is untouched, since its whole point is
           a period *beyond* the fixed length. `service_start_date` itself
           is now part of the always-open edit form (Section B) — even
           post-approval — with `service_end_date` recalculating
           automatically the moment it's saved, which in turn feeds the
           Stage Gate resync-while-untouched logic from two phases ago, so
           the whole chain (submission → CGS's allowance → Stage Gate
           schedule) stays live against a single source of truth instead of
           three independently-drifting dates. Extensions keep
           `service_start_date` **not** editable in the correction form —
           it's chained to the parent appointment's end, never a free input
           for one (`'prohibited'` validation rule if somehow submitted
           anyway).
        Verified live end-to-end: a Master's application correctly computed
        a 12-month service_end_date (PhD confirmed separately at 24);
        walked through Admin → CGS (blocked without an allowance, then
        succeeded with RM 1,500 and advanced) → Supervisor (flagged the
        figure with a reason) → Senior Director approval; confirmed the
        flagged reason appeared on CGS's view and the offer letter showed
        RM 1,500, while the student's own `showMine()` render contained
        neither the reason text, the notes section, nor the Flag Allowance
        button anywhere in its HTML; edited `service_start_date`
        post-approval as the student and confirmed `service_end_date`
        recalculated and the Stage Gate's Stage 1 deadline resynced on the
        next `stage-gate:evaluate` run. Full suite re-run clean at 42/42
        after adding `doctrine/dbal`. At the user's own request, also
        deleted application #8 (detail, stage gates, documents, approval
        history, notifications — profile left intact) so they can submit a
        genuinely fresh application through the real UI to test this
        against.
  - [x] **Found immediately after, in the very feature above: Programme /
        Admission Date / Bank were frozen at whatever a student's first
        GRA application ever said, silently** (2026-10-14) — a returning
        applicant's `create()` form showed these three as plain static
        text (`$profile` already exists → the `@else` branch), and even
        when the student had no profile yet, `storeProfile()` only ever
        read the submitted values on first creation — `if ($existing)
        return $existing;` meant every later 'new' application silently
        discarded whatever was typed and kept the original profile
        forever. Found by the user pointing at the static text and asking
        why it still needed filling in, since it looked read-only but
        wasn't actually skippable. Fixed: Section C is now always real,
        editable inputs — no more `@if (! $profile)` branch in
        `form.blade.php` — pre-filled from the saved profile as a
        convenience (`old('programme', $profile?->programme)`, same
        pattern already used for `old()` elsewhere) but always changeable,
        for every student, every time. `level_of_study`/`programme`/
        `admission_date`/`bank_name`/`bank_account_no` are now always
        `required` in `validateApplication()` (not conditional on whether
        a profile exists), and `storeProfile()` now updates an existing
        profile with whatever was actually submitted instead of silently
        keeping the old one (`mode_of_study`/`thesis_title`/
        `ic_passport_no` stay optional, unchanged). Verified live: a
        returning applicant's create form now renders real `<input
        required>` elements pre-filled with their saved values (confirmed
        via the real HTTP-rendered page, not a Tinker shortcut); submitted
        a change — level_of_study master→phd, a new programme name, a new
        bank — and confirmed the shared `GraGaStudentProfile` row actually
        updated, and that `service_end_date` correctly recomputed off the
        new level (24 months, not 12). Restored the real test student's
        profile to its original values afterward and cleaned up the test
        application. 42/42 clean after.
  - [x] **Same gap, caught immediately after by the user: Sections D
        (Academic Qualification) and E (Working Experience) were entirely
        invisible on the create form once a profile existed** (2026-10-14)
        — `@unless ($profile)` hid both sections completely for any
        returning applicant, with no static fallback either (unlike
        Section C) — a returning student's own academic/work history was
        simply gone from the screen, not editable, not even visible.
        Removed the `@unless` — both sections are now always shown,
        pre-filled from whatever's already on file (`create()` now passes
        `academics`/`experiences`/`publications`, same `collect([null,
        null, ...])`-fallback pattern `correction.blade.php` already uses
        for repeatable rows). Found and fixed a second, related bug while
        at it: `storeRepeatables()` only ever *created* rows, never
        deleted first — harmless before, since the fields were hidden and
        so never resubmitted, but with Sections D/E always on the form
        now, every later 'new' application would have piled a second copy
        of the same academic/work/publication history on top of the
        first. Now deletes-then-recreates per profile, same
        replace-the-set pattern `storeCorrection()` already uses for
        these three. Section F (Publications) was already always visible
        but had the identical latent duplication bug and no pre-fill
        either — fixed the same way as part of this pass, before it ever
        shipped as a real problem. Verified live: seeded one academic and
        one work-experience row for the real test student, confirmed the
        create form rendered both pre-filled (`institution="UTP"`,
        `company_name="Petronas"`, via the real HTTP-rendered page);
        resubmitted the same single academic row and confirmed the row
        count stayed at 1, not 2. Cleaned up the test seed data
        afterward. 42/42 clean, scope unchanged.
  - [x] **Save Draft on the GRA submission form — fill it in over multiple
        sittings, not all in one go** (2026-10-14) — previously `store()`
        created and immediately called `engine->submit()` in one
        transaction; there was no way to save partial progress and come
        back, and no route/button for it. Now two buttons behind one
        route: Save Draft (`action=save_draft`, stays `STATUS_DRAFT`,
        `engine->submit()` never runs) and Submit Application
        (`action=submit`, today's original behaviour). New
        `draftApplication()` (mirrors `activeNewApplication()`'s shape,
        scoped to `status = STATUS_DRAFT` instead of pending/approved) —
        `create()` now loads it pre-filled instead of a blank form the
        moment one exists, and `store()` re-derives it itself from the
        authenticated user rather than trusting a hidden form field, so
        there's nothing for a student to tamper with to target someone
        else's draft. Saving again updates the same draft row in place
        (detail, profile, Sections D/E/F, newly uploaded documents) rather
        than creating a second one. Project/GRA details and
        service_start_date stay required even for a draft save — they're
        needed to construct a valid `gra_application_details`/
        `gra_ga_student_profiles` row in the first place, and were already
        always on the form; the one thing genuinely optional at Save Draft
        is documents. Submit now checks document completeness itself
        (`missingRequiredDocuments()` — both this request's uploads and
        whatever was already attached from an earlier draft save) rather
        than through `$request->validate()`'s `required`, specifically so
        a Submit attempt that's still missing one document saves
        everything else the student entered instead of discarding it —
        redirects back with "Saved your progress, but still missing: …"
        and stays in draft. `validateApplication()` lost its
        `$requireProfile` parameter entirely (documents are now always
        `nullable` there; Section C's fields were already made
        unconditionally required two phases ago) — `hasProfile()` is now
        dead and removed. Verified live end-to-end over real HTTP (not
        Tinker, which can't carry session state the same way): saved a
        draft with no documents, confirmed revisiting `/gra-application/new`
        reloaded it pre-filled (including the "Continuing your saved
        draft" banner); edited and re-saved, confirmed the same
        application row was updated (count stayed at 1, not 2); attempted
        Submit with zero documents and confirmed it listed exactly which
        8 were still missing while leaving the application in draft;
        uploaded all 8 and submitted, confirmed it finally advanced to
        `pending`/`admin_verify`, fired `ApplicationSubmitted`, and that
        revisiting the create screen afterward correctly redirected away
        (same `activeNewApplication()` guard as before, now actually
        reachable since a real submission exists). 42/42 clean after.
  - [x] **Three related bugs the user found live-testing their own real
        application (#23): reuploading a document duplicated it instead of
        replacing it, the correction form never showed what was already
        uploaded, and CGS had no way to revise the allowance once the
        application moved past their own stage** (2026-10-14)
        1. **Document reupload duplicated instead of replacing.**
           `DocumentStore::attach()` (Core, shared by every module) has
           always been a plain `create()`, never a replace — re-uploading
           "Resume/CV" just added a second `application_documents` row on
           top of the first, forever, across every save (a GRA Extension's
           evidence, a draft saved twice, a correction resubmission — any
           of them). Fixed locally rather than in `DocumentStore` itself,
           since that service's default behaviour is shared by every other
           module's uploads too and changing it for everyone wasn't
           something to do without asking: new
           `HandlesApplicationCorrections::replaceDocument()` deletes any
           existing same-(application, doc_type) row — file off disk, then
           the row — before attaching, and now every GRA/GA call site
           (`attachDocuments()`, both `storeCorrection()`s, GRA's
           extension evidence, GA's `store()`) goes through it instead of
           `$documents->attach()` directly. Also deduplicated what this
           bug had already produced on real data: application #23 (the
           user's own, live) had up to 5 copies of some document types;
           kept the newest of each, deleted the rest (11 rows, files
           included).
        2. **The correction form never showed what was already uploaded.**
           A `<input type="file">` can't be pre-filled the way a text
           field can, so every document field looked permanently blank —
           indistinguishable from "nothing was ever uploaded" even when 8
           documents genuinely were, which is what actually got reported:
           it looked like the files had vanished. Both `showCorrection()`s
           now pass `existingDocuments` (keyed by `doc_type`); both
           correction views show "Already uploaded: <name> — view" next to
           any field that has one, and only overwrite it if a new file is
           actually chosen.
        3. **CGS could set the allowance once, at their own cgs_review
           decide(), and never again — including after a Supervisor/Senior
           Director flagged it.** By the time a flag is worth acting on,
           the application has almost always already moved to a later
           stage, where the original set-on-approve logic never runs
           again. New `editAllowance()` (CGS/`NON_EXEC_CGS` only, any time
           the application is still pending, new route
           `gra-application.edit-allowance`) + a small form on
           `show.blade.php` next to the allowance notes, pre-filled with
           the current figure. New `AllowanceRecommendationUpdated`
           notification tells whoever flagged it that CGS actually
           revised the number, not just that it silently changed.
        Verified all three live against the user's own real application
        #23 (not disposable test data): confirmed the 11 duplicate rows
        were cleaned up and re-uploading Resume/CV now leaves exactly 1
        row with the new file; confirmed the correction page now shows
        "Already uploaded" for all 8 documents; had Supervisor flag the
        RM 1,500 allowance, confirmed CGS's view showed both the flag
        reason and a form pre-filled with 1,500, submitted a revision to
        RM 1,200, confirmed the figure actually changed and the
        Supervisor who flagged it received `AllowanceRecommendationUpdated`
        — and separately confirmed Admin/Supervisor/Senior Director all
        get 403 on the edit-allowance route, and the student's own view
        never renders the form. 42/42 clean, scope unchanged.
  - [x] **Student can't see the proposed allowance figure until Senior
        Director has fully approved** (2026-10-14) — `proposed_allowance`
        was visible to the student the instant CGS set it, even though it
        can still change (CGS revising it after a Supervisor/Senior
        Director flag, built the turn before this one) all the way up to
        final approval. Showing a number that might still move before
        it's final isn't useful to the student and risks them reading an
        interim figure as the real one. Gated in the two places a student
        can ever see it — `show.blade.php` (their own `showMine()` view)
        and `correction.blade.php` (their edit form) — both now show "to
        be confirmed once your application is fully approved" instead of
        the real figure for as long as `$application->status !==
        Application::STATUS_APPROVED`, regardless of whether CGS has
        already set one internally. Every approver's own view
        (`show()`, reached only by Admin/CGS/Supervisor/Senior Director)
        is untouched — they still see the real figure throughout, same as
        before, since gating it from them would break the whole point of
        CGS setting it and others reviewing it. The offer letter already
        only generates on final approval, by which point the figure is
        settled — confirmed still correct, not a new behaviour. Verified
        live on the user's own application #23: while still pending
        (at Supervisor's stage), both the student's `showMine()` and
        `correction.blade.php` showed the placeholder text, not RM
        1,200.00, while CGS's own view showed the real figure throughout;
        walked the application through Supervisor and Senior Director
        approval and confirmed both student-facing views then revealed
        RM 1,200.00, and that the generated offer letter correctly shows
        the same figure. Application #23 is now genuinely fully approved
        as a result of this verification — a real, intentional outcome of
        testing the actual approval chain, not test data needing cleanup.
        42/42 clean, scope unchanged.
  - [x] **Document list confirmed as-is — no changes.** (2026-10-14) — user
        asked to review the 8 required GRA documents against spec before
        deciding whether to trim any. Checked the FYP interim report (`docs/
        scope/haziq/FYP_Interim_Report_AbdulHaziq_22007428_sign.pdf`,
        extracted via a throwaway `pypdf` venv since neither `pdftotext`
        nor a PDF library was available) — it confirms "the eight required
        documents" by count (Section 3.7, the AI chatbot's example query
        table) but never names them individually, so the current list
        wasn't contradicting anything specific, just unconfirmed. User
        reviewed and said to keep it exactly as built.
  - [x] **A genuinely new application always starts blank, even for a
        returning applicant with a saved profile — pre-fill now means
        "continuing a draft", not "has applied before"** (2026-10-14) —
        two phases ago, Section C/D/E/F were made to pre-fill from
        `$profile`/its academic-experience-publication records whenever
        one existed, so a returning student wouldn't have to retype
        everything. That was right for continuing an actual saved draft,
        but also silently pre-filled a *brand-new, unrelated* application
        attempt with old profile data the moment one existed at all —
        which is the behaviour being corrected now: every new application
        starts from nothing, independent of what's already on file,
        confirmed explicitly after asking which of the two the user meant.
        `create()` now only loads `$profile` (and from it,
        academics/experiences/publications) when an actual
        `draftApplication()` is found — not merely "this student has a
        profile". No blank-check fields added to the blade; the existing
        `$profile?->field` pre-fill pattern already resolves to nothing on
        its own once the controller stops handing it a profile for the
        no-draft case. `storeProfile()` is unaffected — saving a draft (or
        submitting) still updates the student's one shared profile with
        whatever was actually typed, same as before; only what the *next*
        blank `create()` visit shows is different. Verified live over real
        HTTP: saved a draft (creating/updating a profile with "Old
        Programme" / "Old Bank"), confirmed reloading `/gra-application/new`
        while that draft still existed correctly showed it pre-filled
        ("Pre-filled from your saved draft"); deleted just the draft
        (leaving the profile with "Old Programme" / "Old Bank" intact) and
        confirmed a fresh visit rendered every Section C and D field
        completely empty despite the profile still holding that data,
        with the page now reading "Every application starts blank, even if
        you've applied before." 42/42 clean, scope unchanged.
  - [x] **Supervisor (and Senior Director) couldn't reach the page to flag
        the allowance until the application had already reached their own
        approval stage — not an allowance-flagging bug specifically, a
        page-visibility gate that contradicted an already-stated design
        intent** (2026-10-14) — `HandlesApplicationCorrections`'s own
        docblock says flagging is meant to work "whether or not it has
        reached their stage yet", and `canStillFlag()` already implements
        exactly that (open until the viewer's *own* stage is decided, not
        gated to current_stage having arrived there). But `show()`'s
        `canViewDetail()` gate — which decides whether the page opens at
        all — only used stage order, with no awareness of that flagging
        exception, so an approver whose turn hadn't come up yet got a 403
        ("has not reached your stage yet") before ever reaching the Flag
        Allowance form, regardless of what `canStillFlag()` would have
        allowed once there. For Senior Director (last in the chain) this
        meant never being able to flag until their own final turn — by
        which point there's nothing left to flag ahead of; for Supervisor
        it happened to work out by coincidence (their stage is immediately
        after CGS's, so the timing usually lined up) but broke the moment
        a Supervisor tried looking at an application still sitting at
        Admin or CGS. Fixed by giving `canViewDetail()` the same exception
        `canStillFlag()` already has: now returns true, same as before,
        once the viewer's own stage has been reached — but also returns
        true earlier than that if `canStillFlag($user, $application)` is
        true, i.e. whenever this approver still has something they could
        flag. New `User $user` parameter threaded through
        `canViewDetail()`'s two call sites (`show()`, and the queue
        list's per-row `canViewDetail` closure). This also restores the
        same "flag any time" intent for the general Request Correction
        feature, not just the allowance flag — both ride the same gate and
        the same underlying `canStillFlag()` check, so both were equally
        affected and both are equally fixed. Verified live: built a fresh
        application sitting at `admin_verify` (confirmed via a real
        session, not Tinker) and logged in as both Supervisor and Senior
        Director — both previously got the 403; both now load the page
        and see "Flag the proposed allowance", with no Approve/Reject
        decision form shown (not their turn, correctly unaffected);
        Supervisor's flag actually submitted and created a real
        `AllowanceRecommendationNote` this early. 42/42 clean, scope
        unchanged.
  - [x] **User walked the whole timeline/notification/allowance logic
        through in detail to confirm it end-to-end; three real
        discrepancies came out of that walkthrough** (2026-10-15) —
        everything else described (deadline → grace period from day one,
        monthly reminders throughout grace, the consequence landing the
        month after grace ends, Stage 1 recoverable vs Stage 2+
        terminating, resync on an edited service date, PhD = 24 months)
        was already built and already matched, confirmed by re-reading
        `StageGateEngine` against the walkthrough line by line before
        touching anything. Three things didn't match:
        1. **Grace-period reminders were going to CGS too — should be
           student-only.** Reverses the explicit CGS+student change from
           two rounds ago; the user's own words this round were
           unambiguous ("email ni student je dapat"). CGS finds out
           through the dashboard or once a real consequence lands, not
           every month a student hasn't acted yet.
           `StageGateEngine::sendMonthlyGraceReminder()` no longer calls
           `notifyCgs()`.
        2. **Stage Gate completion only notified the student — should
           notify everyone in the chain.** ("kalau student dah complete
           je semua user dapat bila cgs tekan complete tu.")
           `StageGateCompleted` gained the same `forStudent` dual-content
           pattern every other Stage Gate notification already has;
           `recordCompletion()` now also notifies Admin + the whole CGS
           team (Senior Director included) + this specific student's own
           assigned Supervisor (not every supervisor in the system) —
           alongside the student, not instead of.
        3. **Reapplying after a termination had no attachment
           requirement.** New: `GraApplicationController::
           hasTerminatedHistory()` checks whether any of this student's
           past GRA applications has `candidature_status = 'terminated'`;
           if so, a 9th document field ("Termination Appeal Letter")
           appears on the form and is enforced at Submit time the same
           way the other 8 are (`missingRequiredDocuments()` plus one
           explicit check), via `attachDocuments()`'s existing
           `replaceDocument()` path — handled outside `documentTypes()`
           deliberately, since that method's fixed 8-item contract is
           shared with GA and with `correction.blade.php`.
        Verified all three live, against a real terminated test
        application (backdated 19 months, grace-expired on Stage 2, same
        as previous rounds): confirmed the grace reminder for the
        still-open Stage 3 reached only the student, nobody else;
        confirmed reapplying showed the appeal-letter field, blocked
        Submit with "still missing: Termination Appeal Letter" when
        omitted, and succeeded once attached (9 documents on the new
        application, the 8 standard ones plus the letter); marked a
        withheld stage complete as CGS and confirmed Admin, all three
        seeded CGS-team accounts, and the student were all notified
        (Supervisor correctly absent only because this particular test
        student had none assigned). Also verified, per the user's
        explicit ask, that the Stage Gate Monitoring filters actually
        narrow results for Admin and are reachable by CGS/Supervisor/
        Senior Director too — confirmed against real, pre-existing data
        (application #25) without touching it, per "jangan ubah dataset":
        unfiltered showed all 3 of its stages, `allowance_status=withheld`
        correctly narrowed to just the one withheld stage. 42/42 clean,
        scope unchanged, no application data touched.
  - [x] **Same-day correction to the entry above: grace reminders are CGS
        + student (not student-only); completion/withheld/termination are
        all "semua user", not just completion** (2026-10-15) — user
        caught their own mistake immediately after the previous entry
        landed. Two changes:
        1. **Grace reminder reverted back to CGS + student**, undoing the
           student-only change from a few hours earlier the same day.
           `sendMonthlyGraceReminder()` calls `notifyCgs()` again.
        2. **`StageGateAllowanceWithheld` and
           `StageGateCandidatureTerminationTriggered` widened from
           student-only / student+Admin to the same "everyone in the
           chain" audience completion already had** — Admin + full CGS
           team + this student's own assigned Supervisor, alongside the
           student. `StageGateAllowanceWithheld` gained the same
           `forStudent` dual-content constructor param every other Stage
           Gate notification already has (it previously took none).
           Extracted the repeated "Admin + CGS team + this student's
           supervisor" recipient logic — previously inlined only in
           `recordCompletion()` — into a shared
           `StageGateEngine::notifyEveryone()`, now used by completion,
           withheld, and termination alike; the now-redundant
           single-purpose `notifyAdmin()` helper was removed. The one
           thing that stays narrow on purpose: the monthly grace reminder,
           CGS + student only — "semua user" never applied to that one,
           confirmed explicitly, since broadcasting "still not done" every
           single month to the whole chain would be noise, unlike the
           one-time events.
        Verified live with a fresh test student who, unlike the previous
        round's, actually has a Supervisor assigned (needed to properly
        confirm Supervisor inclusion): walked one application through
        grace period (confirmed CGS-team + student only, no Admin/
        Supervisor), then Stage 1 grace expiry (confirmed Admin + full
        CGS team + Supervisor + student all notified for the withheld
        event), then Stage 2 termination (same full audience confirmed
        again). 42/42 clean, scope unchanged.
  - [x] **Found the real cause of "filter kept saying it didn't work" —
        the filter dropdowns silently never submitted at all, not a server-
        side bug** (2026-10-15) — every earlier verification of the
        Stage Gate/Allowance filters this session was done by hitting a
        URL with the query string already attached (`?allowance_status=
        withheld`), which only ever proved the *server-side* filtering
        logic was correct. It never actually exercised the dropdown the
        way a person clicking it does. Both filter forms used inline
        `onchange="this.form.submit()"` — this project's CSP has no
        `'unsafe-inline'` on `script-src` (`ContentSecurityPolicy`'s own
        docblock explains why: every inline script must carry a nonce, on
        purpose, so an injected one silently can't run), and that same
        restriction blocks inline event-handler attributes exactly like
        it blocks a bare `<script>` — so `onchange="..."` simply never
        fired, with nothing visible to say why. Changing a dropdown really
        did do nothing, which is exactly what was reported. Fixed with
        the same delegated-listener shape `confirm_scripts.blade.php`'s
        `data-confirm` already uses successfully everywhere else in this
        module: new `haziq::partials.filter_autosubmit_scripts`, one
        nonce'd `change` listener delegated off `document`, and every
        filter input/select across both `stage_gate/index.blade.php` and
        `allowance/index.blade.php` now carries `data-autosubmit` instead
        of the inline attribute. Verified via the project's own
        `ContentSecurityPolicyTest` ("every inline script on every screen
        carries the nonce") passing clean, then confirmed live that no
        `onchange=` remains on either page and the new nonce'd listener
        script is present with a matching nonce. The allowance-status
        question in the same message — per-stage `allowance_status`
        showing `eligible` on a stage that hasn't reached its own
        deadline yet, even while an earlier stage on the same application
        shows `withheld` — is working as designed: each stage tracks only
        its own requirement, and the actual monthly payment is driven by
        the *governing* (earliest open) gate's status via
        `ensureMonthlyLogs()`/`statusAsOf()`, already built and tested
        several rounds ago — most likely read as "broken" because the
        filter bug above was blocking a clear look at it. Noted rather
        than changed; flagged back to the user to confirm once they can
        actually filter and look. 42/42 clean, scope unchanged.
  - [x] **User looked again with the filter now actually working and found
        two more real things: no Active/Terminated column on the
        monitoring table at all, and the Allowance column reads as "still
        eligible" on a later stage even while an earlier one is genuinely
        blocking payment** (2026-10-15) — the second one reverses what I'd
        defended as correct-by-design the message before; the user pushed
        back explicitly ("selagi cgs tak mark complete selagi tu die
        withheld") and was right to.
        1. **New Status column** — `StageGateController::
           candidatureStatusByApplication()`, one query each against
           `gra_application_details`/`ga_application_details` keyed by
           `application_id`, merged (an application is never both module
           types, so the keys never collide). Shows `active`/`terminated`
           per row, via two new `.status-badge` colour tones (green/red,
           matching the existing `eligible`/`forfeited` tones) — there
           was no styling for those two values at all before.
        2. **Allowance column now shows the *governing* stage's status,
           not each row's own.** `StageGateEngine::governingGate()` went
           from `protected` to `public` — it already existed and already
           correctly drove the monthly `AllowancePaymentLog` entries, just
           was never used for display. New
           `StageGateController::governingAllowanceByApplication()`
           computes it once per application shown on the page. A row
           whose own `allowance_status` differs from its application's
           governing one (every later, not-yet-reached stage, while an
           earlier one is still open) now shows the governing value as
           the primary badge, with a small "this stage: eligible"
           footnote underneath so the per-stage detail isn't lost,
           only de-emphasised. The underlying stored per-stage column is
           untouched — this is a display change, not a data change; the
           allowance filter dropdown still filters on each row's own
           stored value, which is a legitimate "which stages have
           actually had something happen to them" view distinct from "is
           this student currently being paid".
        Separately investigated the "no earlier reminder emails, only the
        final suspended one" question against the real gate in question:
        confirmed `last_reminder_sent_at` was `NULL` and the gate's
        `created_at` already postdated its own `grace_end_date` — this
        specific gate's whole grace window (Jul–Oct 2026) had already
        fully elapsed before it ever existed in the database, because it
        was backdated into being via a service_start_date edit rather
        than reached by time actually passing with evaluation in between.
        The system was never once able to observe it mid-grace-period, so
        the monthly-reminder branch of `evaluateGate()` never had a
        chance to run for it — not a bug in that branch itself. Confirmed
        the branch does work correctly with a disposable, non-backdated
        test application built through the real submission+approval flow:
        evaluated it while genuinely in grace_period (4 recipients —
        student + 3 CGS-team accounts — notified, matching the CGS+
        student audience from the entry above), then simulated a month
        passing by moving `last_reminder_sent_at` back and re-evaluating,
        and confirmed 4 more reminders fired (8 total), proving the
        monthly cadence continues correctly when a gate is actually
        evaluated as it progresses, rather than fast-forwarded past its
        own grace period in one step. 42/42 clean, no real application
        data modified.
  - [x] **The governing-allowance display fix above only landed on the
        approver table — the student's own Stage Gate view still showed
        each row's own narrow `allowance_status`** (2026-10-15) — caught
        immediately by the user. `StageGateController::index()`'s student
        branch now computes and passes `governingAllowance` the same way
        the approver branch does, and `stage_gate/index.blade.php` no
        longer branches on `$isStudent` for which status to show — both
        views use the same governing value, with the same "this stage:
        eligible" footnote when a row's own state differs from it.
        Verified live as the real student (`student@utp.edu.my`): all 3
        of application #25's stages now show `WITHHELD`, not just Stage 1.
        42/42 clean, scope unchanged.
  - [x] **Editing service_start_date didn't update the Stage Gate schedule
        until the next daily stage-gate:evaluate run — "die kene linked"**
        (2026-10-15) — `resyncIfUntouched()` (built two rounds ago) was
        always correct, but only ever ran as part of `evaluateAll()`, the
        scheduled job. A student correcting their date through
        `storeCorrection()` updated `gra_application_details` immediately
        but left the Stage Gate rows exactly as they were until the
        scheduler next fired — up to a full day later, which reads as
        "the edit didn't do anything." New
        `StageGateEngine::evaluateApplication()` — the same per-gate pass
        `evaluateAll()` does, scoped to one application — called
        synchronously from `storeCorrection()` the moment
        `service_start_date` actually changes (compared against the value
        read before the transaction, not Eloquent's dirty-tracking, since
        the update happens on a re-fetched instance inside the
        transaction closure). Only fires on the date actually changing, not
        every edit. Verified live end-to-end: built and approved a fresh
        application, noted Stage 1's deadline, edited service_start_date
        through the real `storeCorrection()` call with no separate
        `stage-gate:evaluate` run in between, and confirmed the deadline
        had already moved to the new date immediately afterward. 42/42
        clean, scope unchanged.
  - [x] **User re-raised "linked" to mean all of deadline/grace, timeline,
        allowance status, and the candidature active/terminated status
        together — not just the dates — confirmed `evaluateApplication()`
        from the entry above already covers all of it, no code change
        needed** (2026-10-15) — the previous entry's live test only
        checked that the deadline date moved; it called the same
        `evaluateGate()` the daily job uses for every open gate, which
        computes timeline_status, fires `applyGraceExpiry()` (allowance +
        termination) and the monthly reminder exactly as it would on a
        scheduled run — the date resync was never the only thing that
        method does, just the thing checked for last time. Verified with a
        harder case this time: built and approved a fresh application
        (everything `upcoming`/`eligible`/`active`), then edited
        `service_start_date` to 19 months in the past in one
        `storeCorrection()` call, no separate evaluate step anywhere.
        Confirmed, immediately: Stage 1 → `grace_expired`/`withheld`,
        Stage 2 → `grace_expired`/`forfeited` with `candidature_status`
        flipped to `terminated`, and Stage 3 (not yet fully expired at 19
        months) correctly landed in `grace_period` and sent its own
        monthly reminder — all three stages' real-time states changing
        together from one edit, with the matching notifications
        (termination to everyone, allowance-withheld to everyone, grace
        reminder to CGS + student) all firing within that same request.
        42/42 clean, no code changed, scope unchanged.
  - [x] **Appointment length was wrong: Master 12 / PhD 24 months, should
        have been Master 24 / PhD 42 — matching the same max GRA/GA
        funding duration the Stage Gate rule table's own last stage
        already encodes, not two independently-chosen numbers**
        (2026-10-15) — a genuinely wrong value introduced a few rounds
        ago when `computeServiceEndDate()` was first built, caught by the
        user against their own real approved application #25, which
        showed "1 Jun 2026 to 1 Jun 2027" (12 months) where it should
        read "1 Jun 2026 to 1 Jun 2028" (24). An earlier message this same
        day ("aku silap service untuk phd 24 bulan bukan 12, phd 42
        bulan") was meant to correct this and was misread as confirming
        the existing 12/24 split instead — this entry is the actual fix.
        `GraApplicationDetail::computeServiceEndDate()` now adds 24
        months (Master) / 42 (PhD) instead of 12/24; updated the two
        stale "12 months for Master's, 24 for PhD" comments left in
        `form.blade.php` and `GraApplicationController::
        validateApplication()` to match. Checked every other `'new'`-type
        GRA application in the database for the same mismatch (only
        application #25 existed) and corrected its stored
        `service_end_date` in place to match the right formula — this one
        is a direct data fix, not merely a future-submissions-only code
        change, since the user was looking straight at the wrong value.
        service_start_date is unaffected, and so is the Stage Gate
        schedule — that's computed from service_start_date +
        stage_gate_rules only, never from service_end_date, so nothing
        else needed touching. Verified: `computeServiceEndDate()` returns
        2028-06-01 for a 2026-06-01 Master start (was 2027-06-01) and
        2029-12-01 for a PhD start (was 2028-06-01); confirmed application
        #25 now stores the corrected date. 42/42 clean, scope unchanged.
  - [x] **"Linked" editing (see the two entries above) never actually
        helped application #25 — because its Stage 1 gate was already
        `withheld` from earlier testing, `resyncIfUntouched()`'s own
        freeze guard correctly-by-its-old-design refused to touch it, so
        editing `service_start_date` to 1 Jun 2026 left the deadline,
        grace dates, allowance and candidature status exactly as they
        were under the old date** (2026-10-15) — user: "deadline ape
        semua sama lagi macam previous data. patut data kene berubah."
        `resyncIfUntouched()`'s "once touched, frozen" rule was built to
        stop *time passing* (or CGS acting late) from silently erasing a
        real consequence — it was never meant to stop a student's own
        honest correction from being believed. New
        `StageGateEngine::recomputeApplication()` replaces
        `evaluateApplication()` as what `storeCorrection()` calls on a
        real `service_start_date` change: resets every open gate's
        deadline/grace dates, `allowance_status` and `timeline_status`
        from the corrected date (bypassing the freeze guard entirely),
        resets `candidature_status` to active, then re-evaluates each
        gate from that clean baseline — so a correction that clears every
        violation un-terminates/un-withholds, and one that still leaves a
        genuine violation re-applies it (re-notifying, since resetting
        `timeline_status` clears the "already fired" guard).
        `applyGraceExpiry()` also had a related bug this exposed: it only
        called `terminateCandidature()` inside the "no trigger row yet"
        branch, so a re-evaluation after this reset would correctly
        re-detect a still-overdue gate but silently skip re-setting
        `candidature_status` back to terminated, since a trigger row
        already existed from the original violation. Moved that call
        above the dedup check — `candidature_status` now always tracks
        the gate's real current state, while the trigger row + its
        notification still only ever fire once, as before. Also found,
        live, on application #25's real data: existing
        `AllowancePaymentLog` rows (written under the old, wrong
        schedule) never got corrected by this resync — October's log
        still read `withheld` even after the gate itself went back to
        `eligible`. New `recomputeMonthlyLogs()` re-derives every
        non-`backdated` row's status from the corrected schedule;
        `backdated` rows (a real CGS back-pay action) are left untouched
        on purpose. The now-fully-dead `evaluateApplication()` was
        removed rather than kept alongside `recomputeApplication()`.
        Verified live, end to end, against application #25's actual data
        (not disposable test data — this was the application the bug was
        reported on): confirmed via Tinker that Stage 1 was sitting at
        `deadline=2026-07-01`/`withheld` despite `service_start_date`
        already reading `2026-06-01`. Logged in as `student@utp.edu.my`
        and submitted the real `storeCorrection()` form twice — once to
        `2026-06-02` (to prove the resync fires at all), once back to the
        correct `2026-06-01` — both through real HTTP requests with real
        session cookies and CSRF tokens, not Tinker calling the engine
        directly. Confirmed after: Stage 1 `deadline=2026-09-01`,
        `grace_end=2026-12-01`, `allowance=eligible`,
        `timeline=grace_period` (today, 2026-10-15, correctly falls
        inside that window), `candidature_status=active`, and October's
        `AllowancePaymentLog` corrected from `withheld` to `eligible` —
        matching exactly what the user expected ("patut 1 September 2026
        keluar noti... allowance tak withheld and tak terminate. eligable
        lagi"). Two new `StageGateGraceReminder` notifications confirmed
        sent (one per real correction). 42/42 clean, scope unchanged.
  - [x] **A student's own candidature Active/Terminated status was only
        ever visible to approvers, not to the student it actually belongs
        to** (2026-10-15) — user: "student pun sepatunya boleh tengok die
        active or terminated. cuma tambah warning bar kalau terminated."
        `StageGateController::index()`'s student branch now computes and
        passes `candidatureStatus` (the same helper the approver branch
        already used) plus `isTerminated` (true if any of the student's
        own applications has `candidature_status = terminated`).
        `stage_gate/index.blade.php`'s Status column — previously hidden
        for students alongside the Student-name column — now shows for
        both; split the two `@unless`-guarded columns apart so Status
        renders independently of whether the Student-name column does.
        Added a red `.alert-banner-danger` warning ("Your GRA/GA
        candidature has been terminated...") above the table on the
        student's own page when `isTerminated` is true, styled to match
        the existing red "needs attention" tone already used for
        `status-badge.terminated`/`.haziq-flagged` in this module's
        stylesheet. Verified live as `student@utp.edu.my`: loaded
        `/stage-gate` and found their real application #25 — which the
        user had, independently, just tested with a 2025-04-01 service
        start date in their own browser while this fix was being
        verified — correctly showing `terminated` on every row (Stage 1
        `withheld`/`grace_expired`, Stage 2 `forfeited`/`grace_expired`
        triggering termination, Stage 3 still `grace_period`) with the
        warning banner rendered above the table, and the governing
        allowance ("withheld", with a "this stage: forfeited" /
        "this stage: eligible" footnote on rows 2/3) unaffected by this
        change. 42/42 clean, scope unchanged.
  - [x] **New: a student can appeal their own Stage Gate candidature
        termination — only a Senior Director may decide it, approving
        reinstates candidature only, timeline/allowance untouched**
        (2026-10-15) — user: "student boleh appeal balik. ada appeal
        button... akan masuk noti kat cgs, supervisor admin, yang leh
        approve senior director sahaja. once senior director approve,
        student status active balik cuma status timeline sama and
        allowance jugak." Distinct from reapplying fresh after a
        termination (`GraApplicationController::TERMINATION_APPEAL_FIELD`,
        which gates a brand-new application) — this appeals the SAME
        terminated application directly, no new application involved.
        New table `candidature_appeals` (status pending/approved/rejected,
        decided_by, decided_at, decision_notes — one application can carry
        more than one row over time, a rejected appeal doesn't block a
        later one) and model `CandidatureAppeal`. The letter itself is an
        ordinary `ApplicationDocument` on the same application (doc_type
        "Candidature Appeal Letter") via `DocumentStore`, same as every
        other upload in this module. New `CandidatureAppealController`:
        `create`/`store` (student, only reachable while
        `GraApplicationDetail`/`GaApplicationDetail::isTerminated()`, 404
        otherwise; blocks a second submission while one is already
        pending) and `queue`/`decide` (Admin/CGS/Supervisor can view;
        `decide` itself is route-middleware-restricted to
        `Role::SENIOR_DIRECTOR_CGS` only, matching the user's "senior
        director sahaja"). `decide()`'s validation mirrors
        `GraApplicationController::decide()` exactly — `remarks`
        `required_if:decision,reject`. New
        `StageGateEngine::reinstateCandidature()` (a clear, public wrapper
        around the existing `terminateCandidature(..., terminated: false)`
        — deliberately does NOT touch `timeline_status`/`allowance_status`
        anywhere, matching "cuma... status timeline sama and allowance
        jugak" — recovering a gate's allowance is still entirely
        `recordCompletion()`'s job, same as for any other withheld/
        forfeited gate; an appeal reinstates standing, it does not mark
        anything complete. Submission notifies Admin + CGS + the student's
        own assigned Supervisor (same audience `StageGateEngine::
        notifyEveryone()` already uses for every other Stage Gate
        consequence); approval/rejection notifies the student. Reused
        `haziq::partials.decision_form`/`decide_scripts`/`confirm_scripts`
        as-is for the Approve/Reject UI and the Submit-Appeal confirm
        prompt — no new JS needed. Added a "Candidature Appeals" sidebar
        link (`GraApplicationWorkflow::links()`) for Admin/CGS/Supervisor,
        and an Appeal button inside the termination warning banner on the
        student's own Stage Gate page (one per terminated application, in
        case a student has more than one — shows "pending a Senior
        Director's decision" or "last appeal was rejected... Appeal
        again" instead of the button once one exists). One real bug hit
        and fixed during this build: a nested `@if...@endif` written
        inline on a single Blade line inside an `@elseif` branch produced
        a `ParseError: unexpected token "endif"` — moved it onto its own
        lines; purely a Blade-authoring mistake, not a logic bug. Verified
        live, end to end, against application #25's real data (currently
        terminated from the previous entry's independent verification):
        logged in as `student@utp.edu.my`, confirmed the banner + Appeal
        link rendered, submitted a real appeal letter via HTTP with a real
        session/CSRF token. Confirmed Admin + both CGS users + the Senior
        Director + the assigned Supervisor all received the
        `CandidatureAppealSubmitted` notification (one real bug found
        here too: the long-running `uresearch-queue` worker had routes.php
        cached in memory from before this round's new routes existed, so
        the queued mail/database jobs failed with `RouteNotFoundException`
        until `queue:restart` + restarting the container — not a code bug,
        a worker-needs-restarting-after-routing-changes operational gap;
        retried the failed jobs afterward and confirmed delivery). Logged
        in as `director@utp.edu.my` (Senior Director), saw the appeal in
        the queue, approved it: confirmed `candidature_status` flipped to
        `active` while all three gates' `allowance_status`/
        `timeline_status` stayed byte-for-byte identical to before
        (Stage 1 `withheld`/`grace_expired`, Stage 2 `forfeited`/
        `grace_expired`, Stage 3 `eligible`/`grace_period`), and the
        student received `CandidatureAppealApproved`. Separately verified
        the reject path and the "already decided" guard on disposable
        test data (application #34, deleted afterward): rejecting with a
        reason left `candidature_status` as `terminated`, recorded the
        reason, notified the student with it, the decide form disappeared
        from the queue once decided, and a second appeal was allowed
        after the rejection (not blocked the way a still-pending one is).
        42/42 clean, scope stayed inside `app/Modules/Haziq/`.
  - [x] **Candidature appeal submit/approve/reject weren't in the admin
        audit trail at all** (2026-10-15) — user: "die consider action
        lain kau faham... tayah masuk bahagian form and diorang tayah
        submit form balik" — confirming the appeal is its own distinct
        action (no form resubmission involved, see the entry above), and
        it needs to be recorded as one. `CandidatureAppealController::
        store()`/`decide()` now log under `activity('candidature_appeal')`
        — its own `log_name`, distinct from `'workflow'` (the
        application's own decide()) and `'stage_gate'` (Mark Complete),
        same pattern `StageGateEngine::recordCompletion()` already uses.
        `action` properties use the existing vocabulary
        (`submitted`/`approved`/`rejected`) so Core's admin Audit Logs
        page's tone colouring (good/critical/info) applies automatically
        — Core's `logNames`/`$iconFor` are already dynamic with a default
        fallback, so no Core file needed touching for this to show up.
        Verified live: built a disposable terminated application (#35),
        submitted and approved a real appeal for it through the actual
        HTTP routes, confirmed both `Activity` rows were written with the
        right `log_name`/`properties`, and confirmed they render correctly
        in `/admin/audit-logs?log=candidature_appeal` as Admin. Application
        #35 and its two activity rows were deleted afterward — disposable
        only. Application #25's real appeal (approved last round) predates
        this logging and has no retroactive audit row, which is correct:
        there's nothing to fabricate for an action that already happened
        before this existed. 42/42 clean, scope unchanged.
  - [x] **Removed the old "resubmit the whole GRA form with an extra
        letter" reapplication path entirely — the new Candidature Appeal
        action (two entries up) replaces it, not sits alongside it**
        (2026-10-15) — user, looking at a real rendered GRA submission
        form showing the old "Termination Appeal Letter" field: "kenapa
        nak submit balik ah. die action lain. keluar kat tab. bile kene
        terminate. keluar kat sidebar. patu once senior director approve
        die hilang balik and student status active balik student boleh
        edit detail cam biasa." Removed `GraApplicationController::
        TERMINATION_APPEAL_FIELD`/`TERMINATION_APPEAL_LABEL`,
        `hasTerminatedHistory()`, the `requiresAppealLetter` flag passed
        to `create()`, the missing-document push in `store()`, the
        validation rule in `validateApplication()`, and the conditional
        attach in `attachDocuments()` — confirmed zero real
        `ApplicationDocument` rows existed with that doc_type before
        deleting the code, so nothing real was orphaned. Removed the
        matching conditional block from `gra_application/form.blade.php`.
        Added a student-facing sidebar link, "Appeal Termination"
        (`GraApplicationWorkflow::links()`), that only appears while the
        student actually has a terminated GRA or GA application —
        checking both `GraApplicationDetail`/`GaApplicationDetail` for
        `candidature_status = 'terminated'`, linking straight to
        `candidature-appeal.create` for the most recently terminated one
        (not back to Stage Gate, which already has its own "My Stage
        Gate" entry) — and disappears on its own once a Senior Director
        approves and `candidature_status` flips back to `active`, nothing
        extra to track. Verified live as `student@utp.edu.my`: loaded
        `/gra-application/new` and confirmed the old field is gone
        entirely from the real rendered form; loaded `/applications` and
        confirmed the "Appeal Termination" sidebar link renders, pointing
        straight at `/candidature-appeal/25`; confirmed `/candidature-
        appeal/25` itself still renders the Submit Appeal form correctly.
        (Incidentally, application #25 is terminated again at the moment
        of this verification — not from anything done this round; the
        gates' and `GraApplicationDetail`'s `updated_at` both land at the
        same instant, consistent with the user independently editing
        service_start_date again on their own, through the normal
        correction form, which is exactly the "student boleh edit cam
        biasa" behaviour just confirmed two entries up — a good
        incidental re-confirmation that a reinstated application's
        correction flow still behaves correctly and can re-apply a
        genuine consequence when the edited date actually warrants it.)
        42/42 clean, scope stayed inside `app/Modules/Haziq/`.
  - [x] **Governing allowance was wrong the instant Stage 1 completed
        while a later stage was independently overdue — it read the
        later stage's `forfeited`/`withheld` instead of Stage 1's own,
        genuinely-resolved state** (2026-10-15) — user: "allowance ni
        tertakluk pada stage 1. katakan kalau terminate allowance not
        eligable. tapi kalau dah appeal active balik, allowance eligable
        unless memang rpd die tak siap lagi." `stage_gate_rules.
        recoverable` is true only for stage_order 1, for every level —
        Stage 1 is the only stage with its own independent allowance
        consequence; Stage 2+'s grace expiry is a *candidature*
        consequence (termination), not a second, independent allowance
        gate. `StageGateEngine::governingGate()` was defined as "earliest
        still-open gate", which happened to agree with Stage 1 whenever
        Stage 1 wasn't done yet, but was wrong from the moment Stage 1
        completed while Stage 2 was still sitting `forfeited` from its
        own, unrelated termination trigger. Redefined `governingGate()`
        to always return Stage 1's own gate, and added
        `governingAllowanceStatus(Application $application): string`
        — the actual two-part rule: `candidature_status === 'terminated'`
        always reads as `forfeited` regardless of Stage 1 (a terminated
        appointment is never being paid, even if Stage 1 was long since
        resolved); otherwise it's purely Stage 1's own `allowance_status`.
        This is exactly why `reinstateCandidature()` was right not to
        touch `allowance_status`/`timeline_status` two entries up — the
        *derivation* needed fixing, not the stored gate data; once
        `candidature_status` flips back to `active`, this formula alone
        makes the display correct again, no separate "unfreeze" step.
        `ensureMonthlyLogs()`/`recomputeMonthlyLogs()` (the
        `AllowancePaymentLog` ledger) now use the same two-part rule for
        new/re-derived rows. New public `refreshAllowanceLogs()` wrapper,
        called from `CandidatureAppealController::decide()`'s approve
        path, so existing log rows written during the terminated window
        correct immediately on approval rather than waiting for the next
        daily run — same "linked" philosophy as `recomputeApplication()`.
        One more real bug found live-testing this: `statusAsOf()` (used by
        both those log methods) derived a month's status purely from the
        gate's fixed dates, which mis-read an already-genuinely-completed
        Stage 1 (backdated, grace long past) as still `withheld` — fixed
        to return the gate's own `allowance_status` directly once
        `completion_status === 'completed'`, which is always more
        authoritative than a date-only guess. Also added a small
        `detailFor()` helper (DRY — the GRA-or-GA detail lookup was
        duplicated three times already) used by `currentStartDate()`,
        `terminateCandidature()`, `monthlyAllowance()`, and the two new
        methods. Verified live: application #25's own real, current state
        (Stage 1 completed, Stage 2 independently `forfeited`,
        `candidature_status` active) now correctly shows `eligible` on
        both the real rendered student page and via direct engine call —
        confirmed the OLD logic would have shown `forfeited` here, which
        was the actual bug. Built a disposable terminated application
        (#36) to cover the cases #25 couldn't: terminated-with-Stage-1-
        completed → `forfeited` (override); submitted and approved a real
        appeal for it through the actual HTTP routes → flipped to
        `backdated` (Stage 1's own state) with the existing
        `AllowancePaymentLog` row corrected from `withheld` to `backdated`
        by `refreshAllowanceLogs()`; separately confirmed
        terminated-with-Stage-1-withheld → `forfeited`, and
        active-with-Stage-1-withheld → `withheld`. Application #36 and its
        data were deleted afterward — disposable only. 42/42 clean, scope
        stayed inside `app/Modules/Haziq/`.
  - [x] **A completed Stage Gate could still be flipped back to Not
        Complete — and Mark Complete/Not Complete had no confirm prompt
        at all** (2026-10-15) — user: "once dah mark complete no action
        return. takde dah mark not complete balik. ask option yes or no."
        `StageGateController::markComplete()`/`markNotComplete()` now
        both call a new `abortIfAlreadyCompleted()` (404 once
        `completion_status === 'completed'`) — server-side, not just
        hiding the button, since a hidden button is never itself an
        authorization check. `stage_gate/index.blade.php`'s Action column
        now shows "Completed — final, cannot be changed." instead of
        either button once completed, and both buttons got
        `data-confirm="..."` (the existing generic
        `haziq::partials.confirm_scripts` pattern — newly `@include`d on
        this page, it wasn't before) — Mark Complete's prompt explicitly
        says it's final. Why one-way: completing a gate already fires
        back-pay, the allowance-eligible flip and the completion
        notification (`StageGateEngine::recordCompletion()`) — flipping
        it back afterward would either silently re-run or directly
        contradict all three, and (see the two entries above) a
        completed Stage 1's `allowance_status` is now something
        `governingAllowanceStatus()`/`statusAsOf()` treat as
        authoritative, so un-completing it later would un-ground that
        too. A gate merely marked `not_completed` (CGS's own explicit "not
        yet" — different from the untouched `not_recorded` default) still
        shows both buttons and can still be toggled either way; only
        `completed` itself is final. Verified live as `cgs@utp.edu.my`:
        filtered to `completion_status=completed`, confirmed the real
        completed gate (application #25, Stage 1) shows the plain text
        with no buttons; confirmed a direct POST to `/stage-gate/40/not-
        complete` (bypassing the UI entirely) returns 404; filtered to
        `not_recorded` and confirmed open gates still render both buttons
        with the new confirm prompts attached. 42/42 clean, scope stayed
        inside `app/Modules/Haziq/`.
  - [x] **A completed Stage Gate's Timeline column still showed a stale
        `grace_period`/`grace_expired` badge from the moment it was
        completed** (2026-10-15) — user: "completion kalau dah complete.
        takdenye timeline tunjuk lagi grace period lah apalah. tayah
        tunjuk apa apa." Once completed, `evaluateGate()` never runs on
        that gate again (every caller filters `completion_status != '
        completed'`), so `timeline_status` is frozen forever at whatever
        it happened to be the instant completion was recorded — not wrong
        data, just a stale value that stops being meaningful once the
        gate is done. Display-only fix, same as the entry two up:
        `stage_gate/index.blade.php`'s Timeline cell now renders nothing
        at all for a completed gate, for both the approver and student
        views (one shared cell, no `$isStudent` branch needed). Verified
        live as `student@utp.edu.my`: application #25's completed Stage 1
        row now shows `completed` in Completion, a blank Timeline cell,
        and `eligible` in Allowance — the stale `grace_period` badge is
        gone. 42/42 clean, scope stayed inside `app/Modules/Haziq/`.
  - [x] **recomputeApplication() left an already-completed gate stuck
        "completed" against a schedule a corrected service_start_date had
        just moved out from under it — CGS had no way back in to re-mark
        it** (2026-10-20) — user: "kalau service date reset, grace period
        kn reset en. so complete not complete features kat each stage
        auto reset. cgs boleh mark balik" (then, re-confirming the same
        thing in English on the next date change: "if student change the
        service date, it auto reset the stage gate, all mark incomplete
        and cgs can mark back complete or not"). `recomputeApplication()`
        dropped its `where('completion_status', '!=', 'completed')`
        filter — every gate gets recomputed now, not just the still-open
        ones — and each gate's `completion_status`/`completion_date`/
        `completed_by`/`notes` reset to `not_recorded`/null/null/null
        alongside the existing deadline/grace/allowance/timeline reset.
        A completion CGS already recorded was judged against the OLD
        deadline; once service_start_date corrects that deadline, the
        judgement goes back up for grabs against the real one, matching
        last round's "mark complete is final" rule, which only ever meant
        final *against that schedule*, not final forever regardless of
        what the schedule turns out to actually be. `AllowancePaymentLog`
        rows already `backdated` from the old completion are left alone
        (same as before — a real disbursement, not something a later
        date correction gets to rewrite); `recordCompletion()` naturally
        creates a fresh backdate if CGS marks it complete again and it's
        still sitting `withheld`. Verified live end to end against
        application #25's real data (which had a real, genuinely-completed
        Stage 1 at the time): submitted a real `storeCorrection()` through
        the actual HTTP route changing `service_start_date` to 2025-05-01,
        confirmed Stage 1 reset to `completion_status=not_recorded`/
        `completion_date=null`/`completed_by=null` and re-evaluated to
        `withheld`/`grace_expired` under the new dates (governing
        allowance correctly read `withheld`); logged in as
        `cgs@utp.edu.my`, confirmed the "Mark Complete" button was back
        (not stuck behind last round's "already completed, 404" guard),
        and actually re-marked it complete — confirmed it correctly
        re-triggered back-pay (`backdated`) exactly as a fresh completion
        would. Restored `service_start_date` back to its real value
        (2025-04-01) afterward, which correctly auto-reset the gates
        again to match — left in a clean, well-defined state, ready for
        CGS to mark complete against the real schedule. 42/42 clean,
        scope stayed inside `app/Modules/Haziq/`.
  - [x] **GA never had any of the "edit service_start_date → Stage Gate
        recomputes" wiring GRA had — its correction form was documents
        only** (2026-10-20) — user, restating the requirement after
        apparently testing against a GA application: "patutnya timeline
        ngan allowance pun ikut update by new deadline and grace period
        status completion pun reset after tukar service date." The
        previous several rounds built and verified this entirely on
        `GraApplicationController`; `GaApplicationController::
        showCorrection()`/`storeCorrection()` only ever handled document
        replacement — there was no way for a GA student to correct
        `service_start_date` (or any GA Detail/profile field) after
        submission at all, so nothing could "not update" because there
        was nothing to trigger it with. Mirrored GRA's pattern exactly:
        `storeCorrection()` now takes `StageGateEngine $stageGates`,
        validates and updates `GaApplicationDetail` (department_unit,
        purpose, proposed_hours_per_week, service_start_date,
        service_end_date, proposed_allowance) and the student's
        `GraGaStudentProfile` (level_of_study, programme, admission_date)
        — always editable, every time, same "every student lain lain"
        philosophy GRA already follows — captures
        `service_start_date` before the update and calls
        `recomputeApplication()` when it actually changed, synchronously.
        `showCorrection()` now loads and passes `$detail`/`$profile` for
        pre-filling. Added a "GA Details" section to
        `ga_application/correction.blade.php` with all the same fields,
        pre-filled from the existing record. Verified live end to end on
        disposable data (application #37, deleted afterward — no real GA
        application exists yet to test against): built an approved GA
        application with a generated Stage Gate schedule and a genuinely
        completed Stage 1, logged in as `student@utp.edu.my`, submitted a
        real correction through the actual HTTP route changing
        `service_start_date` from 2025-04-01 to 2025-06-01 — confirmed
        the deadline moved to the new date and Stage 1's prior completion
        reset to `not_recorded`/`withheld`/`grace_expired` under the new
        schedule, exactly matching GRA's already-verified behaviour.
        42/42 clean, scope stayed inside `app/Modules/Haziq/`.
  - [x] **Grace period expired one month too early — the original
        walkthrough's own worked example (1 April start → reminders 1
        Jul/1 Aug/1 Sep/1 Oct, expiry only 1 Nov) was never actually
        matched by evaluateGate()'s boundary, and no reminder emails were
        backfilled when a correction jumped straight into an
        already-elapsed grace window** (2026-10-21) — user: "aku silap
        patutnya month terakhir grace period habiskan, month seterusnya
        baru termination atau allowance tak eligable tu, betulkan logic
        ni balik" + "aku nak email yang patutnya send 1 July, 1 August, 1
        September, 1 october tu muncul gak... kepada cgs and student
        only." Two coupled bugs, both against the exact numbers in that
        original example (grace_end_month=6 from a 1 April start =
        grace_end_date 1 Oct):
        1. `evaluateGate()`'s match() treated `today < grace_end_date` as
           still-in-grace, so on grace_end_date itself (1 Oct) it had
           already flipped to `grace_expired` — one reminder short, and
           termination/withheld a month early. Fixed to
           `today < grace_end_date + 1 month`, so the grace_end_date's own
           month is still a reminder month and only the month *after* it
           expires — `statusAsOf()` (the AllowancePaymentLog backfill)
           got the identical +1 month fix so the payment ledger stays
           consistent with the displayed timeline.
        2. `sendMonthlyGraceReminder()` only ever sent one reminder for
           "this month" — fine for the daily job evaluating in real time,
           wrong for `recomputeApplication()`, which resets
           `last_reminder_sent_at` to null and can land several months
           into an already-elapsed grace window in one jump (a corrected
           service_start_date whose grace started months ago). Rewrote it
           to loop every calendar month from the month after the last one
           sent (or the deadline's own month, if none yet) through this
           month — same backfill shape `ensureMonthlyLogs()` already used
           for payment logs, now applied to the reminder emails too.
           Audience unchanged (CGS + student only, per the already-
           confirmed "noti kat cgs dan student" rule).
        Verified live against application #25's real data: set
        `service_start_date` to 1 April 2026 through the real correction
        route (today being evaluated against is 2026-10-21) — confirmed
        Stage 1 now reads `grace_period`/`eligible` (not
        `grace_expired`/`withheld`), with `deadline=1 Jul 2026`,
        `grace_end=1 Oct 2026`, and confirmed exactly 4 `StageGateGraceReminder`
        notifications landed for the student (one per backfilled month —
        Jul/Aug/Sep/Oct) plus the matching 4 to CGS, with 0 jobs left
        pending and no new failures. 42/42 clean, scope stayed inside
        `app/Modules/Haziq/`.
  - [x] **Two more fixes to the same grace-period mechanics: reminders
        were skipped entirely when a correction jumped straight past
        grace into withheld/terminated, and withheld/termination emails
        were going to Admin/Supervisor/Senior Director** (2026-10-21) —
        user: "email ni aku nak even witheld or terminated, email reminder
        berapa bulan dari start grace period (deadline) sampai month
        terakhir grace period pun ada jugak email tu... kenapa admin,
        supervisor, senior director dapat gak. patutnya tayah email tu
        student and cgs only."
        1. `evaluateGate()` only called `sendMonthlyGraceReminder()` while
           `timeline === 'grace_period'` — a correction whose new
           deadline+grace had already fully elapsed landed straight in
           `grace_expired` on the very next evaluation, skipping every
           reminder that window was ever owed. Now calls it for both
           `grace_period` and `grace_expired`. `sendMonthlyGraceReminder()`
           itself got a cap added — `min(this month, grace_end_date's
           month)` — so it still only ever backfills real reminder
           months, never invents one for a month after grace had already
           ended.
        2. `applyGraceExpiry()`'s two consequence notifications
           (`StageGateAllowanceWithheld`, `StageGateCandidatureTerminationTriggered`)
           switched from `notifyEveryone()` to `notifyCgs()` — reversing
           the "semua user" rule confirmed twice earlier in this session,
           but only for these two; `recordCompletion()`'s `StageGateCompleted`
           still uses `notifyEveryone()`, untouched, since completion
           wasn't part of this correction.
        Verified live against application #25's real data: set
        `service_start_date` to 2026-02-01 (deadline 1 May, grace_end 1
        Aug — today being 2026-10-21, well past both) through the real
        correction route. Confirmed Stage 1 landed directly in
        `grace_expired`/`withheld` on this one evaluation, with exactly 4
        backfilled `StageGateGraceReminder` notifications to the student
        (May/Jun/Jul/Aug — capped at the grace_end month, not extending
        into Sep/Oct which were already past grace), the same 4 to each
        of the 3 CGS team members, and the `StageGateAllowanceWithheld`
        notification landing on student + CGS only — a direct query for
        Admin/Supervisor recipients across both notification types in
        this window returned 0. 42/42 clean, scope stayed inside
        `app/Modules/Haziq/`.
  - [x] **Final word on Stage Gate notification audience: everyone, not
        CGS+student — reversing the last two rounds — plus a real,
        separate bug: a terminated student hit a blank form instead of
        their own existing application** (2026-10-22) — user: "yang
        dapat email reminders, allowance withheld, allowance recovered,
        terminated, appeal approved ni semua semua au student, admin,
        cgs, supervisor, senior director ni stakeholder die. ingat semua
        yang dapat email and notification ni." This audience question
        flipped narrow/broad/narrow/broad across four separate
        corrections in one day — this is the one to trust going forward.
        `applyGraceExpiry()`'s two notifications and
        `sendMonthlyGraceReminder()` switched back from `notifyCgs()` to
        `notifyEveryone()`; `notifyCgs()` itself (and the now-unused
        `Notification` facade import) were deleted — nothing called it
        any more. "Allowance recovered" needed no separate change: it's
        the same `StageGateCompleted` notification `recordCompletion()`
        already sends via `notifyEveryone()`, untouched this whole time.
        "Appeal approved" was genuinely student-only before — added a
        `forStudent` flag to `CandidatureAppealApproved` (same pattern
        every other Stage Gate notification already uses) and a shared
        `CandidatureAppealController::stakeholders()` helper (Admin + CGS
        + the student's assigned Supervisor), replacing the inline
        recipient-gathering `store()` already had and reusing it for the
        approval notification too.
        Second, unrelated bug found from the same message: "dekat my
        application and gra application die cam ada form kosong, kau
        biar je wujud detail yang student pernah isi cuma taleh edit,
        boleh edit lepas appeal approve ah." `activeNewApplication()`
        only redirected `create()`/`store()` to the student's existing
        application (`showMine()`) while `candidature_status = 'active'`
        — its own docblock still said a terminated student "has to submit
        a fresh application to continue," which was true under the
        old, now-removed TERMINATION_APPEAL_FIELD mechanism and was never
        updated when that was removed two rounds ago. A terminated
        student landed on a genuinely blank create form instead of their
        own data. Dropped the `candidature_status` filter entirely — a
        terminated appointment now redirects to `showMine()` the same as
        an active one, which already shows the real data read-only
        (`canStudentEdit()` already returns false while terminated) and
        becomes editable again on its own once reinstated — no new state
        to track.
        Verified live end to end against application #25's real data:
        pushed Stage 2 into genuine termination via a real
        `service_start_date` correction (1 Jan 2025), confirmed
        `/gra-application/new` now redirects to `/gra-application/25/mine`
        instead of rendering blank, confirmed that page shows the real
        project title with no Edit link while terminated, confirmed
        `StageGateGraceReminder`/`StageGateAllowanceWithheld` reached
        Admin + all 3 CGS roles + the assigned Supervisor + the student
        in this window, submitted a real appeal, approved it as
        `director@utp.edu.my`, confirmed `CandidatureAppealApproved`
        reached the same full stakeholder set, candidature flipped back
        to `active`, and the Edit link reappeared on the mine page.
        42/42 clean, scope stayed inside `app/Modules/Haziq/`.
  - [x] **A stale, already-approved appeal from a PRIOR termination
        silently blocked the appeal banner from offering a fresh one
        after a later service_start_date correction re-terminated the
        same application** (2026-10-22) — this was the real explanation
        for the previous round's "why isn't it showing terminated"
        confusion: it had been reinstated by my own verification appeal,
        then re-terminated by a later date correction, and the banner's
        `@if (!$appeal) / @elseif (pending) / @elseif (rejected)` chain
        had no branch for `approved` — so with an approved appeal on
        record it silently rendered nothing at all, no link, no message.
        User: "patutnya bila tukar semua bende reset faham ak tak kisah
        sebelum ni dah pernah appeal ke tak. appeal data before this
        patutnya tak visible dah." Fixed in
        `stage_gate/index.blade.php`: now only a *pending* appeal blocks
        the fresh "Appeal this termination" link; both `approved` and
        `rejected` fall through to show it (rejected also keeps its own
        "last appeal was rejected" sub-message, approved shows nothing
        extra — it just offers the link, since reaching this banner at
        all already means candidature_status is terminated right now,
        from a termination the old decision never spoke to).
        `CandidatureAppealController::create()`/`pendingAppeal()` never
        had this bug — they already only checked for `status = 'pending'`
        — so no appeal data was ever actually deleted or needed to be;
        this was purely a display gap on the banner. Verified live
        against application #25's real data: with appeal #7 (approved,
        from the previous round's verification) still on record, pushed
        a fresh correction (service_start_date 2025-01-02) that
        re-terminated the same application, and confirmed the banner now
        correctly shows "Appeal this termination" again instead of
        rendering blank. 42/42 clean, scope stayed inside
        `app/Modules/Haziq/`.
  - [x] **Verified, not a bug: the GRA correction form already pre-fills
        Section B through Documents from the existing record** (2026-10-22)
        — user: "setiap kali nak edit form detail kita pernah isi tak
        kisah ah masa mula mula apply en, semua detail akan wujud part b
        sampai lah yang document part tu... jangan bila tekan edit ada
        data hilang pulak." Checked the real rendered correction form for
        application #25: Section B/C were already correctly pre-filled
        with real values (no code change needed — `showCorrection()`/the
        view already use `old(field, $detail->...)` throughout). Section
        D/E/F rendered blank, but that's because this profile genuinely
        has 0 `AcademicQualification`/`WorkExperience`/`Publication` rows
        right now, not a display bug — confirmed the round-trip itself
        works by submitting a real academic row through the actual
        correction route, reloading the page, and seeing it correctly
        echoed back pre-filled, then removing that disposable test row
        again. Documents section also correctly shows "Already uploaded:
        [filename]" with a view link for every existing document.
        Flagged transparently to the user: every one of this session's
        own test corrections on application #25 was submitted via raw
        curl POSTs that never included `academic[]`/`experience[]`/
        `publication[]` fields, and `storeRepeatables()`'s delete-then-
        recreate means any of those submissions would have wiped
        whatever was in Section D/E/F at the time — a real student using
        the real browser form would never lose this data (the browser
        correctly resubmits whatever's pre-filled), so this isn't a
        product bug going forward, but it may explain why this specific
        profile's D/E/F history reads empty now. No way to recover
        contents that were never captured anywhere else. 42/42 clean, no
        code changed, scope unaffected.
  - [x] **Rebuilt Allowance Eligibility from a flat per-month log listing
        into a cumulative, student-centric dashboard, plus an Excel
        export** (2026-10-23) — user, across two messages: "every month
        admin, cgs, supervisor, and director can see who get the
        allowance... student name, sebelah die allowance and status.
        allowance bila withheld je, status witheld merah ah. every month
        die akan bertambah base on allowance die... [worked example:
        2000/4000/6000 across three withheld months, then 8000 green the
        month it's completed]... student akan nampak die nye je yang
        lain semua student ah" + "kalau tak witheld, amount die sama je...
        pastu boleh filter...eligable, witheld, terminated...terminated
        pulak auto merah total allowance tu bila dah appeal baru
        eligable with amount allowance die [no accumulation carried
        over]...pastu admin sampai director leh dowload excel every
        month yang student active je akan keluar."
        `AllowanceController::index()` now computes, per student/
        application for the selected month, a *running cumulative*
        amount — not the flat per-row `amount` the old table showed —
        by walking that application's whole `allowance_payment_logs`
        history up to the selected month: consecutive `withheld` or
        `forfeited` months each add one more monthly allowance to the
        running total; the one month a withheld gate is actually marked
        complete (`backdated`, previous row `withheld`) settles the
        whole run plus that month in one lump sum, then resets; every
        other case (raw `eligible`, steady-state `backdated` after a
        settlement, or `eligible` after a termination is appealed) shows
        a flat, non-accumulating amount. This one computation correctly
        produces both of the user's worked examples without any special-
        casing for termination vs withholding — it falls out naturally
        from `backPay()` only ever producing `backdated` (never
        `eligible`) while an appeal reinstatement goes straight to
        `eligible` (never passes through `backdated`), which is exactly
        why `reinstateCandidature()` was right not to touch
        `allowance_status` two rounds ago.
        Real statuses collapse to the three the user actually filters/
        sees by (`eligible`+`backdated` → Eligible/green,
        `withheld` → Withheld/red, `forfeited` → Terminated/red) via a
        `DISPLAY_STATUS_MAP`, reusing the existing `status-badge` colour
        classes as-is. Moved `allowance.index` out of the approver-only
        route group into the same one Stage Gate monitoring already uses
        (Admin/CGS/Supervisor/Senior Director/Student), scoped server-
        side exactly like Stage Gate (student sees only their own row via
        `student_id`, Supervisor via their own supervisees). Added
        `AllowanceController::export()` (Admin/CGS/Supervisor/Senior
        Director only, not students) and `Exports\AllowanceEligibilityExport`
        (`maatwebsite/excel`, already a dependency, following the same
        `FromCollection`/`WithHeadings` pattern Nureen's attendance
        template export already uses) — the download reuses the exact
        same computed rows as the screen, filtered to
        `candidature_status = 'active'` only, so a terminated student
        never appears in it at all. Added a "My Allowance" sidebar link
        for students alongside "My Stage Gate".
        Verified live end to end on disposable applications (#38, #39,
        both deleted afterward): built the exact withheld/withheld/
        withheld/backdated/backdated sequence and confirmed the real
        rendered page shows 2000 → 4000 → 6000 → 8000 (green) → 2000
        across the five months, matching the worked example exactly;
        built a forfeited/forfeited/forfeited/eligible sequence and
        confirmed 2000 → 4000 → 6000 (red, terminated) → 2000 (green,
        no carry-over); confirmed the status filter narrows correctly;
        confirmed the student login sees only their own row with no
        Download Excel link; confirmed Supervisor sees the link;
        downloaded the real .xlsx for a month with an active student
        (included, correct amount/status) and a month with only a
        terminated one (correctly empty). 42/42 clean, scope stayed
        inside `app/Modules/Haziq/`.
  - [x] **Two real bugs found checking the new Allowance Eligibility table
        against application #25's real data: the payment log backfill
        anchor was wrong, and a stale 'backdated' row survived a later
        date correction** (2026-10-23) — user: "patutnya 24000 merah
        status witheld skang allowance tu... cuba check database allowance
        die dah witheld dari 1 novembver 2025 sebab grace period ending
        untuk rpd october 2025 tu." Checked: application #25 had exactly
        one `AllowancePaymentLog` row (October 2026, status `backdated`)
        — not the ~18 months it should have had since the appointment
        started.
        1. `ensureMonthlyLogs()` anchored its backfill start on
           `submitted_at`, not `service_start_date` — fine normally
           (they're usually close), wrong here because this application's
           `submitted_at` had been reset much later (2026-10-14) than its
           real schedule's own start (service_start_date 2025-04-01),
           which silently skipped backfilling every month the schedule had
           actually been running already. Also rewrote the backfill to
           fill *any* missing month in range rather than only ever
           extending forward from the latest existing row — the old
           "cursor = max(payment_month) + 1" approach could never reach
           backward past an already-existing later row, which is exactly
           what left that one October row stranded with nothing before it.
        2. That October row's `backdated` status was itself stale — a
           leftover from an earlier (since-reset) "Mark Complete" test
           this session. `recomputeMonthlyLogs()` deliberately exempts
           `backdated` rows from being touched by a date correction (a
           real back-payment shouldn't be silently rewritten) — correct
           for `refreshAllowanceLogs()` (candidature appeal approval,
           which never touches completion), but wrong for
           `recomputeApplication()`, which resets every gate's
           completion_status back to `not_recorded` right before calling
           it — at that point a `backdated` row is no longer a real,
           standing payment either. Added an `$includeBackdated` parameter;
           `recomputeApplication()` now passes `true`.
        Also found and fixed, inline in `AllowanceController::rowFor()`:
        the cumulative display logic initially detected "the settlement
        month" by looking for a `withheld`-then-`backdated` transition
        between adjacent rows, which doesn't exist any more once
        `backPay()` runs — it restates the *whole* withheld run to
        `backdated` in one bulk update, not just the latest row. Switched
        to using Stage 1's own `completion_date` as the settlement-month
        marker directly, and fixed a related miscount: every month up to
        *and including* the settlement month still has to accumulate
        into the running sum (they were all just bulk-restated to
        `backdated` too), not reset to a flat amount the instant a row
        isn't the exact settlement month.
        Verified live against application #25's real data: after both
        fixes, the table now correctly backfills eligible (Apr–Oct 2025)
        then withheld (Nov 2025–Oct 2026, 12 months) and shows RM 24,000
        withheld/red for October 2026 — exactly matching the user's
        expectation. Logged in as `cgs@utp.edu.my` and actually marked
        Stage 1 complete; the table then correctly showed RM 24,000,
        eligible/green for the same month (the completion-month lump sum
        — 11 prior months plus the completion month's own allowance).
        Settlement timing was genuinely ambiguous between the worked
        example's wording and the existing, already-confirmed backPay()
        design — asked directly rather than guess a third time. Confirmed
        2026-10-23: same month (what's already built) is correct — the
        completion month itself immediately shows the lump sum, no
        further change needed. 42/42 clean, scope stayed inside
        `app/Modules/Haziq/`.
  - [x] **New: Admin/CGS confirming an actual allowance payout (with
        proof) is now a distinct action from Stage Gate completion —
        the running total keeps growing every month it isn't, resetting
        only once it is** (2026-10-23) — user: "cgs admin dua dua boleh
        buat die mark complete untuk setiap student punya allowance
        tu... kalau cgs or admin mark complete payment with bagi proof
        of payment which akan bagi noti akan semua pihak admin, cgs
        director, supervisor and student... pastu next month nye akan
        reset balik 2000. kalau tak complete... jadi 26000... pastu en
        director, supervisor, admin, cgs boleh tengok siapa tak dapat
        payment lagi... berapa bulan witheld pun boleh tengok... die
        akan reset everything back kalau student tukar service start
        date." This reworks the previous two rounds' design: completing
        Stage 1 (RPD) only ever *reveals* the lump sum owed
        (`allowance_status` flips to `backdated`) — it no longer
        settles it on its own.
        New table `allowance_payment_confirmations` (confirmed_month,
        amount, proof_document_id, confirmed_by, confirmed_at) and model
        `AllowancePaymentConfirmation` — a student's debt can be settled
        more than once over their appointment, so this is its own table,
        not a column on `allowance_payment_logs`. New
        `AllowanceController::confirmPayment()` (Admin or
        `non_exec_cgs` only — same shape as Stage Gate's own
        `markComplete()`): validates a required proof upload via the
        existing `DocumentStore` (doc_type "Proof of Payment"), confirms
        the *current* month's running total (the only amount Admin/CGS
        could actually be paying today), and notifies the full
        stakeholder set (Admin + CGS team + the student's own assigned
        Supervisor) plus the student via new `AllowancePaymentConfirmed`
        (`forStudent` flag, same pattern every other Stage Gate
        notification uses).
        `AllowanceController::rowFor()` rewritten: `withheld`,
        `forfeited`, AND `backdated` now all accumulate into the running
        sum identically (previously `backdated` auto-reset the month
        after Stage 1's own `completion_date` — removed entirely) —
        accumulation only resets the month after a row whose month
        matches a real `AllowancePaymentConfirmation`. Display status
        gained a fourth bucket, `payment_pending` (amber, new
        `.status-badge.payment_pending` tone) — a `backdated` gate with a
        still-growing, unconfirmed total — distinct from `withheld` (RPD
        itself not done) and `eligible` (never violated, confirmed paid,
        or reinstated by appeal); added to the status filter alongside
        the existing three. Added a "Months Withheld" column (the
        `monthsAccumulating` counter already computed during the walk)
        and a "Mark Payment Complete" upload form per confirmable row,
        visible only to Admin/`non_exec_cgs`.
        `StageGateEngine::recomputeApplication()` now also deletes an
        application's `AllowancePaymentConfirmation` rows — a
        confirmation was a real action against the OLD schedule's
        running total, which a service_start_date correction
        invalidates the same way it already resets completion status
        and payment logs.
        Verified live end to end against application #25's real data
        (currently showing the real RM 24,000 owed since Nov 2025, Stage
        1 already completed): confirmed the table read `payment_pending`
        (amber) with "Months Withheld" = 12 before any confirmation;
        logged in as `cgs@utp.edu.my`, uploaded a real proof file through
        the actual route, and confirmed the status flipped to `eligible`
        (green) for October, the confirmation row was created with the
        correct RM 24,000, and all 6 stakeholders (Admin, all 3 CGS
        roles, Supervisor, student) received
        `AllowancePaymentConfirmed`. Added a disposable November log row
        and confirmed it correctly reset to a flat RM 2,000 rather than
        continuing to accumulate (removed afterward). Confirmed
        Supervisor cannot reach `confirmPayment()` (403) and the button
        doesn't render for them. Re-downloaded the real Excel export and
        confirmed it now reads "Eligible" for the confirmed month. 42/42
        clean, scope stayed inside `app/Modules/Haziq/`.
  - [x] **Verified, not a bug: CGS/Admin already cannot mark payment
        complete for a withheld or terminated student, only for
        payment_pending** (2026-10-27) — user: "if the student not
        eligable or terminated, cgs or admin cannot mark complete
        unless they are eligable." This was already built into the
        previous round's `confirmable` flag
        (`rowFor()`: `confirmable = displayStatus === 'payment_pending'`)
        and enforced on both sides — the view only ever renders the
        "Mark Payment Complete" form when `confirmable` is true, and
        `confirmPayment()` itself `abort_unless($row['confirmable'], 404,
        ...)` regardless of what the UI shows. Verified live rather than
        assumed: built two disposable applications (withheld, forfeited/
        terminated — deleted afterward) alongside the real application
        #25 (eligible, already payment-confirmed), confirmed via the real
        rendered page that no "Mark Payment Complete" button appears for
        either the withheld or the terminated row, and confirmed a direct
        POST to `confirm-payment` against both (bypassing the UI
        entirely) returns 404. No code change needed. 42/42 clean, scope
        unaffected.
  - [x] **Found a real, serious bug live-testing the termination/appeal
        scenario: approving an appeal retroactively rewrote every past
        terminated month to 'eligible', and the transition month got a
        full flat payment regardless of what day the appeal landed on**
        (2026-10-28) — user: "kalau terminated, bulan after stage 2 or
        stage 3 nye grace period... takkan eligable. but katakan... die
        active balik after appeal process approve, on random date...
        student ni active balik 13 hari bulan on some month... die mesti
        tak dapat allowance 13 hari tu, so system let the cgs decide
        bulan tu die dapat berapa je... bulan berikutnya tu like normal
        ah dapat 2000 cam biasa... bulan kene terminated takkan dapat
        allowance."
        Reproduced live first: built a disposable terminated application
        (3 months forfeited), approved a real appeal for it, and found
        `refreshAllowanceLogs()` had flipped *all three* historical
        months to `eligible` — not just the current one — because it
        recomputed the application's whole history using the
        now-active candidature_status in one pass. That directly
        contradicted "a terminated month is never paid" — forfeited
        months were getting silently back-paid.
        Replaced `StageGateEngine::refreshAllowanceLogs()` (deleted)
        with a far narrower `flagReinstatementMonthForReview()`, called
        from `CandidatureAppealController::decide()`'s approve branch:
        it touches *only* the current month's own row, and only if it's
        still `forfeited` — flips it to `review_required` instead of
        guessing a full month's pay, since the appeal could have landed
        on any day. Every month before it is never touched again, full
        stop.
        New `AllowanceController::setReviewAmount()` (Admin/non_exec_cgs,
        same authorization as `confirmPayment()`) lets CGS type in the
        actual prorated amount for that one month; a new "Set Amount"
        form renders on the table for any `review_required` row. New
        raw status `determined` for the result — found and fixed a
        second bug while verifying this: the first attempt reused
        `backdated` for CGS's determined amount, which fell into
        `rowFor()`'s generic accumulate branch and silently pulled the
        *prior forfeited months'* total back into it (RM 5,133.33
        instead of the RM 1,133.33 CGS actually typed in) — exactly the
        "terminated months get paid" bug this whole round exists to
        prevent, just reintroduced through the back door. `determined`
        gets its own branch in the walk: starts its own fresh pending
        total from CGS's figure alone, never folded onto whatever
        forfeited run preceded it, and still flows into the existing
        `confirmPayment()` step from there (reusing `displayStatus ===
        'payment_pending'`, so no change needed to that action itself).
        New `.status-badge.review_required` tone and filter option.
        Verified live end to end on a fresh disposable application
        (deleted afterward): 2 forfeited months stayed `forfeited`
        permanently after the appeal; the 3rd (reinstatement) month
        correctly read `review_required`; set a prorated RM 1,133.33 via
        the real route and confirmed the table showed exactly that (not
        RM 5,133.33) with `payment_pending`; confirmed it with a real
        proof upload and saw it settle to `eligible`; added a disposable
        November row and confirmed it read a fully normal flat RM 2,000,
        matching "bulan berikutnya tu like normal ah." 42/42 clean,
        scope stayed inside `app/Modules/Haziq/`.
  - [x] **Corrected the termination display model entirely — it never
        accumulates, both the entry AND exit transition are CGS's
        judgement call, and a determined amount is deferred to the
        following calendar month, not shown on its own** (2026-10-28) —
        user: "the debt not growing lah, bila dah terminated, bulan
        terminated will not get allowance... on bulan die terminate or
        any month die punya appeal approve, bulan tu die akan nampak
        allowance blank dulu, selagi cgs tak tentukan bulan tu die dapat
        berapa. biasa kan allowance bulan sebelum dapat first day of the
        first month tulah, so bulan berikutnya die akan nampak allowance
        pending with the amount yang cgs setupkan, bulan berikutnya tu
        macam normal ah." This reverses the "terminated accumulates like
        withheld" design from a few rounds ago — scoped strictly to
        termination; Stage 1 (RPD) withheld/backdated's own accumulate-
        then-settle mechanic is untouched.
        An ongoing terminated month now shows blank (no number at all),
        never a running total, and is never back-paid — `forfeited` maps
        straight to blank/`terminated`. Both edges of a termination are
        now a CGS judgement call, not just the exit (appeal approval)
        side from two rounds ago: `StageGateEngine::ensureMonthlyLogs()`
        now tracks the previous month's status through its own backfill
        and flags the exact month a termination *starts* in
        `review_required` instead of defaulting it to `forfeited` —
        termination can trigger any day of a month, same reasoning as
        the exit side already had. `AllowanceController::rowFor()`
        rewritten to track the previous row's raw status (not just a
        running sum): a `determined` row (CGS's set amount) displays
        blank on its own row and only shows up as `payment_pending` on
        the row immediately after it, matching "bulan sebelum dapat
        first day of the [next] month" — and that deferred payout now
        shows on the very next calendar month regardless of whether that
        month is itself still terminated, not only when it happens to
        land on an eligible one.
        Two more real bugs found live-testing this, both now fixed:
        1. A brand-new application's first-ever backfill (an 18+ month
           gap in one pass) used today's `candidature_status` for every
           single month, retroactively marking months from *before* the
           real termination as forfeited too. Fixed by anchoring on the
           earliest forfeited gate's own `grace_end_date + 1 month` (the
           same boundary `evaluateGate()` uses) — only months at or after
           that point are affected by `$terminated`, regardless of what
           today's status says.
        2. The deferred payout was only showing when the *following* row
           happened to already be `eligible` — so a `determined` entry
           month immediately followed by more ongoing termination showed
           nothing at all instead of the one owed payout. Fixed by
           checking `$previousStatus === 'determined'` first, ahead of
           every other branch, so the payout always lands on the very
           next month and then correctly reverts to blank the month
           after that.
        Verified live end to end on a disposable application (deleted
        afterward), including a genuine first-time `stage-gate:evaluate`
        run (not just manually seeded rows) to trigger a real, fresh
        termination: 19 months of real history correctly stayed
        `eligible` and only the 20th (the real transition month) came up
        `review_required`/blank; set RM 666.67 via the real route;
        confirmed it stayed blank that same month; confirmed the very
        next month showed RM 666.67 `payment_pending` even though
        candidature was still terminated; confirmed the month after that
        reverted to blank `terminated` with nothing carried over.
        Separately re-confirmed application #25's unrelated Stage 1
        withheld/payment-pending flow still reads exactly as before
        (RM 24,000, `payment_pending`) — untouched by this restructuring.
        42/42 clean, scope stayed inside `app/Modules/Haziq/`.
  - [x] **Confirm dialogs + mandatory reject reason, module-local**
        (2026-10-11) — every submit action (student's Submit
        Application/Extension, Admin's Verify & Advance, every
        Approve/Reject, GA's Pass/Fail) now confirms before actually
        submitting, and Reject/Fail additionally refuses to submit at all
        until a reason is typed — enforced client-side (JS, for the prompt)
        and server-side (`remarks`/`interview_notes` validated
        `required_if:decision,reject`, the real enforcement). Built as this
        module's own `haziq::partials.decision_form` +
        `decide_scripts.blade.php`, **not** a change to Core's shared
        `x-core::decision-form` — that component is used by every other
        module's approval screens (Travel, GA Extension, Examiner
        Nomination...), and this behaviour is specific to this module's
        request, not something to impose on the other five people's UX
        without asking. New `ApplicationRejected` notification (mail +
        database) carries the actual reason to the student, since Core's
        `ApplicationDecided` — which still fires as normal on every
        decision — never has and this module doesn't touch it; sent
        alongside, not instead of. Verified in Tinker through the real
        queue worker: reject without a reason is refused with a validation
        error and the application stays untouched; reject with a reason
        succeeds, and both the generic `ApplicationDecided` and the new
        `ApplicationRejected` (with the actual reason text) land in the
        student's feed. Separately re-confirmed reapplying after a
        rejection still works once the unrelated in-flight application
        used to test it was accounted for.

- [x] **GA Application** (`ga_application`) — Student → Admin (eligibility) →
      Research Centre (Pass/Fail + notes) → Admin (final)
  - [x] `ga_application_details` + interview result
  - [x] `Research Centre` role — added as
        `App\Modules\Haziq\Support\Roles::RESEARCH_CENTRE`, a plain string,
        **not** in `Support\Role` (Role is VARCHAR-backed by design; no Core
        edit needed unless another module needs this role too)
  - [x] GA Offer Letter + Program Offer Letter on approval
  - [x] GA Extension confirmed out of scope — stays Nureen's `ga_extension`
        entirely; this module never touches it
  - [x] Document-only correction flow, via the same shared
        `HandlesApplicationCorrections` trait GRA uses — no section-level
        flagging (GA has no equivalent of Sections B–F), no CGS stage, no
        applicant list/detail screen. 2026-10-11's spec was explicitly titled
        "GRA Application System", so GA's scope was deliberately left alone
        this round beyond keeping it working against the renamed shared table.

- [x] **Stage Gate Monitoring** — not a `WorkflowModule`, per the plan: its
      own tables (`stage_gate_rules` seeded reference data,
      `stage_gates` per-application schedule) plus a daily scheduled command
      (`stage-gate:evaluate`, registered from `Haziq\ModuleProvider` rather
      than a line in the shared `routes/console.php`). `Support\StageGateEngine`
      is the only writer of `completion_status`/`timeline_status`/`allowance_status`,
      the same one-writer discipline `WorkflowEngine` enforces for `applications`.
  - [x] Deadlines/grace periods computed once from `admission_date` at
        approval time; CGS marking completion late never moves them —
        verified in Tinker with a student 32 months post-admission
  - [x] Stage 1 (RPD) recoverable: grace expiry → `withheld`; completing
        later → **all** withheld months backdated, no cap
  - [x] Stages 2+ not recoverable: grace expiry → `forfeited` +
        `stage_gate_termination_triggers` row raised
  - [x] **Grace-period reminders now go to CGS as well as the student**
        (2026-10-12) — `StageGateGraceReminder` takes a `forStudent` bool
        (same pattern as `StageGateCandidatureTerminationTriggered`); the
        monthly reminder in `StageGateEngine::sendMonthlyGraceReminder()`
        now notifies the student (2nd person, what's at stake for them) and,
        separately, every CGS-team user (3rd person, who is overdue and
        needs their action) — CGS is who actually records completion, so
        they need to know before grace expires, not just after. Verified
        live in Tinker through the real queue worker against two backdated
        test applications: a Stage 1 sat in its grace period correctly
        notified both the student and all three seeded CGS-team accounts
        (Non-Exec, Manager, Senior Director), while a Stage 1 already past
        grace only notified the student with the withheld notice (no CGS
        copy — that path is unchanged).
  - [x] **This appointment's own GRA/GA status now actually terminates on a
        Stage 2+ grace expiry** (2026-10-12) — previously
        `stage_gate_termination_triggers` only raised a record with no
        consumer (see below); CGS/GRA ownership of *this appointment's
        funding continuation* is squarely Haziq's own scope (distinct from
        the student's actual postgraduate candidature/enrolment, which
        stays nobody's decision here — see next item), so
        `StageGateEngine::terminateCandidature()` now flips a new
        `candidature_status` column (`gra_application_details` /
        `ga_application_details`, default `active`) to `terminated` the
        same moment the trigger is raised. `GraApplicationController::
        activeNewApplication()` excludes terminated rows, so the student can
        submit a brand new 'new' GRA application immediately — same
        reapply-after-rejection pattern, just keyed off this flag instead of
        `status`. The old application's row is never deleted — "terminated"
        is a label on it, not an erasure; `gra_application/show.blade.php`
        banners it for the student. Deliberately does **not** write
        `applications.status` — stays entirely inside this module's own
        tables, so `WorkflowEngine` remains the only writer of that column.
        Verified live: a GRA and a GA application both backdated so their
        Stage 2 grace period had lapsed — both correctly flipped to
        `terminated`, and `activeNewApplication()` then returned `null`
        (previously blocked) so `create()` served a blank form instead of
        redirecting back to the dead application.
  - [ ] **`stage_gate_termination_triggers` still has no consumer for actual
        candidature/enrolment status.** The item above only ends *this GRA/
        GA appointment*; the student's underlying postgraduate enrolment is
        still deliberately untouched — see the migration's docblock. It
        raises the trigger and notifies Admin; whichever module ends up
        owning `rpd_dismissal` or `candidacy_dismissal` (both still "not
        started" per the table above, and provisionally Chloe's per the
        owner table) needs to actually act on it.
  - [x] **Only CGS (the `cgs_review` role, `non_exec_cgs`) records
        completion now** (2026-10-12) — `StageGateController` previously let
        Admin act too; narrowed to match "every stage's completion is
        indicated by CGS" — deliberately the single `non_exec_cgs` role, not
        the broader 4-role CGS team `Role::isCgs()` covers, since Senior
        Director is named as a separate *viewing-only* audience alongside
        CGS. Admin and Senior Director now see the screen read-only, same as
        Supervisor already did. Verified live: Senior Director's
        `markComplete` POST now 403s; CGS's succeeds and still correctly
        back-pays a previously-withheld month to `backdated`.
  - [x] **Dashboard added to `/stage-gate`** (2026-10-12) — didn't exist
        before; the screen was a filterable list only. Now opens with a
        bottleneck bar chart (students past deadline, grouped by stage —
        same Chart.js/`core::dashboard.partials.chartjs` pattern as GRA
        Applicants' "Pending by Stage"), Active/Terminated/Allowance-
        Suspended stat cards, and a list of currently-suspended students
        with their stage and grace-end date. Same role scope as the
        existing list (Admin/CGS/Senior Director see everyone, Supervisor
        sees only their own supervisees) via a new shared
        `StageGateController::scopedQuery()` the list and the dashboard
        both build on. Verified rendering for all four roles live.
  - [x] **Fixed, found via this phase's live testing:** `LetterIssued`
        called `MailMessage::attachFromStorageDisk()`, which doesn't exist
        on that class in this Laravel version (it's `Attachment::
        fromStorageDisk()` passed to `MailMessage::attach()`) — every GRA
        Offer/Admission/Extension and GA Offer/Program-Offer letter email
        was silently failing in `failed_jobs` the moment Senior Director (or
        GA's final Admin stage) approved, with no visible error to the
        approver since the approval itself still succeeded. Found while
        approving a test application through to Senior Director to test the
        Stage Gate schedule generation above. Fixed and verified live
        through the real queue worker: the same approval that failed before
        now completes with the generated PDF actually attached.
  - [x] **Student can now track their own Stage Gate progress**
        (2026-10-13) — `/stage-gate` previously 403'd for Role::STUDENT
        entirely. Split the route: the GET is now open to every role (same
        "open route, controller scopes by role" pattern TA Hours already
        uses) while the two POST actions stay restricted to the approver
        roles. `StageGateController::index()` branches up front — a student
        gets every `StageGate` row belonging to applications where
        `student_id` is their own (GRA and GA both, since the screen itself
        never distinguished module type), no portal-wide dashboard, no
        filters, no Student column (redundant — it's always their own row),
        no Action column. Everyone else is unaffected. New conditional "My
        Stage Gate" sidebar link in `GraApplicationWorkflow::links()`, shown
        only once at least one of the student's applications has actually
        generated a schedule (mirrors the existing "My GRA Application"
        link's "only if something exists yet" pattern).
  - [x] **Found 2026-10-14, Core fix (approved): student sidebar never
        rendered ANY module's `ProvidesLinks` links — not a Stage Gate bug.**
        The controller/data layer above was correct and I'd "verified" the
        sidebar link by calling `GraApplicationWorkflow::links($student)`
        directly in Tinker, which bypasses real route/view rendering
        entirely — a blind spot in this phase's own testing method, not
        caught until the user reported the page as reachable only by typing
        the URL. The real cause: `AppServiceProvider`'s `View::composer`
        already computes `$extraLinks` for every user including students,
        but `sidebar.blade.php` only ever forwarded it into the *approver*
        branch — `sidebar-student-nav.blade.php` never received or rendered
        it, so "My GRA Application" and "GRA Extension" had *also* been
        unreachable from the sidebar this entire time, not just the new
        "My Stage Gate" link. Confirmed before touching Core that this was
        safe: neither Hani's `ExaminerNominationWorkflow::links()` nor
        Nureen's `AttendanceAppealWorkflow::links()` return anything for
        `Role::STUDENT`, so forwarding `$extraLinks` to students surfaces
        only this module's own links, nothing newly exposed for anyone
        else's module. Fixed with the user's explicit approval
        (`sidebar.blade.php` passes `extraLinks` through;
        `sidebar-student-nav.blade.php` renders it with the identical
        `@if (! empty($extraLinks))` guard the approver branch already
        uses). Verified this time with real HTTP requests through the
        actual Laravel server (login + session cookie via curl, not
        Tinker) — logged in as `student@utp.edu.my`: dashboard now shows
        "My GRA Application", "GRA Extension" and "My Stage Gate" with
        correct hrefs, and clicking through to `/stage-gate` renders their
        3 real rows. Re-confirmed all four approver roles' dashboards
        (Admin/Supervisor/CGS/Senior Director) are unchanged.
  - [x] **Schedule now anchors to the appointment's service_start_date, not
        the profile's admission_date** (2026-10-14) —
        `StageGateEngine::generateSchedule()` takes an explicit `Carbon
        $startDate` parameter now instead of reading
        `$profile->admission_date` itself; both callers
        (`GraApplicationController`/`GaApplicationController::onApproved()`)
        pass `$detail->service_start_date`. Reasoning: admission_date is the
        student's academic candidature date, which can predate a specific
        GRA/GA appointment by months or years — the Stage Gate clock is
        about this appointment, not when the student first enrolled.
        `admission_date` itself is untouched and still used everywhere else
        (profile display, letters, GRA Extension's own logic). Verified
        live: a test application with `admission_date` 19 months in the
        past but `service_start_date` today generated a schedule with
        Stage 1's deadline 3 months from *today*, not 19 months ago (which
        would have been immediately grace-expired under the old anchor).
        With the user's explicit sign-off, also regenerated application
        #8's existing 3 Stage Gate rows (the only real, already-approved
        application with a schedule) onto the new anchor — confirmed first
        that none of the three had any completion history or allowance log
        referencing them, so nothing was lost.
  - [x] **An untouched gate's dates now resync to service_start_date on
        every daily evaluation — a touched one stays frozen** (2026-10-14)
        — found via the user directly editing application #8's
        `service_start_date` in phpMyAdmin after its schedule had already
        been generated: `generateSchedule()` is one-time by design, so the
        existing rows didn't notice the edit, which looked like "the
        deadline isn't following service_start_date" when it actually was,
        just only as of generation time. Explicitly discussed the trade-off
        before building anything, since blindly recomputing deadlines live
        would let an edited date retroactively undo a consequence CGS had
        already acted on (a completed/withheld/forfeited gate). User chose
        the safe middle option: `StageGateEngine::resyncIfUntouched()`,
        called at the top of `evaluateGate()`, re-derives deadline_date/
        grace_end_date from the appointment's *current* service_start_date,
        but only while the gate is still `not_recorded` + `eligible` —
        completed/withheld/forfeited gates are left exactly as they were
        the moment that happened. Verified live on application #8: changed
        service_start_date, ran `stage-gate:evaluate`, confirmed the
        untouched Stage 1 picked up the new date (and correctly transitioned
        to grace_expired/withheld, since the new date had already lapsed);
        then marked Stage 1 complete, changed service_start_date *again*,
        ran evaluate again, and confirmed Stage 1 stayed exactly as it was
        while still-untouched Stage 2/3 resynced to the new date. Reset
        application #8 back to a clean, all-upcoming state afterward at the
        user's request.
  - [x] **Found 2026-10-14, shared dev-infra fix (approved): the scheduler
        was never actually running, in this environment or anyone's
        checkout of it.** `stage-gate:evaluate` is correctly registered at
        `dailyAt('07:30')` in `Haziq\ModuleProvider` — the registration was
        never the problem. But Laravel's scheduler only fires anything if
        something calls it every minute, and nothing did: `docker-compose.yml`
        has a dedicated `queue` container running `queue:work` continuously,
        but no equivalent for the scheduler — no cron, no `schedule:work`,
        anywhere. So every scheduled job in the app (not just this module's)
        had been silently register-only since the Docker setup was written,
        and every "automatic" update in this phase's testing only ever
        happened because it was triggered by hand. Fixed, at the repo root
        rather than inside this module, with the user's explicit approval:
        added a `scheduler` service to `docker-compose.yml` (identical to
        `queue` apart from running `php artisan schedule:work`), updated
        `setup.sh`'s startup message, and added a `sync.sh` health check
        mirroring the existing queue-worker one (restarts the container
        after a pull; warns with a start command if it isn't running).
        Verified live: brought the container up, confirmed
        `php artisan schedule:list` shows `stage-gate:evaluate` correctly
        due and the long-running `schedule:work` process actually watching
        for it, not just registered.
  - [ ] **Stage 1 is still a standalone CGS entry, not wired to Norhanis'
        RPD.** The dependency flagged below is unchanged: Norhanis has not
        built an RPD data model yet, so `StageGateController::markComplete`
        on Stage 1 is Haziq's own record, not a read of hers. Revisit when
        her RPD module lands.
  - [ ] Configurable reminder lead times (30/14/7 days, mentioned in early
        design discussion) not implemented — currently a flat one-month
        "due soon" window and a monthly grace reminder, both hardcoded in
        `StageGateEngine::evaluateGate()`

- [x] **Allowance Eligibility** (reporting only, no submissions)
  - [x] `allowance_payment_logs` — plain calendar months (the UTP form's
        16th-to-15th claim window was explicitly dropped), backfilled for
        every missed month so a late-running job (or a fresh student) never
        leaves a withheld month with no row to back-pay against
  - [x] Filterable list at `/allowance`, scoped like `CgsDashboard`: Admin/CGS
        see everyone, Supervisor sees their own supervisees
  - [ ] Not wired into `CgsDashboard`/`AdminDashboard`/`StudentDashboard` —
        those are Core files; this module has its own screens
        (`/stage-gate`, `/allowance`) reachable via `ProvidesLinks` instead.
        Adding stat cards to the three existing dashboards needs the team's
        sign-off first.

- [x] **TA Hours** — plain activity log (`ta_hour_logs`: type, hours, date,
      recorded by), not the terms document's full per-activity
      approver/claim-formula engine. Scoped down deliberately.

- [ ] **AI status chatbot** — not built. No home in the current stack; see
      the AI note under Cross-cutting.

- [ ] **No automated tests yet.** Verified manually via Tinker (schedule
      generation, grace-expiry, back-pay, termination trigger — all correct)
      and `php artisan test` confirms the existing 42 tests still pass, but
      this module has none of its own. Worth closing before relying on it.

- [x] **2026-11-03 — GRA form compared field-by-field against the real UTP
      "GRA Requisition Form" and "GRA Extension Form" PDFs** (user pasted
      both verbatim and asked to close the gap): "kau baca form tu betul
      betul... pastu en mana yang kurang dekat application form tambah
      please, pastu bila nak extend en, extend tu kene isi mana yang takde
      kat requisition form. and boleh isi bila dah end service date je."
  - [x] **Terms & Conditions gate, new** — a student can no longer reach
        `gra-application/new` or `gra-application/extension/new` without
        first accepting the GRA Scheme Terms and Conditions
        (`GraApplicationController::hasAcceptedTerms()`, checked in
        `create()`/`store()`/`createExtension()`/`storeExtension()`; new
        `gra_terms_acceptances` table, one row per student — not per
        application, so accepting once covers every later application and
        extension). New route pair `gra-application.terms` /
        `gra-application.terms.accept` and
        `gra_application/terms.blade.php` (eligibility, duration limits,
        appointment-date rule, payment schedule, and the official form's
        own certification statement, with a required "I agree" checkbox).
  - [x] **Found: the schema already had columns for several Requisition
        Form fields that no form input or validation rule ever collected**
        — `gra_ga_student_profiles.co_supervisor_id` /
        `previous_sponsorship_name` / `previous_sponsorship_duration` /
        `scholarship_name` / `scholarship_duration`, and
        `gra_publications.publication_year` / `volume_chapter` /
        `page_number` / `doi` / `issn` / `database_quartile` /
        `impact_factor` — present in the migrations and `$fillable` since
        whenever those tables were first scaffolded, but silently dropped
        because `form.blade.php` never rendered an input for them and
        `validateApplication()`/`storeProfile()` never validated or saved
        them. Wired all of them into Section C (Co-Supervisor select,
        Previous Sponsorship/Scholarship name+duration) and Section F
        (Year, Volume/Chapter, Page No., DOI, ISSN, Database & Quartile,
        Impact Factor) of `form.blade.php`, `correction.blade.php` and
        `show.blade.php`, and into `validateApplication()`,
        `storeProfile()` and `storeCorrection()`. Verified live: submitted
        a real draft via HTTP for `student2@utp.edu.my` with every one of
        these fields filled in, confirmed each one landed correctly on the
        real row in the database, then deleted the disposable draft.
  - [x] **Extension form reworked to match its own PDF, not just the
        Requisition's.** The official Extension Form asks whether the
        extension continues on the same grant or a new one
        (`funding_continuation`, new nullable column on
        `gra_application_details`, null on a 'new' row) — previously,
        `storeExtension()` just silently copied Section B (project
        leader/title/funding source/grant/cost centre/centre) from the
        parent appointment with no way to change any of it. Section B is
        now re-entered on the extension form itself (pre-filled from the
        parent as sensible defaults, editable), alongside the new
        funding-continuation choice; `previous_allowance` is now also
        shown read-only on the form itself, not just stored. Same new
        fields added to `correction.blade.php` (shown only for an
        extension row; `service_start_date` was already `prohibited` there,
        `funding_continuation` is `required` for an extension and
        `prohibited` for a 'new' row, same pattern) and `show.blade.php`.
  - [x] **Extension can now only be requested once the current appointment
        has actually ended** — "boleh isi bila dah end service date je".
        New `GraApplicationDetail::canBeExtended()` (`service_end_date <=
        today`, same-day counts as ended), checked in both
        `createExtension()` and `storeExtension()`. Verified live: built a
        disposable approved application for `student2@utp.edu.my` with
        `service_end_date` five days in the future, confirmed
        `/gra-application/extension/new` returned 403 with the exact end
        date in the message; moved the date three days into the past,
        confirmed the form opened, submitted a real extension through it
        with `funding_continuation=new`, confirmed the resulting row had
        `funding_continuation`, `previous_allowance`, `service_start_date`
        (= parent's old end date) and the new `service_end_date` all
        correct; deleted both disposable applications afterward.
  - [ ] **Not changed, flagged only:** the official form also requires a
        GRA appointment's start date to fall on the 1st or the 16th of the
        month (end date on the 15th or month-end), and describes the RPD
        allowance reimbursement cap as 3 months from the date of successful
        RPD rather than the uncapped back-pay this module already
        implements by deliberate team decision (2026-09-21). Both are
        business-rule questions, not missing form fields, and weren't part
        of what was asked this round — raised here rather than acted on.
  - All of the above verified with real HTTP requests (cookies + CSRF) 
        against `student2@utp.edu.my`, a second, otherwise-unused student
        account — `student@utp.edu.my`'s real application #25 was not
        touched, confirmed unchanged (`status=approved`) before and after.
        `php artisan test` still 42/42 afterward.

- [x] **2026-11-03 — `setReviewAmount()` was discarding a still-open Stage 1
      withheld total whenever the termination it was reconciling was a
      Stage 2/3 one.** Clarified explicitly this round: Stage 1 (RPD) never
      terminates candidature on its own, only withholds — "stage 2 3 die
      tak completekan dah past due grace... stage 1 tak bawak termination."
      Only Stage 2/3's own grace expiry raises a termination trigger
      (confirmed against `StageGateEngine::applyGraceExpiry()`'s own
      `isRecoverable()` branch — unchanged, this round only closes a gap in
      what happens *around* it). The bug: Stage 1 can still be
      independently mid-accumulation (withheld, growing every month) at
      the exact moment a Stage 2/3 termination hits — the two are separate
      tracks — but `setReviewAmount()` always wrote a flat, disconnected
      `determined` figure for the reinstatement month, silently discarding
      whatever Stage 1 had already accumulated before the termination.
      User: "stage 1 pun tak complete lagi... amount diset akan ditambah
      dekat allowance tergantung tu."
  - [x] New `AllowanceController::priorWithheldTotal()` — the Stage 1
        running total at the exact month immediately before the
        termination gap started (0.0 if Stage 1 wasn't mid-accumulation
        right then, e.g. it was already resolved earlier). Reuses
        `rowFor()` itself for the sum, so it can never drift out of sync
        with what the table displays, and only looks at the run
        *immediately* adjacent to the gap — an older, already-settled
        withheld period from well before an unrelated earlier gap doesn't
        count.
  - [x] `setReviewAmount()` now branches on it: if there's a prior total to
        resume, CGS's figure for the reinstatement month is *added* onto it
        (not a replacement), and the row's status becomes `backdated` if
        Stage 1's `completion_status` is already `completed` at that exact
        moment (same place `backPay()` would have put it), or `withheld`
        if Stage 1 is still open — which means a *later*
        `recordCompletion()` still finds this row via the normal
        `backPay()` sweep (matched by `stage_gate_id` + status, not by
        date) and converts it the same way as any other still-open
        withheld row, and `ensureMonthlyLogs()` just keeps accumulating on
        top of it next month like any other open gate — no further special
        casing needed anywhere else. If there's nothing to resume (Stage 1
        was already resolved before this termination), falls back to the
        exact same flat `determined` figure as before — fully backward
        compatible.
  - [x] Verified live with two disposable applications for
        `student2@utp.edu.my`: (1) Stage 1 withheld Aug+Sep 2026 (RM2000/mo,
        running total RM4000), forfeited Oct (the termination gap),
        `review_required` Nov; posted `amount=2000` via the real
        `/allowance/{id}/set-review-amount` route while Stage 1 was still
        `not_recorded` — row became `withheld`, RM6000 (4000+2000), showing
        correctly on the real `/allowance` page, red, "Months Withheld: 1".
        Reset the same row and re-ran with Stage 1 marked `completed`
        first — row became `backdated`, RM6000, same total. (2) A second
        application where Stage 1 was already `eligible` (resolved) before
        an unrelated forfeited/review_required gap — `priorWithheldTotal()`
        correctly returned 0, and `setReviewAmount()` fell back to the
        original flat `determined` RM1200. Both disposable applications
        and their Stage Gates/logs deleted afterward; `student@utp.edu.my`'s
        real application #25 confirmed untouched; `php artisan test` still
        42/42.
  - [x] **Third combination confirmed the same day, on its own request**:
        "kalau stage 1 dah complete takde allowance tergantung, and takde
        allowance tertunggak payment dari cgs... keluar amount tu je. kalau
        ada tertunggak payment die kena add dari payment tertunggak" — Stage
        1 *already complete* with an outstanding, never-confirmed
        `backdated` total still sitting there must also resume (not just a
        still-accumulating `withheld` one) — already covered by
        `priorWithheldTotal()`'s `whereIn(['withheld', 'backdated'])`, not a
        new code path. Verified live with a third disposable application:
        Stage 1 `completed` with an unconfirmed backdated RM4000
        (Aug+Sep 2026), forfeited Oct, `review_required` Nov; posted
        `amount=1000` — row became `backdated`, RM5000 (4000+1000),
        correct on the real `/allowance` page with "Mark Payment Complete"
        available. Cleaned up afterward; #25 untouched; 42/42.
  - [x] **Corrected the same day, superseding the `withheld`/`backdated`
        write above: the resumed total was showing up live, immediately,
        on the reinstatement month itself** — breaking the one-month
        payroll lag every other CGS-determined figure in this module
        already respects. User: "tapi kau tahu en cgs boleh set the
        payment bulan berikutnya... kalau payment tu tak complete lagi by
        cgs, next month added up... sebab kan allowance bulan sebelum
        dapat bulan afternya." `setReviewAmount()` now always writes
        `'determined'` (never `withheld`/`backdated` directly), amount =
        `priorWithheldTotal()` + CGS's figure — same status as the
        original flat case, so it reuses the exact same one-month-lag
        display mechanism instead of a parallel one. The real fix is in
        `rowFor()`'s `$previousStatus === 'determined'` branch: it now
        checks the *next* row's own real status when that row arrives —
        if it's genuinely `withheld`/`backdated` (Stage 1 still accruing),
        this month folds in its own flat amount and the running total
        keeps growing from here exactly like an ordinary withheld run;
        otherwise it's a one-off payout and everything resets to zero
        after, unchanged from before. Deciding this from the *next* row's
        real-time status rather than a snapshot taken when CGS typed the
        figure is also more correct on its own: Stage 1's state can change
        between those two moments. Verified live across three consecutive
        months on a disposable application (Stage 1 still open, withheld
        Aug+Sep RM2000/mo, forfeited Oct, reinstated Nov): Nov (the
        reinstatement month itself) correctly blank; Dec showed RM7000
        (4000 prior + 1000 CGS figure + Dec's own 2000), payment_pending;
        Jan continued to RM9000. Re-verified the flat/no-resume case on a
        second disposable application still shows the one-off payout
        (RM1200) on the month after, then correctly reverts to a plain
        flat RM2000 the month after that — not accumulating. Both deleted
        afterward; #25 untouched; `php artisan test` 42/42.
  - [x] **One more rule from the same walkthrough, confirmed and
        implemented the same day**: "situasi cgs set amount ni only
        applicable on date selepas 1 hari bulan" — an appeal approved on
        exactly the 1st of the month is never a partial month to begin
        with, so there's nothing for CGS to judge; it should resume
        immediately on its own, same as any ordinary month. New shared
        `Support\AllowanceLedger` (`withheldTotalThrough()` +
        `priorWithheldTotal()`) factors the running-total math out of
        `AllowanceController` so `StageGateEngine::
        flagReinstatementMonthForReview()` can reuse the exact same sum
        without drifting apart from what the table displays —
        `AllowanceController::priorWithheldTotal()` now just delegates to
        it. `flagReinstatementMonthForReview()` branches on
        `Carbon::today()->day`: any day but the 1st, unchanged
        (`review_required`, CGS decides, deferred to next month per the
        entry above); exactly the 1st, resolves the row immediately to
        its real current status (`statusAsOf()`) with
        `priorWithheldTotal()` folded straight in if still accruing — no
        CGS action, no deferral. Verified live: (1) a disposable
        application appealed via the real `/candidature-appeals/{id}/decide`
        route on the real "today" (day 3) still produced `review_required`
        unchanged, confirming the refactor didn't disturb the existing
        path. (2) a second disposable application, with
        `Carbon::setTestNow()` set to the 1st, had
        `flagReinstatementMonthForReview()` called directly (this part
        isn't reachable by moving the real server's clock) — row resolved
        straight to `withheld`, RM6000 (4000 prior + 2000 own), showing
        live on the real `/allowance` page for that same month, not
        deferred. Both disposable applications and their appeal/Stage
        Gate/log rows deleted afterward; #25 untouched; `php artisan test`
        42/42.
  - [x] **Not a bug, investigated and confirmed the same day**: "masalahnya
        tak nampak pun allowance tertunggaknya... setting amount tu next
        month... 1 december 2026 sebab appeal approve not on 1 november
        2026" — checking the following month's row before that month has
        actually arrived shows nothing, because `ensureMonthlyLogs()`
        never creates a month ahead of time (by design — it only backfills
        up through "today"). Reproduced live end to end via the real
        routes: appealed (not on the 1st) → `review_required`; set
        RM1000 via the real `/allowance/{id}/set-review-amount` → Nov
        correctly `determined`, RM5000; checking `?month=2026-12` on the
        real "today" (3 Nov) correctly showed "No allowance records for
        this month" because that row genuinely doesn't exist yet — then
        simulated 1 December actually arriving (`Carbon::setTestNow()` +
        a real `StageGateEngine::evaluateAll()` call, the exact method the
        daily job runs) and the December row was created and displayed
        correctly: RM7000 (5000 determined + December's own 2000, still
        accruing). The `uresearch-scheduler` container already runs
        continuously in this environment, so this resolves on its own the
        moment the real calendar reaches that day — nothing to fix.
        Disposable application deleted afterward; #25 untouched; `php
        artisan test` 42/42.
  - [x] **Simplified the same day, reversing the day-1-only exception
        above after walking through it once more**: "bila appeal tu
        approve, stage 1 nampak tak complete agi en, patut terus keluar
        allowance suspended tu dengan keadaan merah, and cgs or admin tak
        boleh lagi set amount, kalau dah complete, die hijau... citer die
        skang apply to every situation... ni hanaya 1 haribulan" —
        generalising the 1st-of-month's own immediate-resolve behaviour to
        *every* day, not just the 1st. `StageGateEngine::
        flagReinstatementMonthForReview()` renamed to
        `resumeReinstatementMonth()` and simplified to always resolve the
        reinstatement month immediately to its real current status
        (red/withheld, green/eligible, or amber/backdated, whichever
        Stage 1's own state says), with `AllowanceLedger::
        priorWithheldTotal()` folded straight in — no day check, no
        `review_required`, no CGS figure, no one-month wait, regardless
        of what day the appeal lands on. `AllowanceController::
        setReviewAmount()` still exists, but is now only ever reached by
        the *entry* side of a termination (the month it starts in,
        `ensureMonthlyLogs()`'s own `review_required`) — the two sides of
        a termination are now deliberately asymmetric, confirmed
        explicitly, since only the entry side can genuinely be a CGS
        judgement call the system has no way to compute on its own.
  - [x] **Found and fixed while verifying the above**: a stale branch in
        `AllowanceController::rowFor()` — `elseif ($previousStatus ===
        'forfeited')` — force-overrode *any* `eligible` row immediately
        following a `forfeited` run back into blank `review_required`,
        regardless of why it was `eligible`. That branch predated
        `resumeReinstatementMonth()` (written for the old assumption that
        every such row needed a human's figure) and was silently
        fighting it: a disposable application with Stage 1 already fully
        resolved before an unrelated termination, reinstated on a
        non-1st day, resolved correctly in the database
        (`status=eligible, amount=2000`) but the real `/allowance` page
        still showed blank `review_required` — removed the branch
        entirely; an `eligible` row is now always trusted and shown via
        its own real status, never second-guessed by what came before it.
  - [x] Re-verified live on two disposable applications after the fix:
        (1) Stage 1 still withheld (RM4000 prior), terminated, appealed
        via the real `/candidature-appeals/{id}/decide` route on day 3 —
        Nov showed RM6000, red, *immediately*, same month, no CGS action
        at all. (2) Stage 1 already resolved with nothing outstanding,
        same real appeal route, day 3 — Nov showed a plain RM2000,
        green, immediately — confirming the stale-branch fix. Both
        deleted afterward; #25 untouched; `php artisan test` 42/42.
  - [x] **Refined once more, same day, after walking through a worked
        example together**: the previous entry's "fully automatic, no
        CGS input ever" was itself an overcorrection. Confirmed final
        shape — three distinct cases, by name ("tertunggak by cgs" vs
        "allowance suspended" vs neither):
      - **Tertunggak (Stage 1 already `completed`, `backdated`,
        never confirmed-paid)**: stays visible — CGS can mark it paid —
        *even while the termination is still active and unappealed*.
        Found and fixed in `ensureMonthlyLogs()`: the termination
        blackout (`forfeited`/`review_required`) now explicitly spares
        any month where `$stage1->completion_status === 'completed'`,
        falling through to the normal `statusAsOf()` treatment instead
        — a completed-but-unpaid Stage 1 balance has nothing to do with
        whatever Stage 2/3 is doing to `candidature_status`. Verified
        live: a disposable application, Stage 1 completed+backdated
        RM4000 base, Stage 2 forfeited (terminated, appeal never even
        filed) — `evaluateAll()` kept accruing straight through the
        termination, RM14000 by the 7th month, visible, confirmable,
        on the real `/allowance` page the whole time.
      - **Suspended (Stage 1 still open)**: blanks during the
        termination exactly as the entry above already fixed, but once
        appealed resurfaces *live, immediately, any day* — same as
        before — and now CGS can *additionally* set a partial-month
        top-up on that same, already-visible row, same month, not
        deferred — "cgs boleh set allowance... tapi kalau tak complete
        lagi allowance tertunggak plus the new amount set."
        `AllowanceController::setReviewAmount()` now branches: a
        `review_required` row still defers to next month as before; a
        `withheld`/`backdated` row immediately preceded by a `forfeited`
        one gets the new figure *added* in place, same month.
        `rowFor()`'s withheld/backdated branch grew a matching
        `reviewable` condition, and `index.blade.php` now shows both the
        Confirm and Set Amount forms together rather than `@elseif`
        (a resumed `backdated` row can legitimately be both at once).
        Verified live: RM4000 (resumed, live) → posted `+1000` via the
        real route → RM5000, still the same month, still red.
      - **Neither (nothing tertunggak or suspended)**: unchanged from
        two entries above — blank this month, CGS's figure (if any)
        only visible the month after. Re-verified live: blank on
        approval, `amount=1200` posted, still blank that same month,
        RM1200/payment_pending only once December actually arrived
        (`evaluateAll()` simulated forward again).
      - The 1st-of-month case is untouched by any of this — still
        auto-resolves the whole month in one step, no CGS figure, no
        `reviewable` option (re-verified: RM4000 prior + RM2000 full
        month = RM6000, immediately, `resumeReinstatementMonth()` run
        directly with `Carbon::setTestNow()` on the 1st).
      - Three disposable applications used across all of the above,
        deleted afterward. `student@utp.edu.my`'s real application #25
        was **not** touched or reset this round — its `allowance_payment_logs`
        history (44 months back to March 2023, several candidature
        appeals) is a real mix of earlier testing rounds and is left as
        found pending the user's own call on whether to reset it.
        `php artisan test` 42/42 throughout.

- [x] **2026-11-03 — `recomputeMonthlyLogs()` had the exact same
      termination-blackout bug `ensureMonthlyLogs()` was fixed for
      earlier the same day, and it was live on application #25.** User:
      "kenapa tak keluar amount suspended dari 1 october 2023 sampai 1
      september 2024. patut auto keluar." Stage 1 on #25 is
      `completed`/`backdated` (the "tertunggak" case) — but its
      `allowance_payment_logs` rows for that whole window (and every
      other month back to March 2023 and forward past several later
      `candidature_appeals`) still read `forfeited`, because those rows
      were written by `recomputeMonthlyLogs()` (or its predecessor logic)
      *before* the Case A exemption existed, and nothing ever goes back
      to refresh an existing row once it's written — only
      `ensureMonthlyLogs()`'s fix prevents the bug for rows created from
      now on, it does nothing for rows already sitting there wrong.
      Added the identical exemption to `recomputeMonthlyLogs()`
      (`$terminated` now also checks `$stage1?->completion_status !==
      'completed'`), then retroactively re-ran it against **#25 itself**
      via `ReflectionMethod` (it's `protected`) with
      `includeBackdated: true` — this is a deliberate, real mutation of
      the user's own real application, done transparently and reported
      here: all 44 existing rows (2023-03 through 2026-12) flipped from
      `forfeited` to `backdated`, now accumulating continuously
      regardless of the later Stage 2/3 terminations layered on top,
      matching Case A. One leftover test artifact found and fixed in
      the same pass: the 2026-11 row had amount=1000 (a stray
      `setReviewAmount()` figure from earlier testing) instead of the
      normal flat RM2000 — `recomputeMonthlyLogs()` only touches
      `status`, not `amount`, so this needed a separate, explicit
      correction, done and noted here rather than silently. Verified
      live on the real `/allowance` page: October 2023 now reads
      RM16,000 (8 months), November 2026 reads RM90,000 (45 months) —
      both correct, continuous, matching the engine's own math.
      `php artisan test` 42/42 after.

- [x] **2026-11-03 — corrected the same day: the entry above was itself
      wrong.** User: "bulan after termination takkan accumulated tau
      dari stage 2 lagi. amik sampai 1 september 2024 je" — Case A
      ("tertunggak") staying *visible* through a termination never meant
      it keeps *growing* every month while genuinely terminated; those
      are two different claims and the exemption above conflated them,
      producing a RM90,000 total that kept accruing straight through
      Stage 2's own 2024 termination as if nothing had happened.
  - [x] Fixed properly in `ensureMonthlyLogs()` this time: a month at or
        after `terminatedSince` where Stage 1 is `completed` now writes
        `status = 'backdated'` with `amount = 0` — visible (rowFor()'s
        walk doesn't reset on a 'backdated' row) but contributing
        nothing further, i.e. frozen at whatever the running total
        already was going into the termination — rather than either
        blanking it (the old bug) or letting it keep accruing (this
        morning's overcorrection). `recomputeMonthlyLogs()`'s matching
        change from the entry above was reverted — it's only ever called
        from `recomputeApplication()`, where `candidature_status` is
        already reset to active first, so the exemption never did
        anything there anyway; it was only ever load-bearing for the
        reflection hack below, and that hack produced the wrong answer.
  - [x] `resumeReinstatementMonth()` had to grow a second branch: a
        frozen Case A row never shows `forfeited` (it's `backdated`
        throughout), so the method's original query never found it.
        Now also matches a `backdated` row with `amount = 0` for today's
        month, and resuming it is simpler than Case B — no partial-month
        judgement to make (the balance was already fully settled before
        the termination), so this month's ordinary entitlement is just
        restored outright and growth continues normally from there, any
        day.
  - [x] Verified live end to end on a fresh disposable application
        (Stage 1 completed+backdated, growing Jan–Jul 2026 to RM14000,
        Stage 2 forfeited end of Jul): Aug–Nov all correctly froze at
        RM14000 (not blank, not growing) on the real `/allowance` page;
        called `reinstateCandidature()` + `resumeReinstatementMonth()`
        directly — Nov resumed to RM16000 (14000 + this month's 2000),
        confirming growth continues correctly from the frozen base.
        Deleted afterward.
  - [x] **Re-applied the correction to application #25's real history**,
        transparently, superseding the entry above: Stage 2's own
        `grace_end_date` (2024-09-01) gives `terminatedSince` =
        2024-10-01, matching the user's "sampai 1 september 2024" exactly
        — every row from Oct 2024 through Oct 2026 (25 rows) set to
        `amount=0, status=backdated` (frozen), November 2026 (today's
        reinstatement) restored to the normal flat RM2000. Verified live:
        September 2024 and October 2026 both now read an identical
        RM38,000 (the freeze holds exactly), November 2026 reads RM40,000
        and December 2026 RM42,000 (growth resumes and continues
        correctly from the frozen base). `php artisan test` 42/42
        throughout; application #25's own `status` (`approved`)
        unaffected.

- [x] **2026-11-03 — one more correction the same day, on explicit
      invitation to ask ("kalau confius kau tanya"): Case B's
      partial-month CGS addition was itself showing immediately, same
      month, when it should defer to the next one — same payroll-lag
      rule as everything else CGS types in.** User: "cgs boleh set
      partial amount bulan di appeal tu kalau tak pada 1 hari bulan ah
      and amount tu masuk next monthnya." Separately confirmed via two
      direct questions: (1) a resumed Case B total staying red
      (`withheld`) is never itself confirmable — only once Stage 1
      actually completes (`backdated`) can CGS mark it paid, unchanged
      from how it already worked; (2) the partial-month figure's
      *destination* is genuinely the following month, not the
      reinstatement month itself — reversing this same day's earlier
      "added immediately, same month" version.
  - [x] New nullable `pending_addition` column on
        `allowance_payment_logs` (this module's own table) — the
        reinstatement month's row keeps showing exactly what it already
        showed when CGS adds a figure to it; the figure is held here
        instead of touching `amount`, and only folds into the *following*
        row's running total once that row is processed (`rowFor()`'s
        withheld/backdated branch now tracks and consumes the previous
        row's `pending_addition`, the same job `$previousStatus ===
        'determined'` already does for the entry side). Accumulates
        across multiple "Set Amount" submissions in the same month
        rather than overwriting.
  - [x] `AllowanceController::setReviewAmount()`'s "already resumed" branch
        now writes to `pending_addition`, never `amount`, for exactly
        this reason.
  - [x] Verified live on a disposable application: Stage 1 withheld,
        resumed to RM4000 on a real (non-1st) appeal via the real
        `/candidature-appeals/{id}/decide` route; posted `amount=1000`
        via the real `/allowance/{id}/set-review-amount` route — November
        stayed at exactly RM4000, unchanged; December read RM7000 (4000 +
        1000 pending + December's own RM2000). Deleted afterward; #25
        untouched (its current RM38,000/40,000/42,000 state from the
        entry above is a Case A scenario, not Case B, so this fix doesn't
        change anything about it); `php artisan test` 42/42.

- [x] **2026-11-03 — found the actual root cause of the RM38,000-instead-
      of-RM24,000 problem from two entries above: `statusAsOf()` itself
      has had this bug since well before today.** User: "kenapa stil
      40000 patutnya 24000 je" — then, once shown the fix, confirmed the
      diagnosis: "sebab tak pernah ade record kat month before deadline
      stage 1 ever done... thats why die accumulated."
  - [x] `statusAsOf()`'s `completion_status === 'completed'` branch
        returned the gate's `allowance_status` ('backdated') for *every*
        month unconditionally, including months **before**
        `grace_end_date` — months where Stage 1 wasn't even due yet, so
        nothing was owed. That's what inflated application #25's
        pre-termination total: March–September 2023 (7 months, before
        Stage 1's own grace period even ended) were counted as
        'backdated' and added RM14,000 that was never actually owed,
        on top of the genuinely-owed 12 months (Oct 2023–Sep 2024,
        RM24,000) — the user's own worked example, confirmed exactly
        right all along.
  - [x] Fixed by checking the `grace_end_date` boundary *before*
        checking completion, not after: a month before the boundary is
        always `eligible`, full stop, regardless of whether the gate
        later completed on time or late. Changes nothing for a gate that
        completed on time (its `allowance_status` was already
        `eligible` then anyway) — only stops a *late* completion's
        `backdated` status from reaching back past when the obligation
        actually began.
  - [x] Verified live on a fresh disposable application mirroring the
        user's own example exactly (Stage 1 never completed, withheld
        from the month after its own grace period, Stage 2 terminating
        13 months later): the 5 months before Stage 1's grace period
        correctly showed `eligible` (not counted), the real `/allowance`
        page showed exactly RM26,000 for 13 withheld months — matches
        the math precisely. Deleted afterward.
  - [x] **Re-corrected application #25's real history again**,
        transparently, superseding the RM38,000/40,000/42,000 figures
        from two entries above: the 7 rows March–September 2023 flipped
        from `backdated` to `eligible` (Stage 1's own grace_end_date is
        2023-09-01, so October 2023 is the first real month owed).
        Verified live: September 2023 now reads a plain RM2,000
        (`eligible`), September 2024 now reads exactly RM24,000 (the
        user's own canonical example, confirmed correct), and November
        2026 (today's reinstatement) reads RM26,000 (24,000 + this
        month's 2,000) — matching the user's original "tekan complete...
        next month 26000" worked example from much earlier in this
        module's development. `php artisan test` 42/42 throughout;
        application #25's own `status` (`approved`) unaffected.

- [x] **2026-11-03 — reverted the entry above, same day, on the user's
      explicit instruction after a direct confirmation question (24000
      vs 38000).** "selagi record tak pernah tunjuk payment completed
      on month start service date patut anggap no payment record" —
      with no `AllowancePaymentConfirmation` ever recorded for this
      application, *every* month back to `service_start_date` counts as
      unpaid once Stage 1 is `completed` and still owed, not just the
      months from `grace_end_date` onward. `statusAsOf()` reverted to
      checking `completion_status === 'completed'` before the
      `grace_end_date` boundary, exactly as it read before the previous
      entry's change. Re-reverted application #25's March–September
      2023 rows from `eligible` back to `backdated`. `php artisan test`
      42/42.
  - [x] **Separately, a real bug found in the same exchange**: "kenapa
        cgs taleh set ni amount next month" — `resumeReinstatementMonth()`'s
        Case A branch always restored the full month's amount
        immediately on *any* day, never offering CGS the same
        day-dependent "Set Amount" action Case B already had — which is
        exactly why November had jumped from RM38,000 to RM26,000 (24,000
        + an auto-added 2,000) on a non-1st-day appeal with no CGS figure
        involved at all, matching the user's "kenapa keluar 26000"
        complaint. Fixed: Case A now also checks the day — the 1st folds
        this month's entitlement in automatically as before; any other
        day leaves the row exactly as it already shows and defers to the
        same `pending_addition`/`setReviewAmount()` mechanism Case B
        uses. `rowFor()`'s `reviewable` flag and `setReviewAmount()`'s
        backend guard both widened to recognise a Case A row (preceding
        row `backdated` with `amount=0`) as well as Case B's (preceding
        row `forfeited`) — guarded by `candidature_status === 'active'`
        in both places, since a frozen Case A row mid-termination
        (unappealed) looks identical to one freshly resumed and must not
        offer the same action.
  - [x] Re-applied both corrections to application #25 live: September
        2024 back to RM38,000; November 2026 (today's reinstatement, a
        non-1st-day appeal) reverted from its wrongly-auto-added
        RM26,000 back to RM38,000 unchanged, with "Set Amount" now
        available. Tested the real action end to end
        (`/allowance/25/set-review-amount`, `amount=1500`): November
        stayed at RM38,000, December showed RM41,500 (38,000 + 1,500 +
        December's own 2,000) — then reverted that RM1,500 test figure
        afterward since it was only a verification step, leaving #25 at
        a clean RM38,000 (Nov) / RM40,000 (Dec, ordinary accrual, no
        CGS figure). `php artisan test` 42/42; application #25's
        `status` (`approved`) unaffected throughout.

- [x] **2026-11-03 — corrected the same day, superseding the entry
      above's "December showed RM41,500" claim: the deferral it was
      exercising was only ever a single hop, when it needed to be
      permanent.** User, testing a clean day-1 case (nothing CGS-set
      involved, just the frozen Case A total resuming): "bukan patut
      keluar 14000 next month nye baru 16000 hah gotcha" — the month a
      reinstatement happens in should show *only* the pre-existing
      balance, with that month's own contribution (automatic on the 1st,
      CGS-set otherwise) never appearing until the month after — and,
      confirmed on direct follow-up, that one-month offset then **stays
      permanent** for every month after, not just the one immediately
      following: "lag berterusan selamanya lepas reinstatement" — e.g.
      RM14,000 → RM16,000 → RM18,000 → RM20,000, each month always
      showing the *previous* month's true total, forever, until an
      actual `AllowancePaymentConfirmation` resets it.
  - [x] `rowFor()`'s withheld/backdated walk grew two new pieces of
        persistent state across the loop: `$laggedMode` (sticky once
        true) and `$heldBack` (the amount withheld from *this*
        iteration's sum, consumed by the *next*). The moment a
        reinstatement's queued `pending_addition` is first consumed,
        `$laggedMode` flips on and every row's own contribution from
        then on becomes next month's `$heldBack` instead of landing in
        its own display — permanently, not just that once. Both reset
        back to normal, immediate display the moment a row is actually
        `AllowancePaymentConfirmation`-confirmed (same place
        `$runningSum`/`$monthsAccumulating` already reset), and also
        reset on `forfeited`/`review_required`/`determined` so a later,
        unrelated termination cycle never inherits stale lag state from
        an earlier, already-resolved one. The entry-side `$previousStatus
        === 'determined'` branch (StageGateEngine::ensureMonthlyLogs()'s
        own transition-month mechanism, unrelated to reinstatement)
        triggers the exact same permanent lag the first time it's
        "stillAccruing," for consistency — the determined figure itself
        shows that first month, its own row's contribution defers like
        everything else from then on.
  - [x] `StageGateEngine::resumeReinstatementMonth()` simplified to
        match: it no longer ever writes a combined figure straight into
        `amount` — Case A leaves `amount` at its frozen value entirely
        and only ever writes `pending_addition` (automatically, the
        ordinary monthly amount, on the 1st); Case B writes `amount =
        priorTotal` (the pre-existing balance, shown immediately) and,
        separately, `pending_addition` on the 1st only (this month's own
        ordinary entitlement, still deferred one month even though it's
        auto-queued rather than CGS-typed).
  - [x] Verified live on a disposable application (Case A, RM14,000
        frozen through a termination, reinstated exactly on the 1st):
        December read exactly RM14,000 (not RM16,000 — confirms the
        "gotcha"), January RM16,000, February RM18,000, March RM20,000 —
        each one permanently one month behind, matching the confirmed
        pattern precisely. Re-verified no regression on a second,
        never-terminated disposable application (ordinary withheld
        accrual still shows live, same month, unchanged). Simulated an
        `AllowancePaymentConfirmation` on the February row: February
        still read RM18,000 (confirmed, green), March correctly reset to
        a fresh, unlagged RM2,000. Both disposable applications deleted
        afterward; #25 left as found (nothing CGS-set yet for its own
        November row, so nothing to retroactively correct); `php
        artisan test` 42/42 throughout.

- [x] **2026-11-03 — two more fixes the same day, on the same walk.**
  - [x] **The "nothing tertunggak" case was still showing live on the
        1st, which turned out to be wrong too.** User: "appeal month
        kalau takde tertunggak pape en tak patut tunjuk value bulan tu
        sebab logic die en bulan sebelum punya allowance dapat next
        month... 1 hari bulan appeal pun keluar next monthnya." The 1st
        only removes the *partial-month question* (no CGS figure to
        ask for, since the whole month counts cleanly) — it never
        removes the payroll lag itself. `resumeReinstatementMonth()`'s
        "nothing to resume" branch now writes `'determined'` (blank
        this month, auto-filled with the ordinary monthly amount, no
        CGS action needed) on the 1st instead of resolving straight to
        a live status; any other day is unchanged (`'review_required'`,
        CGS provides the figure manually). Verified live on a
        disposable application: a 1st-of-month appeal with nothing
        tertunggak showed blank that month, then the ordinary RM2,000
        the month after — matching the entry-side's existing behaviour
        exactly, where it always should have.
  - [x] **New feature: CGS/Admin can see and edit a queued figure before
        it locks in; the student never does.** User: "once cgs or
        admin dah set, die akan keluar ah value pada diorang tapi
        student tak nampak... die boleh edit balik amount tu selagi
        tak masuk next monthnya." `rowFor()` now returns
        `pendingForNextMonth` (what will actually show next month —
        informational) and `pendingInput` (what to pre-fill the edit
        form with — not the same number for a `determined` row, whose
        `amount` already has `priorWithheldTotal()` folded in, so the
        form shows just CGS's own original figure to avoid
        double-counting on resubmit). `setReviewAmount()` changed from
        an additive `+=` to a plain overwrite (`=`) for the resumed
        withheld/backdated branch, so resubmitting corrects rather than
        piles on; the entry-side `determined` branch already recomputes
        fresh from `priorWithheldTotal()` every time, so it was already
        safe to allow resubmission — just needed `rowFor()`'s
        `determined` branch to stop forcing `reviewable = false` once
        set (now `true` for as long as it's still that same calendar
        month). Both gated in `index.blade.php` behind
        `$canConfirmPayment` only, same as the whole Action column
        already was — never reaches the student's own view.
        Verified live: CGS saw "Queued for next month: RM1,000", edited
        it to RM1,500 (confirmed overwrite, not RM2,500), the student's
        own view of the identical row showed neither the note nor any
        input at all. `php artisan test` 42/42 throughout; disposable
        application deleted afterward.
  - [ ] **Found while verifying the above, not yet resolved: application
        #25's November row has `pending_addition = RM1,000`**, set at
        `2026-11-03 15:01` — not from any of this session's own disposable
        test apps (all used different IDs). Likely the user's own real
        use of the "Set Amount" feature just built, via their own
        browser, while this round's work was in progress — left exactly
        as found rather than guessed at either way; flagged for the user
        to confirm whether to keep it (it'll show as RM39,000 on
        December's row once that month arrives) or clear it.

- [x] **2026-11-03 — GA Application rebuilt to match GRA's shape, on
      explicit instruction: "ga is different from gra" only where it
      genuinely differs (own terms acceptance, no Stage Gate/allowance
      afterwards, Research Centre's two-step interview) — everything
      else (draft/submit, section correction, bottleneck chart, full
      five-stage chain) now mirrors GRA exactly, confirmed explicitly.**
  - [x] **Chain changed from 3 stages to 5**: was `Admin (eligibility) →
        Research Centre (interview) → Admin (final)`; now `Admin
        (verify, never rejects) → Research Centre (interview) → CGS
        Staff → Supervisor → Senior Director CGS` — Research Centre
        sits *before* CGS, not instead of the rest of GRA's own chain.
        `GaApplicationWorkflow::stages()` rewritten; old keys
        `admin_eligibility`/`admin_final` retired (grepped the whole
        module first, nothing else referenced them).
  - [x] **Research Centre's stage split into two separate actions**,
        confirmed explicitly 2026-11-03: a brand new
        `scheduleInterview()` (sets `interview_date`, notifies the
        student via new `GaInterviewScheduled`, does *not* advance the
        workflow — the application stays pending at the same stage)
        *before* the existing `decide()` Pass/Fail call, which now also
        requires an `interview_date` to already be set
        (`abort_unless(..., 404)`) and accepts an `interview_report`
        upload (`DocumentStore`, doc_type "Interview Report"). Fail
        rejects outright via the normal engine reject path — CGS never
        sees it; Pass proceeds to `cgs_review` normally.
  - [x] **No Stage Gate or allowance tracking at all after approval** —
        "after approve takdelah stage gate or allowance bagai bagai tu,
        die just application approve." The old `onApproved()` call to
        `StageGateEngine::generateSchedule()` is gone; approval now only
        generates the offer letters. `storeCorrection()`'s matching
        `recomputeApplication()` call (and its `StageGateEngine`
        dependency entirely) removed too — it would have been a silent
        no-op against zero gates anyway.
  - [x] **New `ga_terms_acceptances`** (deliberately separate from
        `gra_terms_acceptances` — "ga is different from gra"), gating
        `create()`/`store()` the same way GRA's own terms gate does; new
        `terms.blade.php` with GA-specific wording.
  - [x] **Save Draft / Submit**, Section D/E/F repeatables (the shared
        `gra_ga_student_profiles` tables — academic/experience/
        publication — GA never saved these before at all), and
        documents-optional-until-submit — all ported from
        `GraApplicationController::store()`, which GA's own `store()`
        never had.
  - [x] **Section-level correction flagging** (`sectionLabels()`/
        `sectionFieldLabels()`) replacing the old documents-only
        correction — every one of the five approver roles can now flag
        a section or field at any time, not just Admin (route
        middleware widened to match). New `showMine()`/`show()`/
        `index()` (with the same "pending by stage" bottleneck chart
        GRA has) — none of these existed for GA before.
  - [x] Verified live, start to finish, with a disposable Research
        Centre test account (no seeded one exists yet — see below):
        terms gate → save draft → submit with documents → Admin verify
        → Research Centre schedules interview (student notified, stage
        unchanged) → Research Centre records Pass with a report
        attached → CGS → Supervisor → Senior Director approves →
        confirmed **zero** `StageGate` rows and the two offer letters
        generated. Separately verified: a Fail rejects outright without
        reaching CGS; `decide()` 404s if interview hasn't been
        scheduled yet; a non-Admin role (Supervisor) can flag a section
        correction and the student sees it highlighted with the reason
        on their own edit page. All disposable applications, documents,
        and the test Research Centre account deleted afterward; `php
        artisan test` 42/42; application #25 untouched throughout.
  - [ ] **No seeded `research_centre` test account exists** — this role
        already existed in the codebase before today (referenced in the
        old 3-stage workflow and routes), but `database/seeders/
        DatabaseSeeder.php` (a shared, root-level file, not under this
        module) never had one. Flagged for the team rather than added
        unilaterally — a permanent seeded account there would need
        sign-off since it's shared, not Haziq's own file.

- [x] **2026-11-03 — `statusAsOf()`'s 24000-vs-38000 question settled for
      good, after flipping twice in one session (24000 → 38000 → back to
      24000).** User, final word: "yang bug kira dari service start date
      tu buang ah... anggap je payment before allowance suspended [tu]
      completed... patut keluar 24000 bukan 38000." Reverted the
      grace_end_date-boundary check back to running *before* the
      completion check (the very first version, from earlier the same
      day) — a month before Stage 1 was ever due is always `eligible`,
      full stop, regardless of whether the gate eventually completed
      late; a late completion's `backdated` status only ever reaches
      back to `grace_end_date + 1 month`, never all the way to
      `service_start_date`. If this gets revisited again, **this is the
      version to trust** — both of the other two orderings were tried
      and rejected in this same session.
  - [x] Re-applied to application #25's real data once more: the same
        7 rows (March–September 2023) flipped back from `backdated` to
        `eligible`. Verified live: September 2023 reads a plain
        RM2,000 (`eligible`), September 2024 and November 2026 both
        read RM24,000 again (November's own display unchanged by this —
        its still-unresolved RM1,000 `pending_addition` from the earlier
        entry is untouched either way). `php artisan test` 42/42;
        application #25's own `status` (`approved`) unaffected.
- [x] **2026-11-03 — three ML/visualisation features added, after asking
      which of the four original ideas were safe to build now.** User:
      "base on my module, what machine learning can be added, visual chart
      or maybe ai chatbox. recommend for each user" → "can you implement
      all of that." The AI chatbot needs a team-level LLM provider/API-key
      decision that isn't Haziq's to make alone, so it was split out and
      confirmed deferred ("Takde lagi, tunggu team decision - skip chatbot
      untuk sekarang"). The other three stayed inside this module and were
      built:
  - [x] **Stage Gate risk score** — `StageGateController::riskPercent()`,
        an explainable heuristic, explicitly documented as not a trained
        model (not enough historical completion data across cohorts to
        make one worth anything): 0% at `deadline_date`, 100% at
        `grace_end_date`, linear between, `null` once completed. Shown as
        a colour-coded (`green`/`amber`/`red`) horizontal bar chart on the
        student's own Stage Gate page, and as a plain percentage column on
        the approver table.
  - [x] **Allowance anomaly flagging** — a threshold bucket chart
        (`0` / `1–2` / `3+ (flagged)` months accumulating) on the
        CGS/Admin allowance table, explicitly labelled "not real ML, just
        a threshold flag." A `⚠` badge appears next to "Months Withheld"
        on any row at or past the threshold, visible to every viewer of
        that row (student included), not just CGS/Admin.
  - [x] **Allowance trend chart** — `AllowanceController::trendFor()`
        builds a student's own last 6 months by calling `rowFor()` for
        each one, so it can never drift from what the table itself shows;
        rendered as a Chart.js line chart on the student's own allowance
        page only (`$trend` is null for every other role).
  - [x] All three follow the project's existing Chart.js pattern
        (`core::dashboard.partials.chartjs`, `@cspNonce` inline scripts).
        Verified live over real HTTP: logged in as `student@utp.edu.my` —
        trend chart renders with real 6-month data, anomaly chart absent;
        logged in as `cgs@utp.edu.my` — anomaly chart renders, trend chart
        absent. `php artisan test` 42/42. `git status` scope stayed inside
        `app/Modules/Haziq/` and this file.
- [x] **2026-11-03 — `/stage-gate` 500'd for every role (`ParseError:
      unexpected token "endforeach"`) after the risk-chart work above,
      caused by mixing `@php(...)` and `@php ... @endphp` in the same
      file.** Blade extracts raw `@php ... @endphp` blocks with one
      regex pass over the *entire template*, `/@php(.*?)@endphp/s` —
      it matches the literal text `@php` and the literal text `@endphp`
      wherever they occur, with no awareness of the parenthesised inline
      form. `stage_gate/index.blade.php` had `@php($openGates = ...)`
      (line 127) and `@php($risk = ...)` (line 298), both inline, plus
      one genuine block-form `@php $appeal = ...; @endphp` (line 194).
      The regex latched onto line 127's `@php` as the start of a block
      and swallowed everything up to line 194's `@endphp` as one raw,
      unprocessed blob — silently turning every `@if`/`@foreach`/
      `@push`/`@continue` in that 67-line span into literal text instead
      of compiled PHP, which is what produced the mismatched
      if/foreach/endforeach the parser choked on. Fixed by converting
      line 194 to the same inline form (`@php($appeal = ...)`), which
      never emits a literal `@endphp`. **Lesson for this file and any
      other Haziq view: never mix the two `@php` forms — stick to the
      inline `@php(...)` form everywhere, since nothing here needs a
      multi-statement block.** Verified by compiling the view with
      `blade.compiler` directly and `php -l`'ing the output (confirmed
      the exact swallowed region before the fix, confirmed clean after),
      then live over real HTTP as both `student@utp.edu.my` (200, risk
      chart renders) and `cgs@utp.edu.my` (200, Risk column renders).
      `php artisan test` 42/42.
- [x] **2026-11-03 — `monthsAccumulating` split into two numbers, after
      application #25's real row showed "38 months withheld" and the
      user asked why.** Traced it live: Stage 1 went `backdated` from
      Oct 2023, accruing RM2,000/month for 12 months (→ RM24,000), then
      Stage 2's grace period expired 1 Sep 2024 and the debt froze
      (Case A — stays visible, stops growing, per
      `StageGateEngine::ensureMonthlyLogs()`) for the next 26 months,
      through Nov 2026 — 12 + 26 = 38. The counter was ticking every
      frozen month the same as every accruing one, so "38" read as a far
      worse anomaly than it actually was. User: "nak [pisahkan]." Added
      `monthsAccruing` alongside the existing `monthsAccumulating` in
      `AllowanceController::rowFor()` — increments only when a
      withheld/backdated row's own `amount` is actually non-zero (i.e.
      not frozen), mirrored into every place `monthsAccumulating` resets
      to 0 (confirmed payment, `forfeited`, `review_required`,
      `determined`) so the two never drift apart. The anomaly bucket
      chart and the `⚠` flag threshold now key off `monthsAccruing` (the
      real, currently-growing risk); the table still shows the old
      `monthsAccumulating` total underneath as "X outstanding total (Y
      frozen by a termination)" whenever the two differ, so CGS loses no
      information, just the misleading framing. Verified live as
      `cgs@utp.edu.my`: application #25 now reads "12 ⚠" with "38
      outstanding total (26 frozen by a termination)" underneath,
      matching the traced history exactly. `php artisan test` 42/42.
- [x] **2026-11-03 — `recomputeMonthlyLogs()` left `amount`/`pending_addition`
      stale on a `service_start_date` correction, and never purged rows
      outside the corrected timeline.** Found live: user corrected
      application #25's `service_start_date` to 2026-06-01 (from
      2023-03-01), and the current month's row read RM0.00 instead of
      the flat RM2,000 an `eligible` month should show. Traced (via a
      subagent reading `StageGateEngine` + `GraApplicationController::
      storeCorrection()`): `recomputeMonthlyLogs()` — called from
      `recomputeApplication()` whenever the date changes — only ever
      wrote `status`/`stage_gate_id` to each existing row; `amount`
      stayed whatever an old schedule had computed (0.00, frozen by a
      since-undone termination test), and a stray `pending_addition`
      from unrelated earlier testing rode along untouched too. Rows
      dated before the new start date, and future rows beyond today
      from earlier `Carbon::setTestNow()` testing, were never deleted
      either — nothing in the resync path bounds by date at all.
      **Fixed**, both confirmed explicitly ("Both (Recommended)"):
      `recomputeMonthlyLogs()` now takes `$newStartDate`, deletes every
      row dated before it (those months never happened under the
      corrected schedule), and recomputes `amount` (flat
      `monthlyAllowance()`, or `null` for `forfeited`) plus clears
      `pending_addition` on every row it touches, instead of leaving
      either stale.
  - [x] Verified the full accrue-then-freeze cycle the user described
        against the fix, fast-forwarding `Carbon::setTestNow()` to
        2028-01-05 and running `stage-gate:evaluate` against application
        #25's corrected dates: eligible through the grace period, then
        `withheld` growing from the first month after
        grace_end+1 — Dec 2027 (the month before Stage 2's termination
        trigger) read exactly RM24,000 / 12 months, then Jan 2028
        blanked to `review_required`, reviewable by CGS. Matches the
        rule exactly. `php artisan test` 42/42 throughout.
  - [x] Cleaned up afterward: re-ran `recomputeApplication()` under real
        "today" to reset the simulated Stage Gates/candidature back to
        `active`/`eligible`/`grace_period`, then deleted the leftover
        future-dated rows the simulation itself wrote (2027-01–2028-01)
        — `recomputeMonthlyLogs()`'s own date bound only ever protects
        against rows *before* the start date, not ones ahead of real
        "today," so this still needs doing by hand after any
        `setTestNow()`-based test. Application #25 now sits clean: six
        rows, Jun–Nov 2026, `eligible`, RM2,000 each — this also
        resolves the stray `pending_addition = RM1,000` flagged earlier
        this session as unresolved, since the whole pre-June-2026
        history (including that row) was deleted by the fix as
        out-of-range.
- [x] **2026-11-03 — Allowance display now has a permanent one-month
      payroll lag, confirmed explicitly.** User: a student enrolling
      1/11/2026 should show nothing for November (just started, nothing
      earned yet) and RM2,000 in December — "macam allowance kita dapat
      is month sebelumnya." Asked whether this applies only to a
      brand-new enrollment's first month or permanently, every month,
      forever — confirmed **"Permanent setiap bulan (Recommended)"**,
      i.e. real payroll: whatever calendar month you're looking at
      shows last month's completed service, always, not just at the
      start.
  - [x] Deliberately scoped as a **display/query-layer shift only** —
        `StageGateEngine`'s own date-driven accrual state machine
        (`statusAsOf()`, `ensureMonthlyLogs()`, the termination/
        reinstatement lag already in `resumeReinstatementMonth()`) is
        untouched and keeps operating in real service-month time; an
        appeal approved today still genuinely affects today's service
        month regardless of which month a human happens to be viewing.
        Added `AllowanceController::serviceMonthFor(Carbon $displayMonth)`
        (one line: `$displayMonth->copy()->subMonth()`) and routed every
        place that resolves "which row" through it: `index()`'s log
        query, `trendFor()` (each of the last 6 points), `export()`,
        and — critically — `confirmPayment()` and `setReviewAmount()`,
        since their buttons live inside the row `index()` renders; left
        unshifted, confirming a payment would have silently hit the
        *next* calendar month's row instead of the one CGS is actually
        looking at and clicking on.
  - [x] View now shows a clear banner ("Payable in {month} is for
        service completed in {service month}") plus a per-row "for {M
        Y}" label next to each amount, so the convention is legible
        even from a single row, not just the page-level note — user
        asked explicitly for a "month indicator."
  - [x] Verified live, reusing application #25's already-proven
        `recomputeApplication()` correction path: set
        `service_start_date` to 2026-11-01 (today is 2026-11-03) —
        Ahmad Danial's row disappeared entirely from November's default
        view (nothing payable, correctly blank), banner read "Payable in
        November 2026 is for service completed in October 2026."
        Fast-forwarded `Carbon::setTestNow('2026-12-05')` + `stage-gate:
        evaluate` — November's own service row then existed with
        RM2,000/`eligible`, confirming it surfaces correctly once
        viewed from December. Restored application #25 back to its
        `service_start_date = 2026-06-01` baseline afterward and
        re-verified live: row now reads "RM 2,000.00 / for Oct 2026."
        `php artisan test` 42/42 throughout.
- [x] **2026-11-03 — a brand-new enrollment vanished from the allowance
      table entirely instead of showing RM0.00.** Direct consequence of
      the payroll lag above: a student enrolling 1/11/2026, viewed in
      November, looks up October's log — which never existed, since
      service hadn't started — so the query returned nothing and the
      application simply didn't appear in the table at all. User:
      "die baru enroll 1/11/2026, takkan dapat allowance 1/10/2026 tak
      logic patut shows 0 skang" — nothing owed is correct, disappearing
      from the list isn't; CGS still needs to see the student with
      RM0.00, not wonder why they're missing. Added
      `AllowanceController::applicationsPendingFirstPay()` — finds
      approved GRA/GA applications whose `service_start_date` falls
      inside the *current display month* (the only case where an
      earlier real log can't exist yet and a later one hasn't been
      approved into view yet either), scoped by the same role rules as
      the main query, and synthesizes a placeholder row (`amount: 0.0`,
      `eligible`, not confirmable/reviewable) for each. Verified live
      reusing application #25 again: set to `service_start_date =
      2026-11-01`, row now reads "RM 0.00 / for Oct 2026" on both the
      CGS and student views instead of vanishing; confirmed the
      placeholder and the real log query never overlap (once December
      arrives and a real Nov row exists, the placeholder's date range
      no longer matches). Restored to the `2026-06-01` baseline
      afterward. `php artisan test` 42/42.
- [x] **2026-11-03 — the allowance trend chart was wrongly using the
      same payout-shift as the main table; a trend line needs service-
      month framing instead.** User set up their own scenario (service
      eligible Jan–Jul 2026, withheld accumulating from Aug) and caught
      it immediately: "patut naik september 4000, october 6000, current
      month takde tunjuk pape" — expected each *service* month's own
      running total, with the still-open current month left blank, not
      September showing August's payout figure the way `index()`'s
      table deliberately frames it ("for Oct 2026" etc). Confirmed via
      direct computation before changing anything: the chart was
      outputting `Sep=2000, Oct=4000, Nov=6000` (one month behind the
      user's expectation, from going through `serviceMonthFor()` the
      same way the table does) instead of `Sep=4000, Oct=6000,
      Nov=blank`.
  - [x] Fixed `trendFor()` to label and look up each of the last 6
        points by *service* month directly (no `serviceMonthFor()` shift
        at all), and to always blank the point matching the current
        real calendar month — that service month genuinely isn't over
        yet, so there's nothing settled to plot, independent of the
        separate payroll-lag reason `index()` doesn't pay it out yet
        either. The two views now deliberately read differently: the
        table says "what's payable this month" (lagged, labelled "for
        X"), the trend says "my history by the month it happened"
        (unlagged, current month blank) — both correct for what each is
        answering.
  - [x] Verified live as `student@utp.edu.my`: trend now reads exactly
        "Jun 2000, Jul 2000, Aug 2000, Sep 4000, Oct 6000, Nov blank."
        `php artisan test` 42/42. Left the user's own test data
        (application #25, eligible Jan–Jul then withheld from Aug)
        untouched afterward — unlike earlier rounds, this is their own
        live scenario, not disposable fixture data this session created.
- [x] **2026-11-03 — a real 500 on `/gra-application/25/correction`
      uncovered three stacked bugs, all now fixed.** User hit a live
      crash: `SQLSTATE[23000]: Integrity constraint violation: 1048
      Column 'amount' cannot be null`, from `recomputeMonthlyLogs()`
      (the fix earlier this same day) trying to write `null` for a
      `forfeited` row — `amount` is `NOT NULL` on this column. Fixed by
      writing `0.0` instead; `rowFor()`'s own `forfeited` branch always
      displays `null` to the user regardless of what's stored, so this
      is purely a schema fix, not a display change.
  - [x] **Found while diagnosing: `recomputeApplication()` is called
        from `GraApplicationController::storeCorrection()` *after* that
        method's own `DB::transaction()` has already committed, with no
        transaction of its own.** The crash above left application #25
        genuinely inconsistent — `service_start_date` and the Stage
        Gate reset had committed, `candidature_status` had flipped to
        `terminated`, but `allowance_payment_logs` was only half
        resynced before the exception. Wrapped
        `recomputeApplication()`'s whole body in its own
        `DB::transaction()` (split into a new `doRecomputeApplication()`
        for the actual work) — it's the only caller, confirmed via
        grep, so this closes the gap for good without touching the
        controller.
  - [x] **Found while repairing app #25's data: `recomputeMonthlyLogs()`
        had the exact same "flat boolean" bug `ensureMonthlyLogs()`'s
        own docblock already describes and was fixed for (2026-10-28) —
        just never ported over.** The moment `candidature_status` read
        `terminated`, it blanket-forfeited *every* surviving row,
        including ones a full year before the real termination point.
        On app #25 this showed 10 months (Dec 2025–Sep 2026) as
        `forfeited`/blank when they should have kept accumulating
        `withheld` up to the real boundary. Extracted the anchor logic
        ensureMonthlyLogs() already had into a shared
        `terminatedSinceFor(Application $application): ?Carbon`, and
        switched `recomputeMonthlyLogs()` to use it instead of the flat
        flag. Deliberately simpler than ensureMonthlyLogs()'s own
        within-the-window handling (that method also distinguishes the
        exact entry month and a Stage-1-already-complete freeze, both
        needing sequential previous-row state) — noted in the docblock
        as a known gap if that finer distinction ever needs to apply to
        a *corrected* entry month too.
  - [x] Verified live end to end: re-ran the (now transaction-wrapped,
        correctly-anchored) recompute against app #25's real corrected
        `service_start_date = 2025-03-01` — eligible Mar–Sep 2025 (7
        months), `withheld` accumulating Oct 2025–Sep 2026 (12 months →
        RM24,000, confirmed via `rowFor()`), `forfeited`/blank Oct–Nov
        2026 (the real boundary, matching Stage 2's `grace_end =
        2026-09-01`). Confirmed live over HTTP as `cgs@utp.edu.my`: row
        shows blank/`—`, exactly as expected while terminated and no
        appeal has been approved yet. `php artisan test` 42/42
        throughout all three fixes.
- [x] **2026-11-03 — the payroll lag (confirmed permanent earlier this
      same day) turned out to have a real exception: a termination
      entering, or an appeal just approved, must surface immediately,
      not wait for next month's view.** User approved a real appeal on
      application #25 (today, not the 1st) and the resumed RM24,000
      debt read as blank — `rowFor()` computed it correctly on
      November's own service row (amount=24000, `withheld`,
      `reviewable`), but `index()` was still showing October's (lagged)
      row. Asked directly whether resumption should bypass the lag or
      wait like everything else; user's answer was detailed, not a
      pick — restating the full Case A/B resumption behaviour
      (confirmable immediately if Stage 1's already complete, shows the
      restored total either way, CGS's own partial-month figure still
      lands next month same as before) and confirming the debt itself
      "boleh di settle bulan appeal approve tu jugak" — settleable the
      same month as the approval, not deferred.
  - [x] Added `AllowanceController::needsImmediateDisplay()` — reuses
        `rowFor()`'s own `reviewable` flag rather than re-deriving the
        Case A/B check a second time; Case A's own row never goes blank
        in the first place (visible every month throughout a
        termination regardless of the lag), so checking `reviewable`
        alone already excludes it correctly and only catches the two
        cases that actually needed this: a termination's entry month
        (`review_required`/`determined`) and a Case B resumption
        (`withheld`/`backdated` right after a `forfeited` one).
  - [x] `index()` now fetches both the lagged month and today's own
        month, and only swaps in today's row per-application where
        `needsImmediateDisplay()` is true — everyone else keeps the
        ordinary lag unchanged. Added `resolveActingMonth()` so
        `confirmPayment()`/`setReviewAmount()` resolve to the exact same
        row index() is showing — they can never act on a different
        month than the one CGS is actually looking at. Since a bypassed
        row's own period no longer matches the page's single
        `$serviceMonth`, moved the "for {month}" label onto each row's
        own new `periodMonth` field instead of the page-level variable.
  - [x] Verified live as both `cgs@utp.edu.my` and `student@utp.edu.my`
        on the user's own real appeal approval: row now reads "RM
        24,000.00 / for Nov 2026", Months Withheld "1", status
        `withheld`, with the "Set Amount" (partial-month) form present
        — exactly matching the behaviour described. `php artisan test`
        42/42.
- [x] **2026-11-03 — a CGS-set partial-month figure could be silently
      dropped, found testing a second resumption variant on a
      disposable application (#85, student2@utp.edu.my, cleaned up
      after).** User asked to verify a different scenario from app
      #25's: Stage 1 completed *on time*, never withheld, "always
      bayar, takde tertunggak papa," then terminated by Stage 2/3 and
      resumed on a non-1st day. Built it fresh and confirmed the
      numbers match the code exactly — RM0.00, `payment_pending`,
      confirmable (user confirmed this is correct: nothing was ever
      owed, so RM0.00 is right, not a bug — Case A never goes blank
      the way Case B's `review_required` does, confirmed in an earlier
      round the same day).
  - [x] Continuing the same test to verify the forward progression
        (same as app #25's confirmed 24000→25000→27000 pattern)
        surfaced a real bug: setting a RM1,000 partial-month figure via
        `pending_addition` and simulating forward, the next month read
        back RM2,000 — the 1,000 was gone. Root cause: once resumed,
        that row reverts to plain `eligible` (the underlying Stage
        Gate's own `allowance_status` was always `eligible` — only the
        *log rows* froze during the termination, the gate itself never
        went to `backdated`), and `rowFor()`'s `pending_addition`
        fold-in only ever ran inside the `withheld`/`backdated` branch;
        the `eligible` branch never checked for one at all.
  - [x] Fixed by adding the identical fold-in (and permanent-lag
        trigger) to the `eligible` branch — same `$laggedMode`/
        `$heldBack` pattern already used elsewhere, so a queued figure
        can never go missing regardless of which status the next row
        happens to resolve to. Confirmed explicitly 2026-11-03: "Fix —
        pending_addition kena fold even untuk eligible row."
  - [x] Verified live: re-ran the exact failing sequence after the fix
        — December now correctly reads RM1,000 (the folded figure,
        December's own RM2,000 deferred), January reads RM3,000
        (1,000 + December's held-back 2,000), continuing the permanent
        lag exactly like every other branch. `php artisan test` 42/42.
        Disposable application #85 fully deleted afterward
        (Application, GraApplicationDetail, StageGate,
        AllowancePaymentLog, AllowancePaymentConfirmation rows); app
        #25's own leftover simulated future rows from the earlier
        24000→27000 verification were also cleared, restoring it to
        real "today" state.
- [x] **2026-11-03 — the fix above still left a dead end: a lagged
      `eligible` row could never actually be confirmed.** User asked
      the natural next question — set RM1,000 for November, does
      confirming December's payment reset January to a plain RM2,000,
      and does leaving it unconfirmed grow it to RM3,000? Rebuilt the
      same disposable scenario (application #86 this time, same
      student2@utp.edu.my recipe, deleted after) and checked
      `confirmable` on December's row directly before testing either
      branch — it read `no`. The `eligible` branch's fold-in (added
      the round before) hardcoded `$confirmable = false` unconditionally,
      a leftover from when this branch only ever showed the plain flat
      stipend with nothing to confirm; now that it can carry a real
      lagged figure, that figure could never actually be marked paid —
      the real "Mark Payment Complete" button would never have
      appeared for it at all.
  - [x] Fixed: `confirmable` (and `displayStatus`) now depend on
        whether `$laggedMode` is active for this row — a plain flat
        month stays `eligible`/not confirmable exactly as before; a
        row showing a real folded figure becomes `payment_pending`/
        confirmable, same convention the withheld/backdated branch
        already uses.
  - [x] Verified live, both branches, rebuilding the scenario fresh for
        each: confirmed December (RM1,000, now genuinely confirmable)
        → January read RM2,000, reset to the plain flat rate exactly
        as predicted. Left December unconfirmed instead → January read
        RM3,000 (1,000 + December's held-back 2,000), matching the
        already-established permanent-lag pattern. `php artisan test`
        42/42. Disposable application #86 fully deleted afterward.
- [x] **2026-11-03 — UI pass across the whole module: standardise
      against the rest of the portal, fix what doesn't match.** User:
      "kau fix the ui cantikkan. follow design ui module lain
      standardise." Clarified scope first (central/shared dashboard vs
      this module's own screens) — user confirmed to leave the shared
      dashboard alone for now and focus purely on standardising within
      `app/Modules/Haziq/`.
  - [x] **Real bug found: `.btn-secondary` was being overridden
        module-wide.** `styles.blade.php` redeclared `.btn-secondary`
        with only `background`/`color`/`border` — no padding,
        border-radius, font-size, font-weight, cursor, or the `:hover`
        state that `public/css/layout.css`'s own (fuller) definition
        already has. Both rules share identical specificity, and
        Haziq's loads later (`@push('head')` renders after
        `core::partials.stylesheets`), so Haziq's incomplete version
        won on every single page — every secondary button in this
        module (GRA/GA "Not Complete", "Save Allowance", edit links,
        etc.) has been rendering stripped-down compared to every other
        module's. Removed the redundant redeclaration entirely —
        nothing in Haziq's own views needs anything different from the
        shared button, so there was never a reason to redefine it.
        Checked every other class `styles.blade.php` declares
        (`.status-badge.*` tones, `.alert-banner`, `.haziq-*`) against
        both `layout.css` and `uresearch.css` for the same kind of
        collision — none found; `.btn-secondary` was the only one.
  - [x] **Real UX bug found in TA Hours:** the "Student" field was a
        plain `<input type="number">` asking whoever is recording hours
        to type a raw numeric user ID from memory. Replaced with a
        `<select>` populated from `TaHourController::
        selectableStudents()`, scoped the same way `store()` already
        enforces access (a Supervisor only ever sees their own
        supervisees, Admin/CGS see everyone).
  - [x] Added two charts to TA Hours, consistent with the "visual
        trend" pattern already used on Allowance/Stage Gate — a
        student's own last-6-months bar chart against the 21hr cap
        (`monthlyTrendFor()`), and, for Admin/CGS/Supervisor, a
        this-month-by-student bar chart so an approver can see who's
        closest to the cap before logging more hours for them
        (`byStudentThisMonth()`). Both colour-coded green/amber/red at
        the same 18hr/21hr thresholds the cap itself uses.
  - [x] Reviewed every other screen for structural consistency against
        Norhanis' reference module and the shared `core::partials.queue`
        pattern — GRA/GA queue, index, show, candidature appeal create/
        queue all already correctly follow the established `.card`/
        `.card-wide`/`.app-item`/`.status-badge`/`.empty-state`
        conventions; no further changes needed there.
  - [x] Verified live: `/allowance` and `/ta-hours` both render
        correctly post-fix; TA Hours' student dropdown is populated
        with real students scoped correctly; the trend/by-student
        charts render with real (if currently zero) data. `php artisan
        test` 42/42 throughout.
- [x] **2026-11-05 — clarified, no code change needed: a "CGS forgot to
      confirm April's payment" scenario can't actually occur when
      Stage 1 completed on time.** User posed a hypothetical:
      Stage 1 complete on time (never withheld), Stage 2 still
      incomplete, CGS never confirms any month's payment, Stage 2's
      grace period lapses and terminates the candidature, appeal
      approved not on the 1st — is the pending payment shown, can CGS
      set the partial month? Built a disposable application
      (temporarily #87, deleted after) to verify precisely: the
      resumption month read RM0.00, `payment_pending` (visible, not
      blank), `reviewable=yes`, `confirmable=yes` — same as the
      already-verified Case A pattern.
  - [x] The clarification: `AllowancePaymentConfirmation` only ever
        applies to a `withheld`/`backdated` debt or a lagged
        resumption figure — a plain `eligible` flat monthly stipend
        (every month here, since Stage 1 was never withheld) is never
        individually confirmed in the first place; there's no button
        for it and no tracked state. So whether CGS "forgot" to
        confirm any given month makes no difference —
        `priorWithheldTotal()` only ever reads Stage 1's
        withheld/backdated running total, which was always zero.
        `php artisan test` 42/42.
- [x] **2026-11-05 — the corrected version of that scenario: Stage 1
      completed late (not on time), CGS confirms every month except
      one right before termination, verified end to end.** User
      revised the premise after the entry above: CGS genuinely has
      been clicking "Mark Payment Complete" every month (so Stage 1
      must have been completed *late* — a plain never-withheld
      `eligible` row has no confirm button at all, as the entry above
      established), except one month (RM2,000) right before Stage 2's
      termination. Does that RM2,000 stay confirmable, and can CGS
      also set a partial-month figure on the appeal month (not the
      1st)?
  - [x] First build attempt caught a mistake in the test itself, not
        the code: directly injecting `AllowancePaymentConfirmation`
        rows onto `withheld` (Stage 1 not yet completed) months —
        `confirmable` is *never* true for `withheld`, only for
        `backdated` (Stage 1 completed, even late). A `withheld` row
        can't actually be confirmed through the real UI, so that build
        wasn't representative. Rebuilt with Stage 1 marked
        `completed`/`backdated` right as its grace period expired
        (no artificial backlog), then confirmed every month for real
        through to June 2027, deliberately skipping July.
  - [x] Verified live: July 2027 (unconfirmed) read RM2,000 /
        `payment_pending` / confirmable — genuinely clickable this
        time. Candidature terminated the following month (Stage 2);
        since Stage 1 was already `completed`, the freeze is Case A
        (stays visible/confirmable throughout, never blank) — October
        2027, three months into the termination and still unappealed,
        still read RM2,000/confirmable. Appealed and approved on
        2027-10-05 (not the 1st): row still read amount=2000,
        `payment_pending`, **`confirmable=yes` and `reviewable=yes`
        simultaneously** — CGS can mark the RM2,000 paid and
        separately set October's own partial-month figure (which
        lands next month, same as always). Matches exactly. `php
        artisan test` 42/42. Disposable application deleted afterward.
- [x] **2026-11-05 — Stage Gate's student view showed every future
      deadline the moment an application was approved, months before
      the student's own service had even begun.** User set
      application #25's `service_start_date` two months ahead and
      caught it: the student's own `/stage-gate` page listed all 3
      stages (deadlines a year or more out) while Allowance correctly
      showed nothing. Asked whether to hide this for the student view
      only, or for CGS/Admin's monitoring table too — confirmed
      **student view only**, since an approver watching the pipeline
      still benefits from seeing an appointment that hasn't started.
  - [x] Added `StageGateController::startedApplicationIds()` — an
        application only appears in the student's own Stage Gate list
        once `service_start_date`'s own *month* has arrived (not the
        exact day, matching "selagi tak masuk service date month"),
        checked against both `GraApplicationDetail` and
        `GaApplicationDetail`. Applied via `whereIn('application_id',
        ...)` on the existing student query, before pagination — the
        CGS/Admin `scopedQuery()` branch is untouched.
  - [x] Verified live: set application #25's `service_start_date` to
        2027-01-01 (two months ahead of real 2026-11-05) — student's
        `/stage-gate` now reads "No Stage Gates yet" instead of
        listing all 3 stages; `cgs@utp.edu.my`'s own monitoring table
        still shows all 3, confirming the scoping is exactly as
        agreed. Restored application #25 back to its
        `service_start_date = 2026-06-01` baseline afterward. `php
        artisan test` 42/42.
- [x] **2026-11-05 — Haziq's data now feeds the shared Student/CGS/Admin
      dashboards in `app/Modules/Core/`, with Haziq's explicit sign-off
      to proceed.** User asked what to put on the dashboard for each
      role, then said to just build it. Flagged first that
      `app/Modules/Core/` is team-shared and CLAUDE.md says not to edit
      it without asking — confirmed to proceed anyway
      ("proceed terus edit Core"). **This is a Core change and the
      other five owners haven't seen it — flag it in the next team
      sync**, same as any other shared-file change.
  - [x] Followed the existing cross-module pattern exactly
        (`Core\Contracts\SuppliesAttendance`, which Nureen implements) —
        never named a Haziq class from Core. Added
        `Core\Contracts\SuppliesPostgraduateProgress` (7 methods: a
        student's allowance/Stage-Gate-risk/TA-hours summary, plus
        portal-wide bottleneck/anomaly/active/terminated counts) and
        `Core\Services\Concerns\ReadsPostgraduateProgress` (mirrors
        `ReadsAttendance` — returns null if nothing's bound, dashboards
        hold that panel's skeleton). Implemented by new
        `Haziq\Support\DashboardSupplier`, bound in Haziq's own
        `ModuleProvider::register()` (previously had no `register()` at
        all).
  - [x] **Every number is a thin pass-through to that controller's own
        public method, never a second calculation** — added
        `AllowanceController::summaryFor()` (reuses `rowFor()` +
        `needsImmediateDisplay()` + `applicationsPendingFirstPay()`,
        same as `index()`) and `::anomalyCount()`,
        `StageGateController::riskiestOpenGateFor()` (reuses
        `riskPercent()` + `startedApplicationIds()`), and
        `TaHourController::thisMonthFor()`. The dashboard card can never
        show a different figure than that module's own page would for
        the same student right now, by construction.
  - [x] Checked the real layout risk before touching anything: all
        three dashboard blade files still say "single screen, never
        scrolls, by requirement" in their comments, but the CSS was
        already refactored (commit `d3129cc`, before this session) to
        natural-height-and-scroll — the comments are stale, not the
        actual behaviour. `.sdash-stats` is a shared `flex-wrap` row
        explicitly designed to take any card count without editing the
        CSS, used identically by all three dashboards, so adding cards
        to CGS/Admin was low-risk; the student panel uses the same
        `.sdash-card` pattern as every other student panel.
  - [x] Added: a new "Postgraduate Progress" panel on the student
        dashboard (allowance payable, riskiest open Stage Gate risk %,
        TA Hours vs. cap — each linking back to its real page); two new
        stat cards on CGS's dashboard (Stage Gate Bottleneck, Allowance
        Anomalies); two new stat cards on Admin's (Active/Terminated
        Candidatures). Supervisors were deliberately left out — they
        land on the generic `dashboard/approver.blade.php`, shared by
        every module's approver stage, not a CGS-specific screen, so
        enriching it would need a design decision beyond just Haziq's
        own data.
  - [x] Verified live as all three roles: the contract resolves to
        `DashboardSupplier` via `app()`; `student@utp.edu.my`'s panel
        reads "RM 2,000.00 / 71% / 0.0 / 21 hrs", matching a direct
        tinker call to the same methods exactly; `cgs@utp.edu.my`'s two
        new cards read "1" and "0"; `admin@utp.edu.my`'s two new cards
        read "1" and "0" — both matching the portal's real current
        state (one active candidature, none terminated). `php artisan
        test` 42/42.

---

## Cross-module overlaps — unresolved

Every scope document was cross-read on 2026-09-12. Four genuine collisions and
two dependencies came out of it. **None is a bug today** — most of the colliding
work is unbuilt — but each one becomes expensive the moment two people write
code against it. Settle them as a team before the next module starts.

### 1. Haziq's GRA/GA vs Nureen's shipped GA modules — RESOLVED 2026-09-21

`app/Modules/Nureen` already ships and works:

| Built | Key | Chain |
|---|---|---|
| GA Extension & VISA | `ga_extension` | Supervisor → CGS Staff → Senior Director CGS |
| GA/GRA Certification Letter | `ga_certification` | CGS Staff → Senior Director CGS, Dompdf on approval |

**Decision:** Haziq owns the GRA/GA *application* lifecycle (new + GRA
extension) end to end. Nureen keeps `ga_extension` and `ga_certification`
exactly as built — Haziq's `ga_application` never covers GA extension, and
GRA Extension is not a separate key (it reuses `gra_application` with
`application_type = 'extension'`), so there is no key or table shared
between the two folders. See `docs/module-keys.md` for the exact split.

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

- [ ] **Haziq's Stage 1 *is* Norhanis' RPD.** Built 2026-09-21 as a
      standalone CGS-recorded field (`stage_gates.completion_status`) since
      Norhanis' RPD module doesn't exist yet — functions correctly on its
      own today, but once she lands an RPD data model, decide whether her
      module should write Haziq's Stage 1 completion directly or whether
      these stay two separately-recorded facts about the same event.
- [ ] **Jason's Appointment Letters depend on Hani's examiner pool.** The
      letter is addressed to an examiner; `examiners` is Hani's table.
      `hani.md` records she handed Appointment Letters to Jason deliberately —
      so this is a handoff with a data dependency, not a duplicate.

---

## Cross-cutting

### Correctness gaps in Core worth closing
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
      never touch a developer's own data. `php artisan test` — 13 tests in
      under a second.
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
- [ ] **Seed demo data in `DatabaseSeeder`.** A teammate running `./setup.sh`
      gets 11 accounts and empty dashboards: no attendance rows, nothing in a
      CGS queue, no notifications. Demo data was created locally during
      development but deliberately not committed to the seeder, so it exists
      on one machine only. Worth fixing before the FYP demo.
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
      Jason's Hardbound Submission needs this; agree the design before
      building that module's review stage.
- [ ] Seed a `senior_exec_cgs` test account — no seeded user has this role
      yet, and Jason's Hardbound Submission and Appeal chains both end there.
- [ ] **Actors named in scope documents that are not roles yet:** `GRS Exec`
      and `Research Centre` (Haziq), `Project Director` (Norhanis' Claims),
      `Faculty` (Norhanis' RPD dismissal, Jason). `Programme Chair` (Chloe) is
      probably the existing `chair`. Adding a role is one line in
      `Support\Role` by design — the work is deciding, not typing. The table
      in `docs/module-keys.md` tracks them.
- [ ] **`dac` and `panel_examiner` are declared but own no stage and have no
      seeded account.** Either a module needs them or they should go.

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
- [~] Automated tests — the harness exists and 13 tests cover the parts that
      break quietly: both authorisation locks, approve/reject outcomes,
      Travel's conditional routing, the `stages(null)` superset, that every
      registered module's routes actually exist, and the attendance contract
      including its degradation path. **Still wanted:** a feature test per
      chain (GA Extension, Supervision, Certification, Attendance Appeal,
      Examiner Nomination), the attendance CSV/xlsx import, and
      `DocumentStore`'s allow-list. Each owner writing one for their own
      module is the cheap way to get there.
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
4. Norhanis does Publication and Claims — both are quick with the engine.
5. Hani closes the examiner lifecycle before touching re-viva.
6. Nureen settles the UTrace data source before starting Attendance.
7. RPD and Re-viva last — they are the two hardest, and both need scheduled
   commands.
8. Jason can start now, independently of the rest of the team — Hardbound
   Submission, Appeal Hardbound Submission and Appointment Letters all reuse
   existing roles. Settle the "return to student" engine question and seed
   the `senior_exec_cgs` account before building the Hardbound Submission
   review stage.
9. **Before Chloe or Haziq writes any code, hold one meeting** and settle the
   four overlaps above. Haziq's is the urgent one — it collides with modules
   that already exist and have rows in the database.
10. Chloe should start with **Workstation Management**. It is the only part of
    her scope that overlaps with nobody, so it is unblocked by that meeting,
    and it is a good first module because it is not an approval chain.
11. The **"return with comment"** engine outcome is now needed by two people
    (Jason's Hardbound review, Chloe's candidacy appeal). One design decision,
    one Core change, two modules unblocked — worth doing early rather than
    twice.
