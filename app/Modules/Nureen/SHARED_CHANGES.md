# Shared-file changes needed

Proposed changes I am not making myself, because the files involved are
outside `app/Modules/Nureen/` and affect everyone. Each entry is the exact
change, the file, and why. Everything works without these — see "Degrades
to" on each — so nothing here blocks using the feature today.

---

## 1. CSRF exemption for the attendance ingest endpoint

**File:** `bootstrap/app.php`

**Change**, inside the existing `->withMiddleware(function (Middleware $middleware) { ... })`:

```php
$middleware->validateCsrfTokens(except: [
    'attendance/ingest',
]);
```

**Why:** `POST /attendance/ingest` (`App\Modules\Nureen\Http\Controllers\AttendanceIngestController`)
is machine-to-machine — a Power Automate flow (or anything else holding the
shared API key) posting attendance records as JSON, authenticated by an
`X-Api-Key` header rather than a browser session. Every module route gets
wrapped in Laravel's `web` middleware group by `App\Providers\ModuleServiceProvider`
(not a file in my folder), which includes CSRF verification for any POST —
and CSRF exemption can only be configured centrally, in `bootstrap/app.php`.

**Degrades to:** a real external POST gets a `419` until this is applied.
This is one of three `AttendanceSource` implementations — `InboxFolderSource`
and `SimulatedSource` work fully without it, so the automated nightly sync
is not blocked on this. The endpoint is also fully covered by
`tests/Feature/Nureen/AttendanceIngestEndpointTest.php`: Laravel's test
client does not enforce CSRF, the same as every other POST test in this
suite, so the route's own logic (API key check, JSON validation, staging) is
verified regardless of whether the exemption has landed.

---

## 2. Document the ingest API key

**File:** `.env.example`

**Change**, add:

```
NUREEN_ATTENDANCE_INGEST_KEY=
```

**Why:** `AttendanceIngestController` reads the expected key from
`config('nureen.attendance.ingest_key')`, which reads `NUREEN_ATTENDANCE_INGEST_KEY`
from the environment (`app/Modules/Nureen/config/nureen.php`, merged via
`Nureen\ModuleProvider`). `.env.example` is the team's shared template of
what a deployment needs — I did not add to it myself, since it is listed as
a shared file.

**Degrades to:** nothing breaks — an unset key means the endpoint refuses
every request (`AttendanceIngestController::store()` treats an empty
configured key as "no request can match it," not "accept anything"). Each
developer still sets their own `NUREEN_ATTENDANCE_INGEST_KEY` in their local
`.env` to exercise the endpoint manually.

---

## 3. A real QR code library for certificate verification

**File:** `composer.json`

**Change**, add to `require`:

```
"endroid/qr-code": "^6.0"
```

**Why:** `GA/GRA Certification Letter` PDFs now carry a unique reference
number and link to a public verification page
(`GET /verify/certificate/{reference}`), and the spec asks for a QR code on
the PDF pointing at it. No QR-generating package exists in `composer.json`
today (checked). `App\Modules\Nureen\Support\Contracts\GeneratesQrCode` is
the seam for this: once the package is added, write an implementation
(e.g. wrapping `Endroid\QrCode\Builder\Builder`) and swap the binding in
`Nureen\ModuleProvider::register()` — nothing else in the module needs to
change, since the PDF template and every caller already go through the
interface.

**Degrades to:** the default binding
(`App\Modules\Nureen\Support\QrCode\UnavailableQrCodeGenerator`) always
returns `null`, and `certificate_pdf.blade.php` already falls back to
printing the plain verification URL as text when that happens. Reference
numbers, the verification page, and revocation are all fully functional
without this — only the QR *image* is missing until the package lands.

---

## Note on `Haziq`'s GA/GRA appointment data

`Support\AppointmentVerifier` checks a certification request against a new,
provisional table, `ga_appointment_records`, seeded with a couple of demo
rows by `Database\Seeders\GaAppointmentRecordSeeder` — not against anything
in `app/Modules/Haziq`, which (checked fresh) still has no models or
migrations at all. Haziq's own `README.md` already flags the overlap
between his GRA/GA scope and this module as something "to agree with the
team first," so I have not proposed a `Core\Contracts\SuppliesAppointmentStatus`
contract here the way `Core\Contracts\SuppliesAttendance` connects Core to
Nureen's Attendance module — that would be premature until ownership is
actually settled. Once it is, the shape to copy is exactly that one: a
Core-owned interface, implemented and bound from whichever module ends up
owning GA/GRA appointment data, with `AppointmentVerifier` swapped to read
through it instead of `ga_appointment_records` directly.

---

## Note on scheduling

No entry needed for `attendance:sync`'s nightly run: it is self-scheduled
from `Nureen\ModuleProvider::boot()` via `Schedule::command('attendance:sync')->dailyAt('02:00')`,
which registers onto the same scheduler `artisan schedule:run` reads
regardless of which file calls it. `routes/console.php` is untouched.
(The existing `supervision:remind-stalled` line already there predates this
approach and I have left it as it is — not part of this change.)

