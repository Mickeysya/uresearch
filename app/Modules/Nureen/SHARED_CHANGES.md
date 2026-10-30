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
