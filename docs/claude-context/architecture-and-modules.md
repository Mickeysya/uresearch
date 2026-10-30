# UResearch 2.0 — Architecture & Nureen's Modules

Reference doc for syncing a Claude Project's context. Written as a standalone
summary — the canonical, always-current versions of this material live in
`docs/architecture.md`, `docs/scope/nureen.md`, `docs/conventions.md` and
`TODO.md`; this file will drift from the code over time, those won't. Point
a connector at them directly if your Project setup allows it.

## What it is

Centralized postgraduate administrative portal for the Centre for Graduate
Studies (CGS), Universiti Teknologi PETRONAS. A six-person Final Year
Project, rewritten from a legacy raw-PHP app into Laravel.

**Stack:** Laravel 12 · PHP 8.3 · MySQL 8.4 (Docker) · Blade · Chart.js ·
Dompdf. MySQL, phpMyAdmin and Mailpit run in Docker; Laravel runs on the
host via `php artisan serve`.

## The one idea everything else follows

Six people share this repo, and each owns exactly one folder under
`app/Modules/`:

| Folder | Owner | Modules |
|---|---|---|
| `Core/` | the team | shared foundation |
| `Norhanis/` | Norhanis Erna Natasha | Travel · Publication · Claims · RPD |
| `Nureen/` | Nureen Nellysha | Attendance · GA Extension · Supervision · Certification |
| `Hani/` | Nur Hani Sofia | Examiner Nomination · Conflict Detection · Re-viva |
| `Jason/` | Yau Jia Sheng | Hardbound Submission · Appeal · Appointment Letters |
| `Chloe/` | Chloe Ching Qing En | Workstation · Study Candidacy Reminder/Appeal/Dismissal |
| `Haziq/` | Abdul Haziq bin Abdul Farouk | GRA · GA · Stage Gates · Allowance Eligibility |

Everything a module needs — migrations, models, controllers, routes, views —
lives entirely inside its own folder. Nothing central lists the modules:
`ModuleServiceProvider` scans `app/Modules/*` at boot and wires up whatever
it finds there (`ModuleProvider.php`, `routes.php`,
`Database/Migrations/`, `Resources/views/`). This is what lets six people
add modules in parallel without merge conflicts — nobody edits a shared
registry file to plug their module in.

`Core/` is the one shared folder, changed only by team agreement, because a
change there can affect all six people at once.

## Layers

| Layer | What it is here |
|---|---|
| Presentation | Blade templates + per-screen stylesheets; Chart.js |
| Application | Laravel controllers, one thin controller per module |
| Shared services | `WorkflowEngine`, `ModuleRegistry`, `DocumentStore`, role middleware, notifications |
| Data | MySQL 8.4, accessed through Eloquent |
| External | SMTP via Mailpit locally; real SMTP in production |

## Data model

```
users ──────────< applications >────── approval_history
                      │      │
                      │      └───────< application_documents
                      │
                      └── your own <module>_details table
```

- **`applications`** is the shared spine every module's rows live in:
  `student_id` (who the application is about), `submitted_by_id` (who filed
  it — usually the same person, but not always), `module_type`, `status`
  (`draft`/`pending`/`approved`/`rejected` — only whether it's open, done or
  dead), `current_stage` (a `Stage::key`).
- **`application_documents`** is already **one-to-many per application** —
  `Application::documents(): HasMany`. Every upload, from every module,
  lands here. This matters: a module never needs a schema change to attach
  more than one file to an application.
- A module never adds columns to `users`, `applications`,
  `approval_history` or `application_documents`. It gets its own
  `<module>_details` table instead (e.g. `ga_extension_details`,
  `supervision_details`).

## The workflow engine

A module declares its approval chain once, as an array of `Stage` objects
(key, label, role, decision verb), optionally branching on the
application's own data:

```php
public function stages(?Application $application = null): array
{
    return [
        new Stage('supervisor', 'Lecturer/Supervisor', Role::SUPERVISOR, 'endorsed'),
        new Stage('chair',      'Chair of Department', Role::CHAIR,      'approved'),
    ];
}
```

From that one declaration: routing, the student's progress stepper, every
approver's queue, authorization, the sidebar, and notification emails.