---

## 4. `database/seeders/DemoDataSeeder.php` is now stale for three modules

**File:** `database/seeders/DemoDataSeeder.php` (repo root, shared by all
six people's demo scenarios — not under `app/Modules/Core/`, but I have not
edited it solo for the same reason I would not edit Core).

**What changed under it:** GA Extension, Supervision and Certification were
realigned to the workflows CGS confirmed (see the task write-up in this
session). `DemoDataSeeder::CHAINS` and each module's `$outcomes` arrays
still assume the OLD chains: GA Extension 3 fixed stages, Supervision 2
stages with no Chair of Department, Certification 2 stages with no
Supervisor. The **data these already created is fine** — `current_stage` is
looked up by key, not position, so every existing seeded row stays
decidable exactly where it is (see the "existing applications" research in
this session). The problem is only `php artisan demo:seed` run **again**:
`runChain()` will try to decide a 3rd/4th step that the application's own
chain (in whichever config mode is live) does not have, and
`WorkflowEngine::decide()` throws `LogicException: not awaiting a decision`.

**Suggested change**, inside `DemoDataSeeder`:
- `CHAINS['ga_extension']` → `[Role::SUPERVISOR, Role::NON_EXEC_CGS]` for the
  `'cgs'`-mode default (2 steps), or keep the 3-step list if the seeder is
  changed to set `final_approver_snapshot` explicitly per scenario.
- `CHAINS['supervision']` → `[Role::SUPERVISOR, Role::CHAIR]` for
  `'automatic'` mode, needing a seeded Chair-of-Department decider and new
  required fields (`appointment_role`, `appointment_type`) on each
  `SupervisionDetail::create()` call.
- `CHAINS['ga_certification']` → `[Role::SUPERVISOR, Role::NON_EXEC_CGS, Role::SENIOR_DIRECTOR_CGS]`,
  plus a `supervisor_id` on each `GaCertificationDetail::create()` call and
  the new type-specific fields.

**Degrades to:** `demo:seed` throws on these three modules' sections until
updated; everything else in that seeder (Travel, Publication, Examiner
Nomination, Attendance, etc.) is untouched and unaffected. In the meantime,
`php artisan db:seed --class="App\Modules\Nureen\Database\Seeders\NureenRealignedWorkflowDemoSeeder"`
(new, Nureen-owned, follows the `GaAppointmentRecordSeeder` convention) seeds
a small set of demo applications under the new chains specifically — GA
Extension in `'cgs'` mode, Supervision through the Chair with an automatic
letter for both a Main and a Co-Supervisor, and Certification for both a GA
and a GRA.

---

## Note on `ApplicationDocument::visibleTo()` and CGS-wide document access

**File:** `app/Modules/Core/Models/ApplicationDocument.php` — not edited.

Its `visibleTo()` rule grants access to the student, an admin, whoever
currently holds the application's *current stage* role, or anyone already
in that application's `approval_history`. That has always been enough,
because every module's chain has always had CGS *decide* at some point,
which puts a CGS user in `approval_history`.

