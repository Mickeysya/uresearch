# Proposed changes to shared files

Hani's modules (Examiner Matching & Pool, Viva Voce Assessment, Re-viva
Monitoring) do not edit shared files: `Core/`, `routes/console.php`,
`composer.json`, `.env.example`, `phpunit.xml`, shared views and CSS,
`TODO.md`, `docs/`, or other members' modules. Each change the modules would
benefit from is written here instead, for the team to review and apply.

There is **one section per target file**. Each gives the reason, the impact,
whether Hani's code works without it, and the exact snippet. **Every item is
optional.** The modules work today with none of them applied.

| # | Target | What | Works without it? |
|---|---|---|---|
| 1 | `app/Modules/Core/Contracts/` (3 new files) | `SuppliesReleasedExaminers`, `SuppliesAppointmentDispatch`, `SuppliesCandidature` | Yes |
| 2 | Core sidebar partials | one "Viva" entry for students (`navLabel()` / `navPattern()`) | Yes |
| 3 | Core `decision-form` and `queue` partial | an "approve" button label ("Finalise and release") | Yes |
| 4 | `routes/console.php` | Hani's four daily commands | Yes (they schedule themselves) |
| 5 | `composer.json` | `smalot/pdfparser` (optional) | Yes |
| 6 | `.env.example` | Turnitin API keys (for later) | Yes |
| 7 | `phpunit.xml` | `QUEUE_CONNECTION` forced to `sync` | Yes |
| 8 | `DocumentStore` / `application_documents` | a staff-only flag | Yes |
| 9 | `TODO.md` | Hani's section is out of date | Yes |
| 10 | Chloe's `study_candidacies` table | read-only, guarded read | Yes |

---

## 1. `app/Modules/Core/Contracts/`: three new contracts

**Status:** proposed, not applied. Three new files, nothing existing
changes.

### 1a. `SuppliesReleasedExaminers.php` (Hani supplies, Jason reads)

**Reason.** Today the Senior Executive CGS emails the Non-Executive an Excel
file of the final examiner list, and he retypes it into the appointment
letters. This contract hands Jason's appointment-letter module the same list
in code: released nominations only, each panel as it stands now (after any
decline and backup promotion).

**Impact.** Additive.
- **Hani:** binds it automatically once the file exists
  (`ModuleProvider::bindReleasedExaminers()` checks `interface_exists()`),
  through `Support\ReleasedExaminerBinding`.
- **Jason:** reads it with `app(SuppliesReleasedExaminers::class)` instead of
  importing anything from Hani's folder. Until then he can keep importing
  the Examiner Report export; its "Application No." column is the key to
  `examiner_nominations.application_id`. His importer does not yet recognise
  that heading (its aliases lack the full stop); he is adding an
  `application no.` alias on his side.