`WorkflowEngine` is the **only** code allowed to write `applications.status`
or `applications.current_stage`. Three entry points matter:

- **`submit()`** — puts the application onto stage 1.
- **`decide()`** — approve/reject. Re-checks the actor's role against the
  application's *actual current stage* (not just the route's role
  middleware — one queue route serves every stage of a chain), appends to
  `approval_history`, advances to the next stage or marks the application
  approved when the chain runs out.
- **`returnTo()`** — sends an application back to an earlier stage with a
  **mandatory** reason, instead of ending it. Used where a stage wants
  "someone earlier has to redo this" rather than "rejected, full stop"
  (GA Extension's CGS Staff use it to send an incomplete application back
  to the supervisor; the Senior Director and the Dean use it on examiner
  lists).

If a module's own `decide()` does more than delegate straight to the engine
(checks which supervisor a row names, moves a deadline, generates a
letter), it implements the marker interface `Core\Contracts\DecidesOneAtATime`
so Core's generic bulk-decide refuses that module rather than silently
skipping its extra logic. Supervision and Certification both implement
this.

## Document storage

`DocumentStore` is the **only** sanctioned upload path — never
`move_uploaded_file()`, never anything written under `public/`. A file is
renamed to a random string on the private `local` disk (so an uploaded
`.php` can never be requested back as a script) and is only ever streamed
back through `DocumentController`, which checks the viewer is the student
who owns the application, an approver on its chain, or an admin.

Because `application_documents` is already one-to-many, attaching several
files to one application never needs a schema change. Two sets of helpers
on `DocumentStore` cover the two shapes a module needs:

- **`rules()` / `attach()`** — the original single-file path. Still used
  by every module that only ever takes one document (Jason's memo, Hani's
  thesis, Norhanis's various Publication fields, Chloe's appeal form).
- **`manyRules()` / `manyMessages()` / `attachMany()`** — added for
  multi-file fields (1–5 files, configurable type/size/count limits, one
  `doc_type` label per field). Purely additive — nothing about the
  single-file path changed, so adding these never touched any other
  module's upload. GA Extension and Supervision both use this; GA
  Extension calls it three times (once per required document type) to
  keep each type separately validated and separately labelled.

## Nureen's four modules

### Attendance

Predictive at-risk flagging, now with automated nightly ingestion instead
of a CGS staffer uploading a spreadsheet by hand.

- **Three automated sources**, picked by one config key
  (`config('nureen.attendance.source')`, `.env`-overridable): an inbox
  folder a Power Automate export (or anything standing in for one) drops
  files into; an API-key-authenticated POST endpoint that stages JSON
  records for the next sync to consume; and a simulated source that
  generates one plausible week per seeded student for a demo with nothing
  real to point at.
- **`attendance:sync`**, self-scheduled nightly at 02:00 from
  `Nureen\ModuleProvider::boot()` via `Schedule::command(...)` — this
  registers onto the same scheduler `artisan schedule:run` reads
  regardless of which file calls it, so no shared `routes/console.php`
  line was needed. Also reachable on demand via a "Sync Now" button on the
  upload page.
- **The manual upload stays**, relabelled "Manual Fallback" beneath a new
  "Automated Sync" status panel (active source, last run, read/added/
  updated/rejected counts) — both paths now share one row-ingestion
  routine (`Support\AttendanceIngestor`) so they can never drift apart.
- Idempotent for free: every source feeds `AttendanceRecord::recordPeriod()`,
  which already upserts on `(student_id, period_end)`.
- The existing risk rule (`AttendanceRiskEvaluator`: below 80%, or
  declining and within 5 points of it) and the existing at-risk
  notification run unchanged after every sync, same as after a manual
  upload.

Chain: Appeal → Non-Executive CGS.

### GA Extension

Extends a Graduate Assistantship. Now does the completeness checking and
profile cross-referencing CGS staff used to do by hand.

- **Read-only applicant block** at the top of the form (name, matric no.,
  programme, supervisor) straight from the logged-in student's own
  profile — not submitted as fields, so there is no second, divergent copy
  of that data to keep in sync.