Supervision's new `'automatic'` mode breaks that assumption on purpose: CGS
never decides on the application at all (the Chair's approval is final), so
a Non-Executive CGS user would have no way to open the generated appointment
letter through the normal `documents.show` route. Rather than widen Core's
rule to "any CGS-team role, always" — which I am not confident is the right
general rule, and is not mine to decide for every other module's documents —
`SupervisionController::downloadLetter()` gives the "Issued Appointment
Letters" list its own download action, gated on `Role::NON_EXEC_CGS`
directly. Students and the appointed supervisor still use the ordinary
`documents.show` route: the student is always the applicant, and the
supervisor shows up in `approval_history` the moment they endorse, so
Core's existing rule already covers both of them with no change.

If a future module hits the same "CGS needs to see something it never
decided on" shape again, that is probably the signal `visibleTo()` is worth
revisiting as a team decision, not a one-off workaround repeated per module.

---

## 5. A module-supplied status note on the student's application screens (Client Charter)

**Files:** a new `app/Modules/Core/Contracts/SuppliesStatusNote.php`, plus a
few lines in `app/Modules/Core/Resources/views/applications/show.blade.php`,
`app/Modules/Core/Resources/views/dashboard/partials/application-status.blade.php`
and `app/Modules/Core/Resources/views/partials/queue.blade.php`.

**Change.** One optional interface, the same shape as `ProvidesLinks` /
`ProvidesDashboardAlerts`: a module that has something to say about where an
application stands says it, and Core lays it out. A module that implements
nothing costs nothing.

```php
namespace App\Modules\Core\Contracts;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;

interface SuppliesStatusNote
{
    /**
     * A short line about this application for this viewer, or null.
     * `tone` is one of the status tones: info, warn, critical.
     *
     * @return array{tone: string, text: string, detail?: string}|null
     */
    public function statusNote(Application $application, User $viewer): ?array;
}
```

Rendered, escaped with `{{ }}` (never `{!! !!}`):

- `applications/show.blade.php`: under the status badge.
- `dashboard/partials/application-status.blade.php`: as the row's
  `sdash-row-meta` line for a pending application, in place of
  "Submitted on …".
- `partials/queue.blade.php`: next to `queue-row-age` on the collapsed row,
  so an approver sees the due date and the overdue flag without expanding
  the row.

Each call site is three lines:

```blade
@php($note = $application->module() instanceof \App\Modules\Core\Contracts\SuppliesStatusNote
    ? $application->module()->statusNote($application, auth()->user()) : null)
@if ($note) <span class="tone-{{ $note['tone'] }}">{{ $note['text'] }}</span> @endif
```

My three workflows would then return `Support\ClientCharter::expectedDecision()`
as text: "Expected decision by Thu 5 Nov 2026" for the student, "Due Tue 3
Nov" / "Overdue" for an approver.

**Why:** CGS's Client Charter gives every pending GA Extension, Supervision
and Certification application an expected decision date, and the place a
student actually checks is `applications.show` and the dashboard panel,
both Core's, with no slot a module can write into. My `_detail` partials
only render inside the approver queue, so a student never sees them.

**Degrades to:** works today without this. Students have a separate
**Expected Decision Dates** page (`nureen.expected-dates`, linked from the
student sidebar through `ProvidesLinks`). In the approver queues the due
date is at the top of each row's detail panel, and an overdue row is marked
on the collapsed line by a CSS `:has()` rule from inside my own partial
(`charter/_queue_clock.blade.php`). That rule relies on Core's
`.queue-row` / `.queue-row-id` class names: if those are renamed, the marker
silently disappears and the due date in the detail panel still shows.

**Related, smaller:** overdue rows sort first through `queueFor()`'s `$scope`
closure, so it holds in the default "Longest waiting first" order only.
"Most recent first" calls `reorder('submitted_at', 'desc')` after the
module's scope, which is right for what it means, and overdue rows are then
still marked, just not on top. No change proposed.

---

## 6. Named-supervisor stages: two places Core still treats them as role-wide

GA Extension's `supervisor` stage is now addressed to one named supervisor
(`GaExtensionDetail::supervisor_id`, snapshotted at submission), the same as
Supervision's and Certification's. My folder enforces that everywhere it can:
the queue, `decide()` and the reminders. Two Core paths can't see the rule.

**a. `WorkflowEngine::notifyReturn()` emails every Supervisor-role user.**
When CGS sends a GA Extension back to the supervisor
(`GaExtensionController::returnToSupervisor()` → `WorkflowEngine::returnTo()`),
Core notifies `User::where('role', $target->role)`, i.e. every supervisor in
the university, with CGS's remarks about one student's application. Only the
named supervisor can act on it. The smallest fix is an optional module hook,
used by `notifyReturn()` in place of the role query when the module
implements it:

```php
interface NamesStageRecipients
{
    /** @return Collection<int, User>|null  null = everyone holding the stage's role */
    public function recipientsFor(Application $application, Stage $stage): ?Collection;
}
```

**b. `ApproverDashboard::queues()` counts every row on a stage.** A
supervisor's dashboard tile counts all pending rows at the `supervisor` stage
of GA Extension, Supervision and Certification, not only the ones addressed
to them. The queue page itself is correct (it uses `queueFor()`'s `$scope`).
The same hook (or a `count` variant of it) would let the dashboard narrow
the count.

**c. `database/seeders/DemoDataSeeder.php` (around line 612)** creates
`GaExtensionDetail` rows without `supervisor_id`. Add
`'supervisor_id' => $student->supervisor_id,` to that `create([...])`.
Without it, a freshly seeded GA Extension left at the `supervisor` stage
shows in no supervisor's queue. Rows that already exist were backfilled by
`2026_11_01_000100_add_supervisor_id_to_ga_extension_details_table`, and my
own two seeders already set it.

**Degrades to:** neither (a) nor (b) lets anyone see or decide an application that isn't
theirs: the queue page and `decide()` both refuse. (a) over-shares an email;
(b) over-counts a tile; (c) only affects freshly seeded demo data.

**Not needed from Core:** the stuck-application fix (older GA Extension
applications at `senior_director`, older Supervision applications at
`cgs_review`) needed no Core change. Core's contract already says
`stages(null)` is "every stage this module can use"
(`ModuleRegistry::queuesForRole()`, as `TravelWorkflow` does); my two
workflows were returning only the live config's chain instead.

---

## 7. Official letters: three small Core/shared additions

Supervision's appointment letter and the GA/GRA certification letter now
share one formal layout (`Support\LetterRenderer`,
`Resources/views/letters/`). Three things in shared files would make it
cleaner. Everything works today without them.

**a. `DocumentStore::replaceGenerated(ApplicationDocument $document, string $contents)`**
(`app/Modules/Core/Services/DocumentStore.php`). When a letter is revoked,
the stored PDF is re-rendered with a REVOKED watermark so that every download
route, including Core's `documents.show`, serves the watermarked copy. The
document row (and every link and email pointing at it) stays the same.
`DocumentStore` can only create documents, so `LetterRenderer::replaceStored()`
writes the new bytes to the existing path on the `local` disk and updates
`size_bytes`. That is a write outside `DocumentStore`. The proposed method is
those same three lines, moved into Core so the rule "file writes go through
`DocumentStore`" holds without exception.

**b. An `approveLabel` prop on `<x-core::decision-form>`**
(`app/Modules/Core/Resources/views/components/decision-form.blade.php`),
default `'Approve'`. At Supervision's CGS stage, approving *is*
"Endorse & issue letter". Today my partial renders its own endorse button
and hides Core's Approve button in that row only, with a scoped CSS
`:has()` rule (`letters/_preview_styles.blade.php`). With the prop, the
queue partial would pass the label through and that rule would go.

**c. `phpunit.xml`: add `force="true"` to the `<env>` lines** for
`QUEUE_CONNECTION`, `DB_CONNECTION` and `DB_DATABASE`. Inside the app
container, the container's own environment (`QUEUE_CONNECTION=redis`) wins
over `phpunit.xml`'s values unless forced. Test runs therefore push real jobs
onto the development Redis queue, where the worker fails them against a
non-existent SQLite database. On 1 November there were ~930 such
`ApplicationDecided` failures in two hours of test runs. Tests still pass,
but they write to the dev database: one run of `tests/Feature/Nureen` alone
added 37 `failed_jobs` rows (measured, 1 November).

**Stopgap in my folder:** every Nureen test class now uses
`Tests\Feature\Nureen\Concerns\KeepsJobsOffTheDevQueue`, which sets
`queue.default` to `sync`, what `phpunit.xml` intends; mail still goes to
the `array` mailer. Re-measured: 0 new `failed_jobs` rows from a Nureen run.
Other modules' tests still leak until `phpunit.xml` is fixed (a full-suite
run still adds ~120 rows).

---

## 8. PROPOSAL: a PDF text-extraction package (for GA Extension document checks)

**Status:** proposal only. Nothing is installed or implemented. The full plan
is in `app/Modules/Nureen/docs/document-content-check-plan.md`.

**File:** `composer.json` (and the regenerated `composer.lock`).

**Change**, in `require`:

```json
"smalot/pdfparser": "^2.13"
```

**Why:** to let GA Extension's *System checks* panel report whether the
applicant's name and matric number appear in each uploaded PDF (Match / Not
found / Could not read), so CGS staff do not have to open every file to spot
a wrong-student upload. It is informational only, behind a config switch
that defaults to off, and never blocks a decision.