- **Flagged emails.** A reported address stays in `examiners.email` with
  `email_status = 'invalid'`. The contract already hands over `email = null`
  plus `email_problem`. Anything reading the `examiners` table directly
  (such as Jason's `ExaminerDirectory`) must check `email_status` and not
  send to an address marked invalid.

**Works without it?** Yes. Nothing is bound, and the export is the hand-off.

```php
<?php

namespace App\Modules\Core\Contracts;

/**
 * Released examiner panels, for the appointment letters. Implemented by
 * whichever module owns examiner nomination (Hani); read by whichever sends
 * the letters (Jason). Bound only if the implementing module is installed.
 *
 * A panel:
 *   [
 *     'nomination_id'  => int,
 *     'application_id' => int,                    // the "Application No."
 *     'released_at'    => \DateTimeInterface,
 *     'student'    => ['id', 'name', 'matric_id', 'programme', 'degree', 'department'],
 *     'supervisor' => ['name', 'email'],
 *     'thesis_title'   => string,
 *     'examiners' => [
 *       'internal_main'   => ['id', 'name', 'email', 'email_problem', 'department', 'faculty'],
 *       'internal_backup' => ['id', 'name', 'email', 'email_problem', 'department', 'faculty'] | null,
 *       'external_main'   => ['id', 'name', 'email', 'email_problem', 'institution', 'department'],
 *       'external_backup' => ['id', 'name', 'email', 'email_problem', 'institution', 'department'] | null,
 *     ],
 *   ]
 *
 * Each seat's 'id' is the examiner's examiners.id. Keep it with the letter
 * you send: with 'application_id' it is the key SuppliesAppointmentDispatch
 * is asked with, and it tells a promoted backup apart from an examiner who
 * already has a letter.
 *
 * Always the CURRENT panel: after a decline, the promoted backup is in the
 * main seat. An examiner's email may be null (CGS has none yet, or the one
 * on record was reported not working); 'email_problem' then says which, as
 * a sentence to show. Skip and flag them rather than send.
 */
interface SuppliesReleasedExaminers
{
    /** @return array<int, array> newest first; only those released at or after $since if given */
    public function releasedPanels(?\DateTimeInterface $since = null): array;

    /** The candidate's current released panel, or null if none has been released. */
    public function panelForStudent(int $studentId): ?array;
}
```

### 1b. `SuppliesAppointmentDispatch.php` (Jason supplies, Hani reads)

**Reason.** Declines are recorded on Hani's panel page, and they are far
easier to act on when the page shows whether each main examiner's
appointment pack has gone out. Jason's module records that in
`appointment_examiners.pack_sent_at`.

**The key is Hani's ids, not Jason's.** Hani calls
`packSentAt($nomination->application_id, $nomination->internal_main_id)`:
- `$applicationId` is the **examiner nomination's** `applications.id` (the
  export's "Application No."), not Jason's own application id.
- `$examinerId` is **`examiners.id`** (the seat's `'id'` in 1a).

Jason's rows do not store either id today, so his implementation keeps them
when a panel is imported or read, and returns the **latest non-null
`pack_sent_at`** for that examiner and nomination (there may be a resend, or
a later letter for a promoted backup).

**Impact.** Additive. Jason implements and binds it; Hani reads it only if
bound (`PanelController::lettersSent()`, `Support\ExaminerProgress`).

**Works without it?** Yes. The "Appointment letter" column simply does not
appear, and the AE records letters by hand on the examiner progress page.

```php
<?php

namespace App\Modules\Core\Contracts;

/**
 * When an examiner's appointment pack was sent, for the module that owns the
 * panel (Hani) to show next to each seat. Implemented by the module that
 * sends the letters (Jason: appointment_examiners.pack_sent_at).
 */
interface SuppliesAppointmentDispatch
{
    /**
     * Keyed by the NOMINATION's applications.id and the examiner's
     * examiners.id. The latest non-null pack_sent_at for that pair; null
     * when nothing has been sent.
     */
    public function packSentAt(int $applicationId, int $examinerId): ?\DateTimeInterface;
}
```

Jason's binding, in `app/Modules/Jason/ModuleProvider.php` `register()`:

```php
$this->app->bind(\App\Modules\Core\Contracts\SuppliesAppointmentDispatch::class, \App\Modules\Jason\Support\AppointmentDispatch::class);
```

### 1c. `SuppliesCandidature.php` (Chloe supplies, Hani reads)

**Reason.** "Within candidacy period" is checked by the system before a
student files the Notice of Thesis Submission. The candidacy expiry date
lives in Chloe's Study Candidacy record. Without a contract Hani reads her
table directly (section 10), which works but leaves the dependency
implicit.

**Impact.** Additive. Chloe implements and binds it from
`study_candidacies`; Hani prefers it when bound and keeps the guarded read
as the fallback.

**Works without it?** Yes, through the guarded read in section 10.

```php
<?php

namespace App\Modules\Core\Contracts;

/**
 * A student's candidature, for modules that need the clock without owning
 * it. Implemented by the module that keeps Study Candidacy (Chloe).
 */
interface SuppliesCandidature
{
    /**
     * Null when the student has no candidacy record.
     *
     * @return array{start_date: \DateTimeInterface, expiry_date: \DateTimeInterface, programme_type: string, status: string}|null
     *         programme_type: msc_ft | msc_pt | phd_ft | phd_pt
     */
    public function candidatureFor(int $studentId): ?array;
}
```

---

## 2. Core sidebar partials: one "Viva" entry for students

**Status:** proposed, not applied.

**Targets:**
- `app/Modules/Core/Resources/views/partials/sidebar-student-nav.blade.php`
- `app/Modules/Core/Resources/views/partials/sidebar.blade.php`

**Reason.** The viva is one journey over several pages (eligibility and
notice, softbound thesis, result and corrections), but the student sidebar
gives each module one entry, labelled with `label()` and highlighted only on
`createRoute()`. `VivaVoceWorkflow` declares two optional methods:
`navLabel()` returns `'Viva'`, and `navPattern()` returns `'viva.*'`. The
sidebar would read them only if a module defines them (`method_exists`).

**Impact.** Backward compatible. No interface change; every other module
renders exactly as before.

**Works without it?** Yes. The entry reads "Notice of Thesis Submission" and
is highlighted on the eligibility page only. Students reach the softbound and
result pages from the viva home's step tracker.
`VivaEligibilityTest::test_the_student_sidebar_has_one_viva_entry_for_every_viva_page`
passes either way.

In `sidebar-student-nav.blade.php`, inside `@foreach ($submittable as $module)`,
replace the `<a>` line with:

```blade
{{-- Optional navLabel() / navPattern(): a module whose one entry spans several pages (Hani's viva) names it and its routes; every other module is unchanged. --}}
<a href="{{ route($module->createRoute()) }}" title="{{ method_exists($module, 'navLabel') ? $module->navLabel() : $module->label() }}" class="nav-subitem @if(request()->routeIs(method_exists($module, 'navPattern') ? $module->navPattern() : $module->createRoute())) active @endif">{{ method_exists($module, 'navLabel') ? $module->navLabel() : $module->label() }}</a>
```

In `sidebar.blade.php`, in the `$myApplicationOpen` loop, replace

```php
if (request()->routeIs($module->createRoute())) {
```

with

```php
if (request()->routeIs(method_exists($module, 'navPattern') ? $module->navPattern() : $module->createRoute())) {
```

---

## 3. Core `decision-form` and `queue` partial: an approve label

**Status:** proposed, not applied.

**Targets:**
- `app/Modules/Core/Resources/views/components/decision-form.blade.php`
- `app/Modules/Core/Resources/views/partials/queue.blade.php`

**Reason.** At the examiner nomination's `cgs_final` stage, the Senior
Executive's one Approve records two decisions, `cgs_final` and `cgs_release`
(`ExaminerNominationController::finaliseAndRelease()`). Core's form always
says "Approve".

**Impact.** Backward compatible: the prop defaults to "Approve".

**Works without it?** Yes. Hani's stage card shows a line beside the
button: "Approving this stage finalises the list AND releases it to CGS
operations (two steps recorded)."

```blade
{{-- decision-form.blade.php --}}
@props(['application', 'route', 'allowReturn' => false, 'returnAction' => null, 'approveLabel' => 'Approve'])
...
<button type="submit" name="decision" value="approve">{{ $approveLabel }}</button>
```

```blade
{{-- queue.blade.php: optional per queue, like $returnRoute --}}
<x-core::decision-form
    :application="$application"
    :route="route($decideRoute, $application)"
    :allow-return="$allowReturn ?? false"
    :approve-label="$approveLabel ?? 'Approve'"
    :return-action="..." />
```

Hani's `examiner_nomination/queue.blade.php` would then pass
`'approveLabel' => 'Finalise and release'` at `cgs_final`.

---

## 4. `routes/console.php`: Hani's four daily commands

**Status:** not needed. All four commands already schedule themselves from
`Hani\ModuleProvider::boot()` through the scheduler hook
(`callAfterResolving(Schedule::class, ...)`), and they appear in
`php artisan schedule:list`. This block is for the team in case every
schedule should be visible in one file.

**Reason.**

| Command | Time | What it does |
|---|---|---|
| `examiners:sweep` | 07:30 | reminds the AE to mark an evaluation complete once the viva date has passed; purges stale pool-import previews |
| `viva:reminders` | 07:45 | examiner reply and report chasers, 7-day and 1-day panel reminders with the calendar file, softbound and corrections deadlines, category-4 window closing |
| `re-viva:monitor` | 08:00 | panel working-day limits (20 → AE, 25 → Senior Exec), resubmission window and final-thesis reminders, overdue flags, level-4 window closing |
| `hani:sla-reminders` | 08:15 | reminds the current approver when an application has waited past its stage limit (`hani.sla`); second threshold copies the Senior Exec |

Each sends once (a log table with a unique key, written before sending) and
has `--dry-run`.

**Impact.** None on other modules. **If this block is added, remove the
`callAfterResolving` block from `Hani\ModuleProvider::boot()`**, or each job
is scheduled twice. (It would still send only once, because of the logs.)

**Works without it?** Yes.

```php
// Hani: examiner, viva and re-viva housekeeping. See App\Modules\Hani\Console.
// If these are added here, remove the callAfterResolving block in
// App\Modules\Hani\ModuleProvider::boot().
Schedule::command('examiners:sweep')->dailyAt('07:30')->withoutOverlapping();
Schedule::command('viva:reminders')->dailyAt('07:45')->withoutOverlapping();
Schedule::command('re-viva:monitor')->dailyAt('08:00')->withoutOverlapping();
Schedule::command('hani:sla-reminders')->dailyAt('08:15')->withoutOverlapping();
```

---

## 5. `composer.json`: `smalot/pdfparser` (optional)

**Status:** optional, not needed. The team decided not to add it for now.

**Reason.** The softbound checks and the digital Summary of Corrections read
PDFs. Hani's own reader (`Support/Pdf/BasicPdfText`) handles Flate and
object streams, standard fonts and ToUnicode maps, and reads the fictional
fixtures reliably. Some real PDFs it cannot read (CID fonts with no
ToUnicode map, unusual filters, scanned pages); those show "Not checked
automatically" and the student's declared figure is used. smalot handles
more of these.

**Impact.** One pure-PHP dependency, no extension. `Support/Pdf/PdfReader`
detects it with `class_exists()`; no code change anywhere.

**Works without it?** Yes.

`maatwebsite/excel` (used by the pool import, the Examiner Report export and
the Process Analytics export) is already in `composer.json`; nothing to add.

```json
"require": {
    "smalot/pdfparser": "^2.11"
}
```

---

## 6. `.env.example`: Turnitin API keys (for later)

**Status:** proposed, not needed until UTP licenses Turnitin API access.

**Reason.** `Support/Similarity/TurnitinApiSource` is a documented stub. It
reads these keys through `config('hani.similarity.turnitin_api.*')`;
switching to it is `hani.similarity.source = 'turnitin_api'`.

**Impact.** None. Empty placeholders that nothing reads unless that source is
selected.

**Works without it?** Yes. The default source reads the student's uploaded
Turnitin report.

```dotenv
# Hani, viva softbound checks: Turnitin Core API, only with a UTP licence.
TURNITIN_API_KEY=
TURNITIN_API_URL=
```

---

## 7. `phpunit.xml`: force the test queue to `sync`

**Status:** proposed, not applied. Nureen proposed the same fix (her
SHARED_CHANGES.md §7c).

**Reason.** Inside the app container docker-compose sets
`QUEUE_CONNECTION=redis`, and phpunit.xml's `QUEUE_CONNECTION=sync` has no
`force="true"`, so the container's value wins. Every queued notification a
test does not fake lands on the real Redis queue. The dev worker then fails
it (the job names a model on the test's in-memory SQLite connection) and
writes a `failed_jobs` row into the dev database. On 2026-11-03 that had
built up to 4,243 failed jobs across six modules. No email is sent
(`MAIL_MAILER=array` still applies); the cost is a worker log full of FAILs
that look like real bugs.

**Impact.** Tests only. Every member's tests run queued jobs synchronously,
as phpunit.xml already intends. Hani's and Nureen's per-folder traits
(`KeepsJobsOffTheDevQueue`) can be deleted afterwards.

**Works without it?** Yes. Hani's tests use `KeepsJobsOffTheDevQueue`, which
sets the queue to `sync` for its own tests.

```xml
<env name="QUEUE_CONNECTION" value="sync" force="true"/>
```

---

## 8. `DocumentStore` / `application_documents`: a staff-only flag

**Status:** proposed, not applied. Touches a shared table, so it needs the
team's agreement.

**Targets:** a new Core migration on `application_documents`;
`ApplicationDocument::visibleTo()` and `scopeVisibleTo()`;
`DocumentStore::attach()` / `storeGenerated()`.

**Reason.** `ApplicationDocument::visibleTo()` shows a student every
document on an application they own, so anything stored through
`DocumentStore` on a student's application is visible to that student
(including in the new in-app document viewer and Documents page). Files that
name or come from the examiners must never reach the candidate. For now Hani
keeps them on the private disk, outside `DocumentStore`, with random names,
a role-checked download route, and `activity('document')` logging:

| Files | Where | Who can download |
|---|---|---|
| Viva examiner reports | `hani/examiner-reports/` | AE (own department), Senior Exec |
| Examiner CVs proposed at nomination | `hani/examiner-cvs/` | CGS |
| Re-viva correction letters | `hani/reviva-letters/` | AE (own department), Senior Exec, Non-Exec |
| Signed UTP/CGS/024 re-evaluations | `hani/reviva-reports/` | as above |
| Confirmation of Correction | `hani/reviva-confirmations/` | as above |

A flag would let all of them move into `DocumentStore`, the viewer and the
Documents page.

**Impact.** One nullable boolean column, default `false`; every existing
document stays visible exactly as today.

**Works without it?** Yes, as described above.

```php
// migration (Core)
$table->boolean('staff_only')->default(false)->after('doc_type');

// ApplicationDocument::visibleTo()
if ($this->staff_only && $application->student_id === $user->id) {
    return false;
}

// DocumentStore::attach(..., bool $staffOnly = false)
```

---

## 9. `TODO.md`: Hani's section is out of date

**Status:** notes for whoever edits `TODO.md`. Hani does not edit it.

**Works without it?** Yes; this is documentation only.

Hani's section should record, in place of what it says now:

- **Examiner Matching & Pool (Prompt 1, 2026-11-03).**
  - Six-stage chain; `cgs_release` (same key) belongs to the **Senior
    Executive CGS**, labelled "Release to CGS operations"; one Approve at
    `cgs_final` records both stages. Non-Executive CGS is read-only and
    notified on release.
  - Pool maintained by the Senior Executive: import from CGS's Excel layout
    or the UResearch template with a preview; edit page; expertise keywords
    and publications; optional email.
  - Explainable examiner suggestions (TF-IDF in plain PHP); fuzzy duplicate
    flags with "Not the same person"; conflict-of-interest pre-check
    (UTP/CGS/026); cross-department clashes on submit, cards and compile.
  - Declines recorded on the panel page; backup promoted; seat history kept;
    release ties up the two **main** examiners only.
  - Examiner availability dashboard; `examiners:sweep`; `HaniDemoSeeder`.
  - Examiner email upkeep (follow-up): Non-Exec edits emails only, contact
    history, "Report email not working" blocks sends, freshness, emails
    proposed at nomination.
- **Viva Voce Assessment (Prompt 2, 2026-11-03).** Missing from `TODO.md`
  entirely (`viva_voce`, `viva_softbound`):
  - one-page eligibility and notice with abstract and keywords; candidacy
    checked by the system
  - Form #019 softbound with System checks (Turnitin report read by a
    built-in PDF reader)
  - examiner progress with divergence alerts; scheduling with clash,
    weekend and holiday warnings; `viva:reminders`
  - step 5: Viva Results, corrections upload and acceptance
  - both viva workflows decide one at a time (bulk safety). The module's
    "Not built: step 5" line and "bulk actions" limitation can go.
- **Re-viva Monitoring (Prompt 3, 2026-11-04).** Logged by the Senior
  Executive with the viva hand-off and window; "Send to panel" letters with
  signed links, resend; working-day panel alerts; UTP/CGS/024
  re-evaluations with divergence; suggested outcome; final thesis and
  Confirmation of Correction; `re-viva:monitor`; Re-viva Cycles with a risk
  score; digital Summary of Corrections. Non-Executive CGS read-only.
- **Across the modules (Prompt 4, 2026-11-04).** `hani:sla-reminders`
  (per-stage limits, provisional); Process Analytics (stage times from
  `approval_history`, slowest stage, overdue now, viva pipeline, re-viva
  outcomes, report turnaround, pool utilisation; xlsx/csv export); the demo
  seeder's 24 fictional historical cases.
- **Cross-cutting:** "no scheduled commands yet" is out of date for Hani:
  four commands schedule themselves (section 4).
- **"Conflict detection has no feature test":** done
  (`tests/Feature/Hani/ConflictDetectionTest.php`).
- **Test count:** Hani's folder has 250 tests
  (`php artisan test tests/Feature/Hani`).

---

## 10. Chloe's `study_candidacies` table: read-only, guarded

**Status:** in use; no change to Chloe's module needed. Recorded here because
it is a read across module boundaries.

**Target:** `study_candidacies` (Chloe's Study Candidacy), read by
`Hani\Support\Eligibility\CandidacyPeriodSource`.

**Reason.** The eligibility checklist verifies "Within candidacy period"
from `study_candidacies.candidacy_expiry_date` (which includes extensions)
and `status`: inside the expiry date is Verified by system; past it, or
dismissed, is Not met. It used to read Norhanis's `candidacies` table, which
develop removed with RPD Candidacy (2026-10-30).

**How it reads.**
- Read-only, never writes.
- Guarded with `Schema::hasTable()` / `hasColumns()`.
- Never imports Chloe's classes; plain query builder on the table name.

**Impact.** None on Chloe's module. If the table or either column is renamed
or removed, the check quietly falls back to "Self-declared" instead of
failing. That is what happened when `candidacies` was dropped: nothing
broke, but the check went quiet until it was repointed. Contract 1c would
make the dependency explicit.

**Works without it?** Yes. With no Study Candidacy record the item is
self-declared, as the other checklist items are.
