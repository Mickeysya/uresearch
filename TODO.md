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
| Haziq — GRA · GA · Stage Gates · Allowance | scoped, not started |
| **Cross-module overlaps** | **4 unresolved — see below** |
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
