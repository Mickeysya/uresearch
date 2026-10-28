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
