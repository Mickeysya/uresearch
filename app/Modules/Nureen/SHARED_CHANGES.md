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