**Why this package:** it is pure PHP, so it needs no system binary and no
Dockerfile or server change. Its requirements (`ext-zlib`, `ext-iconv`, a
mbstring polyfill) are already met. It is actively maintained: v2.13.1 on
13 October 2026, eight releases in 14 months. The alternatives both need a
binary: `spatie/pdf-to-text` (`pdftotext`; its newest release also needs PHP
8.4) and OCR via `tesseract`. See the plan, §2.

**Licence:** LGPL-3.0. Fine for use unmodified via Composer. Flagged anyway
so the team (or supervisor) can confirm there is no licence policy against it.

**Impact on everyone else:** one new dependency in `vendor/`. After pulling,
run `sync.sh` (or `composer install`). No Core code, no shared view and no
migration outside Nureen changes.

**Degrades to:** without it, nothing changes: the System checks panel keeps
showing only "type attached / missing", exactly as today.

---

## 9. Attendance projection: what Core shows students

Nureen's attendance risk check now includes an end-of-semester projection
(`Support\AttendanceProjector`), shown to CGS only. By design, students see
their current attendance and history, never a projection or a trend.
Everything is inside `app/Modules/Nureen/`, except (a), which the team
agreed and is done. (b)–(d) still need a team decision.

**a. DONE (1 Nov 2026, agreed by the team): the "Predicted Attendance"
card is replaced.** It showed students the older mean-change projection.
It is now an "Attendance Action" card, worded by Nureen, and the Active
Applications card shows the nearest Client Charter decision date. Core
files changed, and why:

| File | Change |
|---|---|
| `Core/Contracts/SuppliesAttendance.php` | `projectionFor(): ?float` replaced by `actionFor(): ?array` (label, value, note, pill, pillTone, url), so no projected figure can reach the student dashboard |
| `Core/Contracts/SuppliesDecisionDates.php` (new) | `expectedDecisionDate(Application): ?CarbonInterface`, implemented on a workflow class like `ProvidesLinks` |
| `Core/Services/StudentDashboard.php` | `predictedAttendance()` → `attendanceAction()`; new `nextDecisionDate()` (earliest date over the student's own pending applications) |
| `Core/Http/Controllers/DashboardController.php` | student branch passes `attendanceAction` and `nextDecision` instead of `predicted` |
| `Core/Resources/views/dashboard/partials/stat-cards.blade.php` | the second card renders `attendanceAction`; Active Applications' note adds "· next decision by j M"; a pill may carry a ready-made `url`; a `textValue` card sets its words smaller |

Only the student dashboard includes `stat-cards.blade.php`; the CGS, admin,
supervisor, chair and approver dashboards are untouched (asserted in
`AttendanceActionCardTest`). Nureen deleted `AttendanceProvider::projectionFor()`
and `AttendanceRiskEvaluator::project()`. Tests outside Nureen changed:
`tests/Feature/Core/ModuleContractsTest.php` (`predictedAttendance()` →
`attendanceAction()`).

**Still open, small:** the dashboard's gauge (Core's
`attendance-overview.blade.php`) labels its stat "Target ≥ 85%" (the top of
the "Good" band), while the requirement, the email and the new card say
80%. Suggest relabelling it "Requirement ≥ 80%".

**b. "Current attendance" means two different things.** Core's dashboard
card and gauge show the LATEST PERIOD's percentage
(`AttendanceReading::$percentage`). The 80% requirement, the early-warning
email and Nureen's overview page use attendance SO FAR THIS SEMESTER. A
student can be told 96.3% in the email and see 90% on the dashboard.

**Proposed change:** add `?float $semesterPercentage = null` to
`Core\Support\AttendanceReading`, which Nureen's provider would fill from
`AttendanceRiskEvaluator::assess()`. The dashboard card and gauge would
show it, labelled "This semester so far", with the latest period as the
secondary figure.

**c. Core's CGS dashboard counts only the original rule.** Its attendance
bands and alert count read `attendance_records.at_risk` through
`latestPerStudent()`, which deliberately still means only the original
per-period rule. A student flagged by the projection alone is on Nureen's
At-Risk list but not in those counts.

**Proposed change:** an `atRiskCount(): int` method on `SuppliesAttendance`
(Nureen: `AttendanceRiskState::where('at_risk', true)->count()`), used for
the CGS dashboard's at-risk figure.

**Degrades to:** the CGS dashboard under-counts by the projection-only
students; the At-Risk list itself is complete.