- **Four separately-required document types** instead of one
  undifferentiated upload: the GA extension form, an identity document
  (IC or passport/visa copy), the supervisor's supporting letter, and an
  acceptance letter *or* published paper (either satisfies that last one).
  Each is its own `<x-nureen::multi-file-upload>` field, validated and
  labelled separately, so a missing type blocks submission with a specific
  message rather than a generic "attach something."
- **"System checks" panel** shown to approvers: a live ✓/✗ per required
  document type, computed from `$application->documents` against the same
  config list that enforced it at submission — so it can never say
  something was checked that wasn't, or vice versa.
- **CGS Staff can send an incomplete application back to the supervisor**
  (`WorkflowEngine::returnTo()`, mandatory reason) instead of rejecting it
  outright — the same pattern Hani's Examiner Nomination module already
  established for sending a compiled list back to a department.
- **Extensible by config, not by code**: `config('nureen.ga_extension')`
  holds both the required-document list and an `additional_fields` array
  (key → label/type/required) for whatever the real CGS online form turns
  out to need. Today that array is empty; adding a field later — and
  having it appear in the form, the validation, and the approver's detail
  panel — is one config entry, no migration, no Blade edit. The storage
  for it (`ga_extension_details.additional_info`, nullable JSON) already
  exists, waiting.
- **Stalled-application reminder**: if a pending application sits at one
  stage past the configured threshold (3 days by default), the current
  stage's approver is emailed — see "Reminders & analytics" below.

Two chains (2026-10-31, realigned with CGS's confirmed UResearch process —
see `docs/scope/nureen.md` §5), chosen per application at submission and
snapshotted onto `GaExtensionDetail::final_approver_snapshot` so a later
config change never alters a chain already in flight:
- `'cgs'` (default, `config('nureen.ga_extension.final_approver')`):
  Supervisor → Non-Executive CGS, **final**. The Senior Director has no
  stage here; `GaExtensionController::decide()` emails them every outcome
  informationally instead, and separately copies the endorsing supervisor
  on the final decision (both as dedicated Nureen notifications, not a
  literal mail header CC, since the generic per-decision email is Core's
  `ApplicationDecided`).
- `'senior_director'`: the original three-stage chain, Supervisor → CGS
  Staff (`cgs_verify`, intermediate) → Senior Director CGS
  (`senior_director`, final).
`GaExtensionWorkflow` implements `DecidesOneAtATime` for this reason — its
`decide()` now does more than call the engine.

### Supervision

Main and Co-Supervisor appointment requests. The one module where the
stage's role holder is *not* automatically the right recipient — the
`supervisor` stage is addressed to one **named** person, not "anyone
holding the Supervisor role," which is why its reminder command stays its
own, rather than reusing the generic one GA Extension and Certification
share.

- Request → the specifically named supervisor endorses → **Chair of
  Department** (of the student's own department; `Role::isDepartmentScoped()`)
  approves, with a mandatory supporting document (1–5 files, the same
  `DocumentStore::manyRules()`/`manyMessages()` as GA Extension). The
  request also states an appointment role (Main / Co-Supervisor) and type
  (New / Existing).
- Two chains (2026-10-31, realigned with CGS — see `docs/scope/nureen.md`
  §5), snapshotted onto `SupervisionDetail::cgs_stage_snapshot`:
  `'automatic'` (default, `config('nureen.supervision.cgs_stage')`) makes
  the Chair's approval **final** — the appointment letter (Dompdf, a
  `CGS/SVAPPT/<year>/<seq>` reference via `Support\AppointmentReferenceGenerator`,
  the same race-safe pattern as `CertificateReferenceGenerator`) is issued
  immediately, emailed to the student with the supervisor CC'd, and listed
  on a CGS-only "Issued Appointment Letters" page where it can be revoked
  with a mandatory reason; `'manual'` keeps an explicit `cgs_review` stage
  after the Chair, with the letter generated only on CGS's own approval.
- A **Main Supervisor** approval writes `users.supervisor_id` (capturing
  the previous value first, so a revocation can restore it); a
  **Co-Supervisor** approval never touches it. Revoking a Main Supervisor
  letter restores the previous supervisor only if `users.supervisor_id`
  still equals the one this letter appointed — if a later appointment has
  since superseded it, the restore is skipped and CGS is told why.
