# Recent Updates

Changelog of changes made to this repo in recent Claude Code sessions, kept
for syncing a Claude Project's context. Each entry lists the problem, the
decision, what actually changed, and how it was verified, so a future
session (or a teammate) doesn't have to re-derive the reasoning from the
diff alone.

---

## 2026-10-31 — GA Extension, Supervision and Certification realigned to CGS's confirmed UResearch process

**Branch:** `develop`, uncommitted.

### The ask

Nureen validated her three approval modules' workflows with CGS through
written answers from CGS staff (the current process) and CGS's official
UResearch process workflow diagrams with Client Charter days (the new,
confirmed process). Where they agreed, the chain changed outright; where
they differed, the new process became the default behind a config switch so
it can be reverted without a code change. Attendance was explicitly out of
scope for this task.

### The design

Three config-driven decisions, each resolved **per application at
submission** and snapshotted onto that application's own detail row, so a
later change to the config can never alter the chain an in-flight
application is already running:

- **GA Extension** (`GaExtensionDetail::final_approver_snapshot`,
  `nureen.ga_extension.final_approver`): `'cgs'` (default) ends the chain at
  Non-Executive CGS, with the Senior Director emailed every outcome
  informationally; `'senior_director'` keeps the original three-stage chain.
- **Supervision** (`SupervisionDetail::cgs_stage_snapshot`,
  `nureen.supervision.cgs_stage`): `'automatic'` (default) makes the
  Chair of Department's approval final — the appointment letter issues
  immediately; `'manual'` keeps an explicit CGS eligibility-review stage
  after the Chair, with the letter generated only on CGS's approval.