**d. `database/seeders/DemoDataSeeder.php` still seeds MONTHLY attendance**
(`attendance_months`, month-end `period_end`s) and links its attendance
appeals to those rows. The demo semester is now weekly
(`Nureen\Database\Seeders\NureenAttendanceDemoSeeder`: four weeks ending
9–30 Oct 2026, in the semester starting `nureen.attendance.semester_start`).
Re-running `demo:seed` would put monthly rows back beside the weekly ones.
The projection then skips those students ("Projection unavailable:
inconsistent periods") until `NureenAttendanceDemoSeeder` is run again.
**Proposed change:** in DemoDataSeeder, call `NureenAttendanceDemoSeeder`
instead of writing attendance itself, and link its demo appeals to a weekly
row with `sessions_requested` set. Also, its attendance appeals now need the
two required document types (`config('nureen.attendance_appeal.required_documents')`);
appeals it seeds without them show "missing" in CGS's checks.


---

## 10. Track My Applications redesign (branch `feature/track-applications-redesign`, uncommitted)

The student's Track My Applications page (`/applications`) now has tabs by
category, status filters ("Needs you", "In progress", "Approved",
"Rejected") and one card per application, with a stepper built from that
application's own chain. **Core still names no module.** Every module
already appears and works with no change; the lines below are optional
extras.

### For teammates: the ONE line that gives your module its own tab

Add a `CATEGORY` constant to your workflow class (the class you register
in your `ModuleProvider`):

```php
public const CATEGORY = 'thesis';
```

Use one of these keys (the labels live in `config/tracking.php`, so a
rename never touches your module):

| Key | Tab label | Suggested for |
|---|---|---|
| `assistantships` | Assistantships | GA Extension, Certification Letter (Nureen, done) |
| `supervision` | Supervision & Examination | Supervision Request (Nureen, done), Examiner Nomination, Re-viva Monitoring (Hani), Appointment Letter (Jason) |
| `candidacy` | Candidacy & Progress | Attendance Appeal (Nureen, done), Study Candidacy Appeal (Chloe), RPD Extension Appeal, RPD Dismissal (Norhanis) |
| `thesis` | Thesis Submission | Hardbound Submission, Appeal Hardbound Submission (Jason) |
| `travel-funding` | Travel & Funding | Travel, Student Claims, Publication (Norhanis) |

Without the line, your applications show under **Other**. A key not in the
table still gets its own tab (labelled from the key, in a neutral colour).
Optionally, a second line picks the card icon from
`core::dashboard.partials.icon`, e.g. `public const ICON = 'book';`.

### Optional contracts (implement only if you want them; checked with `instanceof`)

- **`Core\Contracts\DescribesApplicationProgress`**: `describeProgress(Application $application): array`.
  Return any of these keys:
  - `sentence`: your own footer line;
  - `actions`: extra buttons, as `[['label' => ..., 'url' => ..., 'primary' => true]]`;
  - `reference`: e.g. an issued letter's real reference, shown beside the
    application reference;
  - `needs_student`: counts under "Needs you"; make your action the primary
    button;
  - `resubmission_expected`: a REJECTED application counts as "Needs you"
    only when this is true.

  Return `[]` for nothing. Without it, Core writes a generic sentence ("Now
  with CGS Staff. No action needed from you.") and offers View details.
- **`Core\Contracts\SuppliesOverdueStatus`**: `decisionIsOverdue(Application $application): bool`
  marks the current step "Taking longer than usual". It was deliberately
  NOT added to `SuppliesDecisionDates`, because adding a method to an
  existing interface would break anyone already implementing it.

### Every Core / shared change

| File | Change |
|---|---|
| `Core/Http/Controllers/ApplicationTrackingController.php` | `index()`: validates `tab`, `status`, `page` and hands off to the service. `show()` unchanged |
| `Core/Services/ApplicationTracker.php` (new) | tabs, counts, filters, cards, 20 per page |
| `Core/Services/ModuleRegistry.php` | `categoryFor($key)` reads a module's optional `CATEGORY` / `ICON` constants plus `config/tracking.php` |
| `Core/Contracts/DescribesApplicationProgress.php` (new) | optional contract, above |
| `Core/Contracts/SuppliesOverdueStatus.php` (new) | optional contract, above |
| `Core/Support/WorkingDayCalendar.php` (new) | the portal's one working-day calculator (Mon to Fri minus `config('calendar.holidays')`), moved from Nureen. Nureen's calendar now extends it, unchanged in behaviour (tested day by day for all of 2026) |
| `config/calendar.php` (new) | `holidays => []`, portal-wide |
| `config/tracking.php` (new) | category key → label, fixed tone, default icon. Tones are fixed per category, not per tab position, so a category has the same colour for every student. No modules are listed |
| `Core/Resources/views/applications/index.blade.php` | rewritten |
| `Core/Resources/views/components/track-card.blade.php` (new) | one card |
| `Core/Resources/views/partials/stylesheets.blade.php` | loads `tracking.css` |
| `public/css/tracking.css` (new) | scoped to `.trk` |
| `public/css/tokens.css` | adds `--purple-fg/-bg` and `--teal-fg/-bg` (light and both dark blocks), text-safe at 4.5:1 or better |

The shared `<x-core::stepper>` (also used by `applications.show` and
Chloe's appeal page) is **unchanged**; the new stepper lives in the card.

**Found, not fixed globally:** in `layout.css`, `.page-header-text { flex: 1 1 24rem }`
becomes a 24rem *height* once `.page-header` stacks into a column below
720px, leaving a ~380px blank gap above the actions on every page that has
header actions. Fixed only on this page (`.trk .page-header-text { flex-basis: auto; }`).
Suggested global fix: the same rule inside the existing `@media (max-width: 720px)` block
in `layout.css`.

### 10b. The application details page ("View details"), same branch

`ApplicationTrackingController::show()` now renders a full details page from
`Core\Services\ApplicationDetails`:
- **Header:** breadcrumb back to the list's own tab and filter; label,
  reference, title; "Pending · with CGS Staff"; the expected-date card, or
  the student's action when they must act.
- **Progress:** who acted, when, and working days against each stage's
  target.
- **"What happens next".**
- **Activity:** newest first.
- **"Your application"** (optional).
- **Documents:** with the issued letter, its reference and verification
  link.
- **"Need help?".**

**Bug fixed in `WorkflowEngine::progress()` (affects every module, in a good way).**
A stage before the current position used to count as completed by its
position alone. Now it is completed only if `approval_history` has a decision
for it; otherwise its state is `'skipped'`, shown as "Not required for this
application". That is the case of a stage added to a chain after an
application had already passed that point. Found on certification #144: it
was submitted before the Supervisor stage existed, and showed Supervisor as
done with "No decisions recorded yet" underneath. The shared
`<x-core::stepper>` (Chloe's appeal page) no longer ticks such a stage either.
No existing test changed.

**For teammates: optional, to fill the "Your application" section and more.**
Implement `Core\Contracts\DescribesApplicationDetails` on your workflow class
with its defaults trait, and override only what you have:

```php
use App\Modules\Core\Contracts\DescribesApplicationDetails;
use App\Modules\Core\Contracts\Defaults\DescribesApplicationDetailsDefaults;

class TravelWorkflow implements WorkflowModule, DescribesApplicationDetails
{
    use DescribesApplicationDetailsDefaults;

    public function details(Application $application): array
    {
        return ['Destination' => '...', 'Dates' => '...'];   // label => value
    }
}
```

The interface's four methods are below. Each one has a do-nothing default in
the trait, so a method added later never breaks you:

| Method | Returns |
|---|---|
| `details()` | label => value |
| `timelineEvents()` | your own events, e.g. reminders, letters; never staff names |
| `issuedDocument()` | the letter you issue, with reference, verify URL and revocation |
| `stageTargetDays()` | each stage's promised working days |

It is a separate interface rather than a `details()` method added to
`DescribesApplicationProgress`, for the same reason as `SuppliesOverdueStatus`.
`DescribesApplicationProgress` also accepts an optional `next` key, which sets
the "What happens next" text.

**Never return internal-only data** from these: everything is shown to the
student.

| File | Change |
|---|---|
| `Core/Services/WorkflowEngine.php` | `progress()`: a stage with no decision is `'skipped'`, never `'completed'`; reads loaded history (no extra query) |
| `Core/Services/ApplicationDetails.php` (new) | the page's data |
| `Core/Services/ApplicationTracker.php` | `summarise()` public (one "needs you" rule for list and page); View details links carry `?tab=&status=`; skipped steps on cards |
| `Core/Contracts/DescribesApplicationDetails.php` (new) + `Contracts/Defaults/DescribesApplicationDetailsDefaults.php` (new) | above |
| `Core/Contracts/DescribesApplicationProgress.php` | documents the optional `next` key |
| `Core/Http/Controllers/ApplicationTrackingController.php` | `show()`: same owner check, validates `tab`/`status`, renders from the service |
| `Core/Resources/views/applications/show.blade.php` | rewritten (still opens with `<x-core::page-header>`) |
| `Core/Resources/views/components/track-card.blade.php` | "not required" wording for skipped steps |
| `config/tracking.php` | `help` contact for "Need help?" (PLACEHOLDERS until CGS confirms) |
| `public/css/tracking.css` | `.trk-detail` styles; muted, dashed "skipped" step |

**Who can open it:** students only, their own applications only, as before.
Approvers never had this page and still don't.

---

## 11. `phpunit.xml`: tests no longer leak jobs onto the real queue (done on `feature/track-applications-redesign`)

**Symptom everyone has seen:** after every `php artisan test`, the dev queue
worker logs about 120 `FAIL` lines (`ApplicationDecided`, `ApplicationReturned`,
Chloe's `CandidacyAppealApproved`, Jason's `HardboundSubmissionRejected`, …),
`storage/logs/laravel.log` fills with "Database file at path [uresearch] does
not exist (Connection: sqlite)", and `failed_jobs` grows. It held over 8,000
rows on 3 Nov 2026.

**Cause:**
- **The container's variable wins.** The Docker app container exports
  `QUEUE_CONNECTION=redis`, and `phpunit.xml`'s `<env>` lines are ignored for
  any variable the environment already has (no `force`).
- **`force` alone wasn't enough.** Even forced, `<env>` only sets `$_ENV` and
  `getenv()`, while PHP also copies the container environment into
  `$_SERVER`, which Laravel reads first. A probe test showed
  `config('queue.default') === 'redis'` inside tests.
- **What then happened:** tests queued their notifications onto the REAL
  Redis queue, and the dev worker tried to run them against the tests'
  in-memory SQLite database.

**Change:** every setting in the `<php>` block is now set twice, both forced:

```xml
<env name="QUEUE_CONNECTION" value="sync" force="true"/>
<server name="QUEUE_CONNECTION" value="sync" force="true"/>
```

(The same for `APP_ENV`, `DB_CONNECTION`, `DB_DATABASE`, `MAIL_MAILER`,
`CACHE_STORE`, `SESSION_DRIVER` and `BCRYPT_ROUNDS`.)

**Verified:** a clean full run (488 tests) sent **0** jobs to the worker,
and `failed_jobs` didn't change.

**Nothing for teammates to do.** Per-test workarounds such as Nureen's
`KeepsJobsOffTheDevQueue` trait still work but are no longer needed. The
8,000+ old rows in `failed_jobs` are leftovers from before this fix. They
are safe to clear once someone has looked at them (`php artisan queue:flush`),
but do not clear them blindly: a genuine failure could be among them.

## 12. Revocation reason: who sees it (Nureen, both letter types)

The CGS revoke forms said the reason was "shown nowhere public, but is kept
for the record". That was already inaccurate for Supervision, where the
revocation email to the student AND the supervisor quotes it. The forms now
show this hint under the reason box (linked with `aria-describedby`), and the
validation message says the same:

> This reason is shown to the student and their supervisor. It is not shown on the public verification page.

**Caveat:** for a GA/GRA **certification**, the reason is shown to the
student (application details page) but nothing currently shows it to the
supervisor. Either the hint drops "and their supervisor" there, or the
certification revocation also emails the supervisor. That decision is open.

## 13. No Electrical & Electronics Chair in the seeded data (for whoever owns `DemoDataSeeder`)

Core's seeders create Chair accounts for **Computing** (`chair@utp.edu.my`) and
**Chemical Engineering** (`meiling.tan@utp.edu.my`) only. Five roster students
are in **Electrical & Electronics Engineering**:
- Tan Wei Jian (22001012)
- Lee Xin Yi (22001015)
- Siti Khadijah Yusof (22001018)
- Ravindran Subramaniam (22001021)
- Ahmad Zaki Hilmi (22001024)

The Chair's queue is department-scoped, so a Supervision request from any of
them stops at the Chair stage with nobody able to act. Tan Wei Jian's #88
will, once endorsed. `docs/test-accounts.md` used to list
`chair-electrical-electronics-engineering@utp.edu.my`, but no seeder creates
it; it came from a hand-made account on someone's machine.

**Proposed:** add one line to `DemoDataSeeder`'s staff list, next to Dr. Mei
Ling Tan:

```php
['<name>', '<email>@utp.edu.my', Role::CHAIR, 'Electrical & Electronics Engineering', 'FOE'],
```

**Until then:** Nureen's CGS **Stuck Reviews** page (`/reviews/stuck`) lists
any Supervision request waiting at a Chair stage whose department has no
Chair.

### 10c. Addition to `DescribesApplicationDetails` (3 Nov 2026)

`waitingFor(Application): ?string` names who a pending application is waiting
for ("Dr. Aisyah Rahman, your supervisor"). The details page shows it in the
status line and on the current step; without it, the page names the role.
Its default in `DescribesApplicationDetailsDefaults` returns null, so no
implementer breaks.

Files: `Core/Contracts/DescribesApplicationDetails.php`,
`Core/Contracts/Defaults/DescribesApplicationDetailsDefaults.php`,
`Core/Services/ApplicationDetails.php` (the `waiting_for` key),
`Core/Resources/views/applications/show.blade.php`, `public/css/tracking.css`.

## 14. Saved drafts (GA Extension, Certification): what Core shows, and two small Core proposals

Numbered 14 so it doesn't collide with §10–§13 on `feature/track-applications-redesign`
when the branches merge.

Nureen's GA Extension and GA/GRA Certification forms now have **Save draft**.
A draft uses Core's existing draft support: an `applications` row with status
`'draft'` and `current_stage` NULL, plus the module's detail row. There is no new
table, no Core edit, and no new column on a Core table (the nullable columns and
`draft_step` are on Nureen's own detail tables). Drafts never reach a queue
(`WorkflowEngine::queue()` reads pending rows only), a reminder, the Client
Charter or Nureen's analytics. Students manage them on Nureen's **My Drafts**
page (`/my-drafts`, a sidebar link).

**What Core shows today (from reading the code on this branch). Not changed:**

a. **Track My Applications lists drafts.** `ApplicationTrackingController::index()`
   loads every application the student has, so a draft appears with a "draft"
   badge and a stepper that marks the first stage as current, as if it were
   with the supervisor. **Proposed:** `->where('status', '!=', Application::STATUS_DRAFT)`
   on that query. The redesign branch's `ApplicationTracker` already excludes
   drafts, so merging that branch fixes this.

b. **The student dashboard's "My Application Status" panel lists drafts as
   "Pending Approval".** `StudentDashboard::applications()` has no status
   filter, and the partial labels anything not approved, rejected or returned
   as pending. **Proposed:** the same exclusion. Alternatively a "Draft · Continue" row,
   but that would need a link each module supplies.

c. **`applications.show` opens a draft** and shows its (empty) progress.
   **Proposed:** 404 for a draft, or a redirect to the module's own Continue link.

d. **`core::partials.form-stepper` cannot open on a given step.** Nureen
   resumes a draft on the step last saved by pressing the stepper's own
   Continue (stopping early if a step still needs something). A one-line
   option would let it open there directly:

   ```js
   show(firstBad > -1 ? firstBad : (parseInt(form.dataset.stepperStart || '0', 10) || 0), false);
   ```

e. **`DocumentStore` has no way to remove a file.** Drafts let a student
   remove an attached file, so `Nureen\Support\Drafts::removeDocuments()`
   deletes the stored file and its `application_documents` row itself. A
   `DocumentStore::remove(ApplicationDocument)` would keep uploads and
   removals in one place.

## 15. Generated GA Extension Form: a module download on Core's application page

The GA Extension Form (UTP/CGS/001-A2) is no longer uploaded: Nureen generates
it from what the student enters (`LetterRenderer::gaExtensionForm()`, route
`ga-extension.form-pdf`). It is rendered fresh on every download, so it is not
an `application_documents` row and doesn't appear in Core's document lists.
No Core edit was made.

**Where it is reachable today:**
- **The student:** the GA Extension page (`/ga-extension/new`) lists their
  submitted applications, each with a download link, and the draft form has
  one too.
- **Approvers:** the link is on the GA Extension queue's detail panel.

**What Core shows today.** `applications.show` (Track My Applications → an
application) lists the uploaded documents only. A student looking at a
submitted GA Extension there won't see the generated form, and has to go back
to the GA Extension page for it.

**Proposed:** an optional contract a module can implement, e.g.

```php
interface ProvidesApplicationDownloads
{
    /** @return array<int, array{label: string, url: string}> */
    public function downloadsFor(Application $application, User $viewer): array;
}
```

Core's application page would list these next to the documents. Nureen
would return the GA Extension Form, and the GA/GRA certification attachment
could use it too. Modules that don't implement it are unaffected.