- A Co-Supervisor request naming someone who is already the student's Main
  Supervisor, or already an active (approved, unrevoked) Co-Supervisor for
  them, is blocked at submission with a clear message.
- **Stalled reminders with escalation**: `supervision:remind-stalled`
  escalates at both the Supervisor stage and the Chair stage (department-
  scoped recipients) — after a configurable number of reminders (2 by
  default) with still no decision, CGS Staff is also notified, purely
  informationally ("this request is stuck, nothing needed from you yet").
  The reminder count is tracked per-*current-stage*, so it resets cleanly
  if the request moves on before escalating, rather than carrying a stale
  count forward.
- `DecidesOneAtATime`: its `decide()` double-checks the acting supervisor
  is the one actually named on the request (a gap the engine's normal
  role-based authorization can't catch on its own, since every
  Supervisor-role account would otherwise pass it).

Chain (`'automatic'`, default): Request → named Supervisor → Chair of
Department (`chair`, final). Chain (`'manual'`): → Chair (`chair`,
intermediate) → Non-Executive CGS (`cgs_review`, final).

### GA/GRA Certification Letter

Generates a certification letter PDF on final approval. Now verifiable,
and backed by an optional automatic check before CGS ever has to look at
it by eye.

- **A named-supervisor stage first** (2026-10-31, realigned with CGS — see
  `docs/scope/nureen.md` §5): the student's own supervisor at submission
  time (`GaCertificationDetail::supervisor_id`, snapshotted the same way
  Supervision's `requested_supervisor_id` is) reviews before CGS Staff ever
  sees it. Submission is blocked up front with a clear message if the
  student has no supervisor on record. `CertificationController::decide()`
  checks this the same way `SupervisionController` does — holding the role
  is not enough, it has to be the named person.
- **Type-specific fields**: GA certifies against a course (code, name,
  course owner, semester); GRA against a research project (title, leader,
  semester, optional grant/project code) — `config('nureen.ga_certification.fields')`,
  the form shows and validates only the chosen type's list (a small vanilla-JS
  toggle, degrades to both blocks hidden without JS — same pattern as
  Chloe's candidacy-appeal form). The original `purpose` field is kept, now
  optional, alongside the type-specific ones rather than replaced by them.
- **Automatic appointment verification**, off by default
  (`nureen.ga_certification.appointment_check_enabled` — not part of CGS's
  confirmed process) and shown to CGS Staff and the Senior Director only
  when turned on: Verified / Not Found / Mismatch, computed live against a
  provisional table (`ga_appointment_records`) — informational only, never
  blocks a decision either way. Haziq's module owns GA/GRA appointment data
  *in principle* but has no models or migrations yet (his own module README
  already flags the overlap as a team decision pending); once that's
  settled, the seam to swap onto his real data is a single
  `AppointmentVerifier` change, documented in `SHARED_CHANGES.md`.
- **A unique, sequential reference number** (`CGS/GACERT/<year>/<seq>`,
  locked against races) assigned the moment the Senior Director's approval
  generates the certificate.
- **A public verification page**, no login: `/verify/certificate/{reference}`
  shows exactly four facts (student name, letter type, issue date,
  endorsing approver — read from `approval_history`, not re-stored) plus
  Valid/Revoked. An unknown reference gets a clear "not found" message.
  Throttled, since it's reachable with no session at all.
- **A QR code on the PDF** linking to that page — or, until a QR-generating
  Composer package is added (none exists yet; documented in
  `SHARED_CHANGES.md`), the plain verification URL printed as text. The
  seam (`Contracts\GeneratesQrCode`) is already in place either way, so
  nothing else changes the day the package lands.
- **Revocation**: Non-Exec CGS or the Senior Director can revoke an issued
  certificate with a reason, from a new "Issued Certificates" list
  (`/ga-certification/issued`, linked from the queue's tools slot) — the
  only place to reach it once an application is no longer pending and has
  therefore dropped out of the approval queue. Revoking never touches
  `applications.status`; the application stays approved, only the
  certificate's own validity changes.
- The PDF template (`certificate_pdf.blade.php`) keeps its original,
  self-contained `<style>` block untouched in shape — still easy to
  restyle wholesale once a real CGS sample letter arrives.

Chain: named Supervisor (`supervisor`) → CGS Staff (`cgs_verify`) → Senior
Director (`senior_director`). `DecidesOneAtATime` (the Senior Director's
approval is what triggers PDF generation).

## Shared infrastructure Nureen built (reusable beyond her own modules)

- **`Support\StalledApplicationReminder`** — the generic half of "remind
  whoever needs to act": find stalled pending applications for a module,
  resolve recipients as everyone holding the current stage's role
  (department-scoped the same way the engine itself scopes `decide()`/
  `returnTo()`), record a reminder. GA Extension's reminder command is a
  thin wrapper over this; Certification's uses it for `cgs_verify`/
  `senior_director` but special-cases its own new `supervisor` stage
  (2026-10-31) to the one named person on the row, the same reasoning
  `RemindStalledSupervisionRequests` has always needed — the generic class
  has no way to know a module's own named-recipient rule, so that one
  `match` stays in each command, not pushed down here.
- **`Support\ProcessingTimeAnalytics`** — average days per `(module,
  stage)`, computed in PHP from consecutive `approval_history` rows per
  application rather than a database window function, so the same
  calculation is portable across the MySQL dev database and the SQLite
  in-memory test suite. Backs the CGS-only `/reports/processing-time`
  page (a Chart.js bar chart + an overdue-applications table, the overdue
  threshold reusing the exact same per-module config the reminders fire
  on, so the two can't drift apart).
- **The config-driven extensibility pattern** (`GA Extension's
  `additional_fields`, `required_documents`; Attendance's `source`,
  `reminders.*`) — every place a requirement might still change before
  the real CGS forms are confirmed is a config array read by the
  controller, the validation, and the relevant view, rather than
  hardcoded in three places that could drift.

## Dashboards (brief)

Six dashboards total: student, CGS, admin, Chair, Supervisor, and a shared
"approver" screen every other approving role lands on. Each is a service
class (one named method per panel) plus a Blade view built from partials —
changing a data source is a one-method edit, no view/route/controller
change needed.

The student dashboard is natural-height (the page scrolls); CGS and Admin
are locked to one viewport on desktop "by requirement," with each panel
declaring whether it scrolls internally or is sized to never overflow. See
`docs/architecture.md` → "The dashboards" for the full detail.

## Hard rules that govern any change here

1. Never write `status` or `current_stage` outside `WorkflowEngine`.
2. Never add columns to `users`, `applications`, `approval_history` or
   `application_documents` — a module gets its own `<module>_details`
   table.
3. `module_type` and `Stage::key` are stored in rows and are permanent once
   live. Labels are display-only and safe to reword.
4. Uploads go through `DocumentStore`. Never `move_uploaded_file()`, never
   write under `public/`.
5. Validate every input with `$request->validate()`. Never touch `$_POST`.
6. Blade `{{ }}` escapes; `{!! !!}` does not — never pass user input to
   `{!! !!}`.
7. Secrets live in `.env`, never hardcoded.
8. Scope every query — students see only their own rows, approvers only
   their stage.
9. A change inside `Core/` affects all six people — flag it and explain the
   impact rather than making it silently. In practice, every Nureen
   feature above shipped without touching a single Core or shared file;
   the handful of genuinely shared changes needed (a CSRF exemption for
   the attendance ingest endpoint, a QR-code Composer package) are
   documented, not applied, in `app/Modules/Nureen/SHARED_CHANGES.md`.

## Where the canonical docs live

| Doc | Covers |
|---|---|
| `README.md` | setup, test accounts, commands |
| `docs/architecture.md` | full layer/engine/data-model detail, dashboards, stylesheet load order, the audit trail |
| `docs/conventions.md` | naming, ownership table, the nine rules, which stylesheet to edit |
| `docs/scope/nureen.md` | Nureen's module breakdown as originally scoped for the FYP report |
| `docs/module-keys.md` | claiming a `module_type` |
| `docs/migration-from-legacy.md` | every bug the Laravel rewrite fixed |
| `TODO.md` | what is actually built vs. only scoped, and the agreed order of work |
| `docs/test-accounts.md` | every seeded login, by role |
| `app/Modules/Nureen/SHARED_CHANGES.md` | every shared-file change Nureen's work needs but hasn't applied, with the exact line and why |