- **Certification**: no mode switch (CGS's process did not differ here),
  but GA and GRA now show and validate only their own type-specific fields,
  defined in `nureen.ga_certification.fields`.

All three chains keep every Stage key that existed before this change
(`supervisor`, `cgs_verify`, `senior_director`, `cgs_review`) — a new stage
is *inserted*, never renamed, which is what lets an application already
sitting at any of these keys stay decidable exactly where it is regardless
of which config mode is live when it is next acted on
(`WorkflowEngine::currentStage()` looks a stage up by key, not position).

Existing in-flight applications, confirmed against the actual dev database
before writing any code:
- GA Extension: all backfilled to `final_approver_snapshot = 'senior_director'`
  (the only chain that has ever existed), so nothing already submitted
  changes behaviour.
- Supervision: backfilled to `cgs_stage_snapshot = 'manual'` so a row that
  reaches the end of its chain still gets a real CGS decision rather than
  silently skipping to an automatic letter it was never promised. Rows
  already sitting at `supervisor` do pick up the new Chair stage next —
  that is the point of the change. `appointment_role` backfilled to
  `'main_supervisor'` (accurate: the old code always wrote
  `users.supervisor_id` on approval); `appointment_type` left `null`,
  genuinely unknown, shown as "Not recorded".
- Certification: no snapshot needed — inserting the new `supervisor` stage
  before `cgs_verify` does not retroactively require an application that
  has already passed that point to go through it.

### What changed

- **Migrations** (3): `final_approver_snapshot` on `ga_extension_details`;
  `appointment_role`/`appointment_type`/`cgs_stage_snapshot`/
  `previous_supervisor_id`/`letter_reference`/`letter_issued_at`/
  `letter_revoked_at`/`letter_revoked_reason`/`letter_revoke_note` on
  `supervision_details`; `supervisor_id`/type-specific fields/`purpose`
  made nullable on `ga_certification_details`. All on Nureen's own tables.
- **`config/nureen.php`** — `ga_extension.final_approver`,
  `supervision.cgs_stage`/`reference_prefix`, `ga_certification.fields`/
  `supporting_document_label`/`appointment_check_enabled` (now off by
  default — not part of CGS's confirmed process), and `charter_days` (the
  official Client Charter working days per module/stage, recorded for a
  later task, not yet consumed by reminders or analytics).
- **Workflows** — `GaExtensionWorkflow` now implements `DecidesOneAtATime`
  (its `decide()` now does more than call the engine); `SupervisionWorkflow`
  gained the Chair stage; `CertificationLetterWorkflow` gained the
  supervisor stage.
- **Controllers** — `GaExtensionController::decide()` now copies the
  endorsing supervisor and, in `'cgs'` mode, informs the Senior Director.
  `SupervisionController` gained the Main/Co-Supervisor split (only Main
  writes `users.supervisor_id`, capturing the previous value first),
  appointment-letter generation (`Support\AppointmentReferenceGenerator`,
  same race-safe pattern as `CertificateReferenceGenerator`), the "Issued
  Appointment Letters" list, and revocation — which restores the previous
  supervisor **only if** `users.supervisor_id` still equals the supervisor
  this specific letter appointed; if a later appointment has since
  superseded it, the restore is skipped and CGS is told why, both in the
  redirect message and on the issued-list page. `CertificationController`
  gained the no-supervisor submission guard, type-specific field validation,
  and named-supervisor addressing at its new first stage.
- **New Nureen-owned classes**: `Support\AppointmentReferenceGenerator`,
  `Notifications\GaExtensionFinalDecisionCc`/`GaExtensionFinalDecisionInformational`,
  `Notifications\SupervisionFinalDecisionCc`/`AppointmentLetterIssued`/`AppointmentLetterRevoked`.
- **Views** — Supervision gained `appointment_role`/`appointment_type`
  fields on the form, a letter-status partial (mirrors Certification's
  certificate-status partial), an issued-letters list, and a CGS-only tools
  link. Certification's form now shows only the fields for the chosen type
  (vanilla JS toggle, degrades to both-hidden without JS, same pattern as
  Chloe's candidacy-appeal form); the detail view and the PDF letter follow
  the same type split.
- **Reminders** — `RemindStalledSupervisionRequests` extended for the Chair
  stage (department-scoped recipients, same escalation-to-CGS rule as the
  Supervisor stage). `RemindStalledCertification` now addresses its new
  `supervisor` stage to the one named person on the row, not every
  Supervisor-role user, the same reasoning as Supervision's own command.
- **`database/seeders/DemoDataSeeder.php`** — left untouched (shared,
  outside Core but affects all six people's demo scenarios); its
  `CHAINS`/`$outcomes` for these three modules are now stale for a **fresh**
  `demo:seed` run (existing seeded rows are unaffected — see
  `SHARED_CHANGES.md`). Added `NureenRealignedWorkflowDemoSeeder`
  (Nureen-owned, follows the `GaAppointmentRecordSeeder` convention) to seed
  fresh demo data under the new chains specifically.

### Bugs caught

- Certification's "Non-Executive CGS can always see an issued document"
  assumption (via `ApplicationDocument::visibleTo()`'s `approval_history`
  check) silently breaks in Supervision's `'automatic'` mode, where CGS
  never decides on the application at all. Worked around with a dedicated,
  role-gated download route rather than widening Core's shared rule — see
  `SHARED_CHANGES.md`.
- The Certification detail/PDF templates and `DemoDataSeeder`'s `CHAINS`
  array both needed updating for the new stage counts; caught by the full
  test suite failing on both before being fixed.
- An em dash crept into two new Supervision views
  (`Tests\Feature\Core\ProseTest`, this repo's own house-style check) —
  caught immediately by the full suite, not a real bug but worth noting
  since it would have blocked anyone relying on that test.

### Follow-up fix (same session, before commit)

A pre-commit check against the original ask caught one gap: Non-Executive
CGS could find an issued appointment letter on the "Issued Appointment
Letters" list, but was never actually *notified* the moment one issued —
only the student was. Added `Notifications\AppointmentLetterIssuedToCgs`,
sent to every Non-Executive CGS user from `SupervisionController::issueLetter()`
regardless of `cgs_stage` mode (the rule is "every issued letter", not
"every letter CGS did not already know about" — in `'manual'` mode CGS is
also the one who just approved it, and still gets the notification). Wired
into the demo seeder too, for parity. Covered by new assertions in all
three of `SupervisionTest`'s letter-issuing tests (automatic Main,
automatic Co-Supervisor, manual mode) plus a negative assertion that
nothing is sent before the Chair has actually approved. Full suite re-run
green afterwards: 338 passed, 1776 assertions (+4 from this fix).

### Verification

Full test suite: 338 passed (was 314 before this task; +24 net from new and
updated coverage across `GaExtensionTest`, `SupervisionTest`,
`CertificationTest`, `StalledReminderTest`, `CertificateVerificationTest`).
Live-verified in a headless browser against the running dev stack: the
Certification form's GA/GRA field toggle (JS-only behaviour feature tests
cannot exercise) in both light and dark mode; the Supervision "Issued
Appointment Letters" list showing both a Main and a Co-Supervisor letter as
Valid with working Revoke forms; an authenticated PDF download of a
generated appointment letter (caught and fixed a real-disk file-permission
issue along the way — `storage/app/private/applications/<id>` was
root-owned because it was created via `docker exec` as root rather than the
container's `sail` user that actually serves requests; chown'd the
affected directories rather than deleting any data); and the GA Extension
queue correctly labelling its CGS stage "Final Approval" in `'cgs'` mode.
`core-guard` reviewed the full diff against all eight project hard rules
(Core untouched, no other folder touched, status/current_stage only via
`WorkflowEngine`, no new columns on shared tables, no raw `$_POST`, uploads
through `DocumentStore`, no unescaped `{!! !!}`, no hardcoded secrets) —
pass on all eight.

---

## 2026-10-11 — Student dashboard: fixed nested scrolling and clipped cards

**Branch/commit:** `develop`, commit `a5da01b`
("fix: stop the student dashboard from clipping cards and scrolling inside
itself")

### The problem

On the student dashboard, the four panel cards ("My Application Status",
"Attendance Overview", "Recent Notifications", "Upcoming Tasks") had fixed
heights with their own internal scrollbars. Content got clipped — the
"Attendance Appeal" row in My Application Status was cut off, and the
attendance gauge chart in Attendance Overview was only half visible.

### Root cause

`public/css/dashboard-student.css` carried a media query
(`min-width: 1201px and min-height: 700px`) that pinned `.sdash` to a fixed
viewport height on desktop and made every `.sdash-card-body` scroll
internally — the classic "one-screen dashboard" pattern. That selector
applied to **all three** `.sdash` dashboards (student, CGS, admin), but
only CGS and Admin actually want it (their own `.blade.php` files say
"single screen, by requirement"). The student dashboard's own file-header
comment already said "natural-height layout, the page scrolls" — the media
query at the bottom of the file silently contradicted it.

Separately, `.sdash-panels` wrapped two independent per-column grids
(`.sdash-col-left`/`.sdash-col-right`), so a row's two cards never shared a
height — nested grids don't align row tracks with their siblings.

### What changed

- **`public/css/dashboard-student.css`** — rescoped the one-screen media
  query to `.sdash.sdash-cgs`/`.sdash.sdash-admin` only. The student grid
  is natural-height at every size now — the whole page scrolls, not
  individual cards. Flattened `.sdash-panels` into one grid holding all
  four cards directly (removed the `.sdash-col` wrapper), so a row's two
  cards share a grid row track and stretch to equal height automatically.
- **`app/Modules/Core/Resources/views/dashboard/student.blade.php`** —
  removed the `.sdash-col-left`/`.sdash-col-right` wrapper divs; the four
  `@include`s sit directly inside `.sdash-panels` in pairing order
  (application-status, attendance-overview, notifications, upcoming-tasks).
- **`public/css/dashboard-gauge.css`** — updated the animation-delay
  selectors to match the flattened structure (cosmetic stagger only).
- **`app/Modules/Core/Http/Controllers/DashboardController.php`** — capped
  "My Application Status" to 3 items and "Recent Notifications" to 5, each
  keeping its existing "View All" link.

Nothing in `Core/` outside these four files was touched. The CGS and Admin
dashboards' one-screen behavior is unaffected — confirmed by the scoped
selector change and by `tests/Feature/Core/PageShellTest.php` staying
green (that test's panel-scroll-declaration check only globs
`{approver,supervisor,chair}-*.blade.php`, so it never covered the student
dashboard's panels in the first place).

### Verification

- Full `tests/Feature/Core/` suite (108 tests) passed.
- Drove the actual app in headless Chromium, logged in as
  `student@utp.edu.my`, at 1600×1000 (well above the old 1201×700
  breakpoint): confirmed `overflow-y: visible` on all four card bodies with
  `scrollHeight === clientHeight` (nothing clipped), row-paired cards at
  equal heights (290px/290px and 374px/374px), the full gauge/legend/footer
  note visible inside Attendance Overview, correct 3/5 item caps with
  "View All", single-column stacking at 400px width, and dark mode intact.

---

## 2026-10-11 — Multi-file supporting documents: GA Extension & Supervision

**Branch/commit:** `develop`, commit `0456dcf`
("feat: allow multiple supporting documents on GA Extension and
Supervision")

### The ask

Both forms' "Supporting Documents" upload accepted exactly one file.
Requirement: 1–5 files, PDF/JPG/PNG/DOCX only, 5 MB per file / 20 MB
combined, selectable across multiple picks (not just one dialog), a staged
list before submitting (name, size, remove button), clear client-side *and*
server-side validation messages, and the approval side (Supervisor, Chair
of Department, etc.) showing every attached file.

### Key finding before writing any code

`application_documents` was **already a one-to-many table**
(`Application::documents(): HasMany`) — the Core model's own comment says
"Every upload, for every module, lands here." `DocumentStore::attach()`
already inserted one row per call; nothing stopped calling it more than
once per application. **No migration was needed.**

Better still: both `applications/show.blade.php` (the student's own
tracking page) and `partials/queue.blade.php` (every approver's queue,
Supervisor and Chair of Department included) **already** looped
`@foreach ($application->documents as $doc)` and already showed a file-count
badge plus a download link per document. The approval-side requirement was
already satisfied by existing Core code — confirmed live, no changes
needed there.

So the actual work was: the HTML input, the validation rule, and the one
`attach()` call per controller.

### What changed

- **`app/Modules/Core/Services/DocumentStore.php`** (shared Core file —
  flagged to the user before editing, since `DocumentStore` is used by
  every other module's single-file uploads too) — added, purely
  additively:
  - Constants `MULTI_ALLOWED`, `MULTI_MAX_FILES` (5), `MULTI_MAX_FILE_KB`
    (5120), `MULTI_MAX_TOTAL_KB` (20480).
  - `manyRules(string $field, ...)` — returns Laravel validation rules for
    an array file field: required/array/min:1/max:N on the field itself,
    `file`/`mimes`/`max` on `{field}.*`, and a closure checking the
    combined size (Laravel has no built-in rule string for a cross-item
    sum).
  - `attachMany(Application $application, array $files, string $docType)`
    — loops the existing `attach()` per file, reusing the same
    store-then-log path rather than duplicating it.
  - **Nothing existing was changed or renamed** — `ALLOWED`, `MAX_KB`,
    `rules()`, `attach()` are untouched, so every other module's
    single-file upload (Jason's memo, Hani's thesis, Chloe's appeal form,
    Norhanis's various Publication documents) is unaffected. Confirmed by
    the full 262-test suite staying green afterward.
- **`app/Modules/Nureen/Resources/views/components/multi-file-upload.blade.php`**
  (new) — the reusable picker widget, entirely inside Nureen's own folder.
  One real `<input type="file" name="field[]" multiple>`. Inline JS keeps
  its own running array of staged files so a second selection *adds* to
  the first instead of replacing it (the browser replaces a file input's
  FileList on every selection by default — the script rebuilds it via
  `DataTransfer` on every add/remove), renders a list with name + formatted
  size + a ✕ remove button, and shows inline error messages for: too many
  files, a file too large, wrong type, and combined total too large. "No
  file selected" is caught by the input's native `required`. Styling reuses
  existing CSS custom properties (tokens), so dark mode and the existing
  look fall out for free — no Core CSS file was touched.
- **`ga_extension/form.blade.php`** / **`supervision/form.blade.php`** —
  swapped the single `<input type="file">` for
  `<x-nureen::multi-file-upload>`.
- **`GaExtensionController::store()`** / **`SupervisionController::store()`**
  — validate via `DocumentStore::manyRules('supporting_documents')`;
  replaced the single `attach()` call with `attachMany($application,
  $request->file('supporting_documents'), 'Supporting Document')`.
- **`tests/Feature/Nureen/GaExtensionTest.php`** /
  **`SupervisionTest.php`** — updated for the new array field name, and
  added cases for: more than 5 files, a file over 5 MB, combined total over
  20 MB, a disallowed extension, zero files, and several valid files all
  attaching correctly.

### A real bug caught during verification (not by the test suite)

The empty error-message placeholder in the new widget carried the CSS
class `field-error` even while hidden and empty. Core's
`form-stepper.blade.php` — the JS that turns a long form into a wizard —
scans every step for `.is-invalid`/`.field-error` to decide which step to
open first after a failed submission. Because the placeholder always
carried that class, **the wizard always opened on the Documents step**,
even on a completely blank form with no errors at all. This only showed up
when actually driving the form in a browser; the PHPUnit suite never
rendered the page with JavaScript, so it never caught it. Fixed by only
adding the `field-error` class via JS when a real message is being shown.

### Verification

- Full test suite: 262 tests passed, including the new multi-file cases.
- Drove the actual app in headless Chromium as `student@utp.edu.my`:
  selected 3 files at once → added a 4th in a second selection (confirmed
  it added rather than replaced) → attempted a `.txt` file (rejected with
  an inline message, count stayed at 4) → removed one via ✕ → submitted →
  confirmed the Supervisor's queue showed "3 files" with all three as
  working download links.
- Backward compatibility: simulated a pre-existing single-file application
  (the exact shape the old code produced — one `application_documents` row,
  created via the unmodified `attach()` method) and confirmed it still
  displays and downloads correctly through the unmodified display code.
- Confirmed dark mode renders correctly, including the error-message
  coloring.

### What this means going forward

Any other module that later wants multi-file upload can reuse
`DocumentStore::manyRules()`/`attachMany()` directly — they're generic, not
specific to GA Extension/Supervision. The Blade component currently lives
in Nureen's folder (`nureen::components.multi-file-upload`); if a second
module wants it, the natural move is promoting it into `Core/` as a shared
component — a team conversation, not a unilateral change, per the Core
ownership rule.

---

## 2026-10-18 — Attendance: automated ingestion, manual upload kept as fallback

**Branch/commit:** `develop`, commit `e06d5b2`
("feat: automate attendance ingestion, keep manual upload as a fallback")

### The ask

CGS staff were manually downloading an attendance spreadsheet and
uploading it every week. Automate it from whatever source UTP can actually
provide, without losing the ability to upload by hand if a source is down
or wrong — and without the two paths being able to drift apart from each
other.

### The design

Three sources behind one `Support\Contracts\AttendanceSource` interface,
picked by `config('nureen.attendance.source')` (`.env`-overridable, no
code change to switch): an **inbox folder** a scheduled export drops files
into, a **push endpoint** (`POST /attendance/ingest`, authenticated by an
`X-Api-Key` header, throttled, outside the session/CSRF middleware since
nothing calling it has a browser session) that stages records for the next
sync, and a **simulated** source for demoing against seeded students with
no real feed to point at yet.

`Support\AttendanceIngestor` is the single routine both the manual upload
and every automated source funnel through — extracted from the manual
upload's own logic rather than written fresh, so the two paths can never
validate or record a row differently. Idempotent for free, since both
paths already called `AttendanceRecord::recordPeriod()`, which upserts on
`(student_id, period_end)`.

`Support\AttendanceSyncRunner` holds the actual sync logic, called
directly by both `php artisan attendance:sync` and a "Sync Now" button on
the upload page — **not** via `Artisan::call()` from the controller, which
does not work for commands registered only when
`$this->app->runningInConsole()` is true (see "A bug caught" below).

### What changed

New, entirely inside `app/Modules/Nureen/`:
- `Support/Contracts/AttendanceSource.php` — the interface.
- `Support/AttendanceSources/{InboxFolderSource,PushEndpointSource,SimulatedSource}.php`
- `Support/AttendanceIngestor.php`, `Support/AttendanceSyncRunner.php`,
  `Support/AttendanceSourceFactory.php`, `Support/IngestSummary.php`
- `Console/Commands/SyncAttendance.php`
- `Http/Controllers/AttendanceIngestController.php` — the push endpoint.
- `Models/AttendanceSyncRun.php`, `Models/AttendanceIngestQueueEntry.php`
  + their migrations.
- `config/nureen.php` — `attendance.source` / `inbox_path` / `ingest_key`.
- `tests/Feature/Nureen/AttendanceSyncTest.php` (9 tests),
  `AttendanceIngestEndpointTest.php` (5 tests).

Changed:
- `AttendanceController.php` — `upload()` now delegates to
  `AttendanceIngestor`; new `syncNow()` for the button.
- `AttendanceSheet.php` — new `readFile()`, shared by the manual upload
  and `InboxFolderSource` so both parse a spreadsheet identically.
- `ModuleProvider.php` — binds `AttendanceSource`, registers
  `SyncAttendance`, self-schedules `attendance:sync` daily at **02:00**
  via `Schedule::command(...)` called directly from `boot()` — registers
  onto the one scheduler `artisan schedule:run` reads regardless of which
  file calls it, so no edit to any shared `routes/console.php` was needed.
- `routes.php` — `POST /attendance/sync-now` (authenticated, the button);
  `POST /attendance/ingest` (outside the normal auth group, API-key
  checked inside the controller).
- `attendance/upload.blade.php` — a new "Automated Sync" status card
  (active source, last run, read/added/updated/rejected counts) above the
  existing form, relabelled "Manual Fallback."

Flagged in `app/Modules/Nureen/SHARED_CHANGES.md`, not applied: a CSRF
exemption for `/attendance/ingest` in `bootstrap/app.php`; a
`NUREEN_ATTENDANCE_INGEST_KEY=` line needed in `.env.example`.

### A bug caught before it shipped

The first version called `Artisan::call('attendance:sync')` from the "Sync
Now" controller action. It failed silently — Laravel only registers
console commands when `$this->app->runningInConsole()` is true, which a
web request never is. Fixed by extracting the sync logic into
`AttendanceSyncRunner` and having the command and the controller both call
it directly, with no `Artisan` involvement at all.

### Verification

- Full test suite passed, including the 14 new Attendance tests covering
  all three sources, the manual-fallback path staying intact, and the
  push endpoint's API-key authentication and throttling.
- Confirmed the manual upload and an automated inbox-folder sync produce
  identical `attendance_records` rows for the same input file.

---

## 2026-10-24 — GA Extension completeness checks, SLA reminders, analytics, and verifiable certification letters

**Branch/commit:** `develop`, commit `2163a09` (bundles three separate
tasks — see "A note on how this landed" below)

### Task 1 — GA Extension: reduce manual checking by CGS staff

**The ask:** CGS staff were manually cross-checking an applicant's own
details and eyeballing whatever got attached for completeness.

**What changed:**
- The form now opens with a **read-only applicant block** (name, matric
  no., programme, supervisor) pulled straight from the logged-in
  student's profile — nothing re-entered, nothing that can drift from the
  real record.
- **Three separately-required document types** (GA extension form,
  supervisor's supporting letter, acceptance letter *or* published paper)
  instead of one generic upload, each its own
  `<x-nureen::multi-file-upload>` field, validated and labelled
  separately via `DocumentStore::manyRules()`/`manyMessages()` called once
  per type.
- A **"System checks" panel** on the approver's detail view: a live ✓/✗
  per required type, computed from the application's actual attached
  documents against the same config list that enforced it at submission.
- **CGS Staff can send an application back to the supervisor**
  (`GaExtensionController::returnToSupervisor()`, mandatory reason) via
  `WorkflowEngine::returnTo()`, instead of only being able to approve or
  reject — copied from
  `Hani\Http\Controllers\ExaminerNominationController::returnToDepartment()`,
  the existing pattern for this exact need.
- **Config-driven extensibility**: `config('nureen.ga_extension')` now
  holds both the required-document list and an `additional_fields` array
  (key → label/type/required) for whatever the real CGS form turns out to
  need later. Empty today; a new field is one config entry away from
  appearing in the form, its validation, and the approver's panel — no
  migration, no Blade edit. Storage already exists
  (`ga_extension_details.additional_info`, nullable JSON) and sits unused
  until the array is.

Files: `config/nureen.php`, a new migration for `additional_info`,
`Models/GaExtensionDetail.php`, `Http/Controllers/GaExtensionController.php`,
`ga_extension/{form,_detail,queue}.blade.php`, `routes.php` (new
`POST /ga-extension/{application}/return`),
`tests/Feature/Nureen/GaExtensionTest.php` (rewritten, 16 tests).

### Task 2 — Stop applications getting stuck unnoticed (GA Extension, Supervision, Certification)

**The ask:** nothing told anyone when an application had been sitting,
untouched, at one stage for days.

**What changed:**
- **`Support\StalledApplicationReminder`** (new, generic): finds pending
  applications stalled past a per-module threshold (3 days by default,
  `config('nureen.reminders.*')`), resolves recipients as everyone
  holding the current stage's role (department-scoped the same way the
  engine scopes `decide()`), and records that a reminder went out so it
  won't repeat inside the same window. GA Extension's and
  Certification's reminder commands
  (`Console/Commands/RemindStalledGaExtension.php`,
  `RemindStalledCertification.php`) are thin wrappers over this, both
  notifying via a new generic `Notifications\ApplicationStalled`.
- **Supervision kept its own command** rather than switching to the
  generic service, because its `supervisor` stage is addressed to one
  **named** person, not "anyone holding the Supervisor role" — the one
  case the generic resolver can't handle. It gained **escalation**: after
  a configurable number of reminders (2 by default) to the named
  supervisor with still no decision, every Non-Exec CGS account is also
  notified, informationally, via the new `Notifications\SupervisionEscalatedToCgs`.
  The reminder count is tracked per-current-stage
  (`reminder_count`/`reminded_stage_key` on `supervision_details`), so
  moving to the next stage resets it rather than carrying a stale count
  forward.
- **`Support\ProcessingTimeAnalytics`** (new): average days per
  `(module, stage)`, computed in PHP from consecutive `approval_history`
  rows per application rather than a database window function — portable
  across the MySQL dev database and the SQLite in-memory test database.
  Backs a new **CGS-only `/reports/processing-time` page**
  (`Http/Controllers/AnalyticsController.php`): a bar chart plus an
  overdue-applications table, the overdue threshold reusing the exact
  same `stalled_days` config the reminders fire on, so the two numbers
  can never disagree.

Files: `config/nureen.php` (`reminders.*`), two migrations
(`reminder_count`/`reminded_stage_key` on `supervision_details`; new
`application_reminders` table shared by GA Extension/Certification),
`Support/StalledApplicationReminder.php`,
`Notifications/{ApplicationStalled,SupervisionEscalatedToCgs}.php`,
`Console/Commands/{RemindStalledGaExtension,RemindStalledCertification}.php`,
`Console/Commands/RemindStalledSupervisionRequests.php` (escalation added),
`Models/{SupervisionDetail,ApplicationReminder}.php`,
`Support/ProcessingTimeAnalytics.php`,
`Http/Controllers/AnalyticsController.php`,
`analytics/index.blade.php`, both self-scheduled daily at **08:00** from
`ModuleProvider::boot()`, `tests/Feature/Nureen/StalledReminderTest.php`
(7 tests), `AnalyticsTest.php` (4 tests).

**A bug caught only by driving the real page, not by PHPUnit:** the
analytics chart used a horizontal bar (`indexAxis: 'y'`) with two-line
array-style category labels. It rendered correctly on first paint, then
the bars became invisible the moment dark mode toggled — the shared
`retheme()` helper's `Chart.instances[key].update('none')` call interacted
badly with that configuration specifically. PHPUnit never renders
Chart.js at all, so nothing caught it until a live screenshot was taken
after a theme toggle. Fixed by rebuilding it as a plain vertical bar chart
with flat string labels, copying `admin-status-chart.blade.php`'s
already-proven structure (same scale shape, same datalabel anchor)
instead of inventing a new chart shape. Confirmed correct in both themes
afterward via live screenshots.

### Task 3 — Make GA/GRA Certification letters verifiable

**The ask:** letters had no way for anyone outside CGS to check they were
real, and CGS was manually cross-referencing the applicant's appointment
by eye.

**What changed:**
- **Automatic appointment verification** shown to CGS Staff and the
  Senior Director before they decide: Verified / Not Found / Mismatch,
  informational only, never blocking either way
  (`Support/AppointmentVerifier.php`). It reads from a new
  `ga_appointment_records` table that is explicitly a **provisional
  stand-in** — Haziq's GA/GRA module, which should really own this data,
  has no models or migrations at all yet (confirmed empty via `find
  app/Modules/Haziq`; his own module README already flags the overlap
  with Nureen's modules as something the team needs to agree on). Noted
  in `SHARED_CHANGES.md` as a `Core\Contracts\SuppliesAppointmentStatus`
  seam to swap onto his real data once ownership is settled.
- **A unique, sequential reference number** (`CGS/GACERT/<year>/<seq>`,
  via `Support/CertificateReferenceGenerator.php`, race-safe with
  `lockForUpdate()`) assigned the moment the Senior Director's approval
  generates the PDF.
- **A public, no-login verification page** —
  `GET /verify/certificate/{reference}` — showing exactly four facts
  (student name, letter type, issue date, the approver who endorsed it,
  the last read from `approval_history` rather than re-stored) plus a
  Valid/Revoked badge, or a clear "not found" message for an unknown
  reference. Throttled, since it's reachable with no session.
- **A QR code on the PDF** linking to that page, behind a
  `Contracts\GeneratesQrCode` seam. No QR-generating package exists in
  `composer.json` yet, so the default binding
  (`UnavailableQrCodeGenerator`) returns `null` and the PDF template
  falls back to printing the plain URL as text — flagged in
  `SHARED_CHANGES.md` with the exact `require` line needed
  (`endroid/qr-code`) for whenever that's added.
- **Revocation**: Non-Exec CGS or the Senior Director can revoke an
  issued certificate with a mandatory reason. This surfaced a real gap —
  the existing Revoke control would only have been reachable from the
  *pending* queue, but an approved application drops out of that queue
  entirely — so a new **"Issued Certificates" list**
  (`GET /ga-certification/issued`, linked from the queue via a new
  `$tools` slot) was added as the only place Revoke is actually reachable
  once a certificate exists. Revoking never touches
  `applications.status` — the application stays approved; only the
  certificate's own validity changes.

Files: `config/nureen.php` (`ga_certification.reference_prefix`), three
migrations (`reference_number`/`revoked_at`/`revoked_reason` on
`ga_certification_details`; new `ga_appointment_records` table),
`Models/{GaAppointmentRecord,GaCertificationDetail}.php`,
`Support/{AppointmentVerifier,CertificateReferenceGenerator}.php`,
`Support/Contracts/GeneratesQrCode.php`,
`Support/QrCode/UnavailableQrCodeGenerator.php`,
`Http/Controllers/{CertificateVerificationController,CertificationController}.php`
(new `revoke()`, new `issued()`), `certificate/verify.blade.php`,
`ga_certification/{_detail,_certificate_status,_tools,issued,certificate_pdf}.blade.php`,
`Database/Seeders/GaAppointmentRecordSeeder.php` (2 demo records against
real seeded students — one Verified, one Mismatch — run with
`php artisan db:seed --class=...`, following Jason's
`ExaminerPoolSeeder.php` convention rather than being wired into Core's
`DatabaseSeeder`), `routes.php` (`POST
/ga-certification/{application}/revoke`, `GET /ga-certification/issued`,
`GET /verify/certificate/{reference}`),
`tests/Feature/Nureen/CertificateVerificationTest.php` (13 tests).

### Verification (all three tasks)

Full suite passed (314 tests) after all three tasks landed. Live
Playwright verification covered: the public verification page in both
light and dark mode for a valid certificate; an unknown reference showing
"not found"; the Issued Certificates list; revoking a certificate from it
and confirming the public page immediately showed "Revoked" and no
longer showed "Valid."

### A note on how this landed

All three tasks were built and left uncommitted per their own
instructions ("leave all changes uncommitted so I can review them in
Source Control"). Before that review happened, the user committed
externally (outside this session) on top of the Attendance automation
commit, and separately pulled in a teammate's independent commit
(`DocumentStore::manyMessages()`, used identically by both
`GaExtensionController` and `SupervisionController`) via `git pull
--tags origin develop` — also outside this session. The next pull/rebase
replaying the session's local commits onto that new base produced a real
conflict in `GaExtensionController.php` (the session's three-document
validation loop vs. the teammate's `manyMessages()` refactor, both
touching the same lines). This surfaced as PHPUnit intermittently failing
on a literal `<<<<<<<` token, misleadingly attributed by the stack trace
to an unrelated file.

Resolved by keeping the session's three-document-type loop structure but
switching its hand-written validation messages to
`DocumentStore::manyMessages($field)` per field — exactly the call
`SupervisionController.php` already used for the same refactor, confirmed
via `git diff` against both sides before touching anything. Conflict
markers removed, `php -l` clean, GA Extension's 16 tests then the full
314-test suite passed, `git rebase --continue` completed cleanly. Net
effect: because the commit had already happened externally before the
conflict was discovered, all three tasks' work ended up inside the
resulting commit (`2163a09`) rather than staying uncommitted the way each
task's own instructions asked for — worth knowing if you're trying to
find a clean "before this task" point in history.
