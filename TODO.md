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
| Norhanis — Travel | done · reference implementation |
| Norhanis — Publication · Claims · RPD | not started |
| Nureen — GA Extension | done |
| Nureen — Attendance · Supervision · Certification | not started |
| Hani — Examiner pool + Nomination | done |
| Hani — Conflict detection T2 · Re-viva | not started |
| Jason · Chloe | scope not yet defined |
| Automated tests | none |
| **Runs end to end** | yes — verified 2026-09-09 |

---

## Verified working

Run end to end on 2026-09-09 (Ubuntu 24.04 / WSL2, PHP 8.3.6, MySQL 8.4.11):

- [x] `./setup.sh` completes from a clean clone and an empty volume
- [x] All 9 migrations run; 11 accounts and 5 examiners seeded
- [x] All 20 routes register, including every module — auto-discovery works
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

- [ ] **Attendance Record** — the predictive piece
  - [ ] **Decide where UTrace data comes from** — API, CSV export, or manual
        upload. Everything else is blocked on this; settle it first.
  - [ ] `attendance_records` table + percentage calculation
  - [ ] At-risk rule against the 80% threshold (rule-based, per the refined scope)
  - [ ] Proactive alerts to student and supervisor
  - [ ] CGS "At-Risk" dashboard list
  - [ ] Attendance appeal workflow routing to CGS

- [ ] **Supervision** — supervisor appointment requests
  - [ ] Request → Supervisor → CGS eligibility review
  - [ ] Specific feedback on rejection
  - [ ] Reminder escalation when an approval stalls (scheduled command)
  - [ ] On approval, set `users.supervisor_id` — this is what makes supervisor
        queue scoping work for everyone

- [ ] **GA/GRA Certification Letter**
  - [ ] Field completeness check, GA vs GRA verification by CGS
  - [ ] Approver endorsement stage
  - [ ] PDF generation with Dompdf (already in `composer.json`, not yet used)
  - [ ] Store the generated PDF as an `ApplicationDocument` so the existing
        download route and its permission check apply

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

## Jason · Chloe

- [ ] Define scope, then claim a `module_type` in `docs/module-keys.md`
- [ ] Folders exist with a README and the pattern to copy

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
- [ ] Profile / change-password screen.
- [ ] A withdraw/cancel action for students on a pending application.

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
