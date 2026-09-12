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
| Nureen — GA Extension · Attendance · Supervision · Certification | done |
| Hani — Examiner pool + Nomination | done |
| Hani — Conflict detection T2 · Re-viva | not started |
| Jason — Hardbound Submission · Appeal · Appointment Letters | scoped, not started |
| Chloe | scope not yet defined |
| Automated tests | none |
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

### Docs
- [x] `README.md`, `CLAUDE.md`, `LEGACY.md`, this file
- [x] `docs/` — architecture, adding-a-module, conventions, module-keys, migration-from-legacy
- [x] `docs/scope/` — the four FYP documents
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
  - [x] Attendance appeal workflow (`attendance_appeal`, single stage to
        Non-Executive CGS), reachable from a student's "My Attendance" page

- [x] **Supervision** — supervisor appointment requests
  - [x] Request → Supervisor → CGS eligibility review
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

- [x] **GA/GRA Certification Letter**
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

## Chloe

- [ ] Define scope, then claim a `module_type` in `docs/module-keys.md`
- [ ] Folder exists with a README and the pattern to copy

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
- [ ] `tests/` does not exist, though `composer.json` maps `Tests\` to it.
      Create it, or drop the `autoload-dev` entry.
- [ ] Password reset UI — the `password_reset_tokens` table exists, no screens.
- [ ] **Core now names a module directly, for the first time.**
      `Services\StudentDashboard` imports Nureen's `AttendanceRecord` and
      `AttendanceRiskEvaluator`; everywhere else Core goes through
      `ModuleRegistry`, which is what keeps the folder system conflict-free.
      It is guarded with `class_exists()` so a missing module degrades to a
      held skeleton rather than a fatal, but the clean fix is a
      `ProvidesDashboardPanels` contract. Worth doing before a second module
      wants a dashboard panel.
- [ ] Seed attendance rows in `DatabaseSeeder` — a teammate running
      `./setup.sh` currently gets an empty attendance gauge and no predicted
      figure, because no seeded student has any `attendance_records`.
- [ ] Give `/notifications`, `/calendar`, `/documents` and `/profile` real
      screens. The dashboard now links to all four and they are still
      `PageController` placeholders; the notification feed in particular has
      real data behind it now.
- [ ] Profile / change-password screen.
- [ ] A withdraw/cancel action for students on a pending application.
- [ ] A "return to submitter, application stays open" outcome for
      `WorkflowEngine::decide()` — currently only approve/reject exist.
      Jason's Hardbound Submission needs this; agree the design before
      building that module's review stage.
- [ ] Seed a `senior_exec_cgs` test account — no seeded user has this role
      yet, and Jason's Hardbound Submission and Appeal chains both end there.

### Team
- [ ] **Admin module** — user management and role assignment. `technical.md`
      lists System Administrators; nothing is built. Currently roles can only
      be set in the seeder or phpMyAdmin. Somebody needs to own this.
- [ ] Automated tests — at minimum a feature test per chain, asserting the
      wrong role cannot advance an application.
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
