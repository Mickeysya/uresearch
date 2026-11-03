# Proposed changes to shared files

Hani's modules do not edit shared files (`Core/`, `routes/console.php`,
`bootstrap/app.php`, `composer.json`, `.env.example`, shared CSS, sidebar
partials, Core dashboard services, `docs/`, other members' modules). Each
change they would benefit from is written here instead, for the team to
review and apply. Hani's code works without every one of them.

---

## 1. One "Viva" entry in the student sidebar (optional `navLabel()` / `navPattern()`)

**Status:** proposed, not applied.

**Reason.** The viva is one journey over several pages (eligibility
checklist, Notice of Thesis Submission, softbound thesis), but the student
sidebar gives each module exactly one entry, labelled with `label()` and
highlighted only on `createRoute()`. `VivaVoceWorkflow` now declares two
optional methods:

- `navLabel()` returns `'Viva'`, the sidebar text. `label()` stays
  "Notice of Thesis Submission" for emails and the tracking page.
- `navPattern()` returns `'viva.*'`, so the entry stays highlighted on every viva page.

The sidebar reads them only if a module defines them, using `method_exists`.
No contract changes, and every other module renders exactly as before.

**Impact.** Backward compatible. Nobody else's module changes, and no
interface change is needed.

**Without it (current behaviour).** `VivaSoftboundWorkflow::createRoute()`
returns `null`, so the softbound form no longer has its own entry. Students
reach it from the viva home's step tracker. The one remaining entry reads
"Notice of Thesis Submission" and is highlighted on the checklist page only.
`tests/Feature/Hani/VivaEligibilityTest::test_the_student_sidebar_has_one_viva_entry_for_every_viva_page`
passes in both cases.

### File: `app/Modules/Core/Resources/views/partials/sidebar-student-nav.blade.php`

Inside `@foreach ($submittable as $module)`, replace the `<a>` line with:

```blade
{{-- Optional navLabel() / navPattern(): a module whose one entry spans several pages (Hani's viva) names it and its routes; every other module is unchanged. --}}
<a href="{{ route($module->createRoute()) }}" title="{{ method_exists($module, 'navLabel') ? $module->navLabel() : $module->label() }}" class="nav-subitem @if(request()->routeIs(method_exists($module, 'navPattern') ? $module->navPattern() : $module->createRoute())) active @endif">{{ method_exists($module, 'navLabel') ? $module->navLabel() : $module->label() }}</a>
```

### File: `app/Modules/Core/Resources/views/partials/sidebar.blade.php`

In the `$myApplicationOpen` loop, replace

```php
if (request()->routeIs($module->createRoute())) {
```

with

```php
if (request()->routeIs(method_exists($module, 'navPattern') ? $module->navPattern() : $module->createRoute())) {
```

so that "My Application" stays expanded on every viva page.

---

## 2. `examiners:sweep` in `routes/console.php` (optional alternative)

**Status:** not needed. The command already schedules itself from
`Hani\ModuleProvider::boot()` through the scheduler hook
(`callAfterResolving(Schedule::class, …)`), and it appears in
`php artisan schedule:list`. This entry is for the team in case they would
rather keep every schedule visible in one file.

**Reason.** Daily housekeeping: it reminds the Academic Executive when the
viva date has passed but the evaluation has not been marked complete, and it
purges stale pool-import previews. It is idempotent, using a log table with a
unique key, and has `--dry-run`.

**Impact.** None on other modules. If this line is added, **remove the
`callAfterResolving` block from `Hani\ModuleProvider`**. Otherwise the job is
scheduled twice. It would still send only once, because of the log table.

### File: `routes/console.php`

```php
// Hani — examiner housekeeping: evaluation-overdue reminders after the viva,
// stale import previews purged. See App\Modules\Hani\Console\ExaminersSweep.
Schedule::command('examiners:sweep')->dailyAt('07:30')->withoutOverlapping();
```

---

## 3. New Core contract: `SuppliesReleasedExaminers`

**Status:** proposed, not applied.

**Reason.** Today Puan Waheeda (Senior Executive CGS) emails Encik Syahmi
(Non-Executive CGS) an Excel file of the final examiner list, and he retypes
it into the appointment letters. This contract hands Jason's
appointment-letter module the same list in code. It covers released
nominations only, and gives each panel as it stands now, after any decline
and backup promotion.

**Impact.** Additive. It is one new file in `Core/Contracts`. Nothing changes
until someone binds or reads it.
- **Hani:** binds it automatically once the file exists
  (`Hani\ModuleProvider::bindReleasedExaminers()` checks `interface_exists()`
  first), through `Hani\Support\ReleasedExaminerBinding`.
- **Jason:** reads it with `app(SuppliesReleasedExaminers::class)` instead of
  importing anything from Hani's folder.

Until it exists, Jason's module can keep importing the Examiner Report export.
That export's **"Application No."** column is the key to
`examiner_nominations.application_id` (see Hani's README, "Examiner Report
export layout").

### File: `app/Modules/Core/Contracts/SuppliesReleasedExaminers.php` (new)

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
 *       'internal_main'   => ['name', 'email', 'email_problem', 'department', 'faculty'],
 *       'internal_backup' => ['name', 'email', 'email_problem', 'department', 'faculty'] | null,
 *       'external_main'   => ['name', 'email', 'email_problem', 'institution', 'department'],
 *       'external_backup' => ['name', 'email', 'email_problem', 'institution', 'department'] | null,
 *     ],
 *   ]
 *
 * Always the CURRENT panel: after a decline, the promoted backup is in the
 * main seat. An examiner's email may be null: CGS has none yet, or the one
 * on record was reported not working. Then 'email_problem' says which, as
 * a sentence to show; skip and flag them rather than send. (email_problem
 * added 2026-11-03; null when the email is fine.)
 */
interface SuppliesReleasedExaminers
{
    /** @return array<int, array> newest first; only those released at or after $since if given */
    public function releasedPanels(?\DateTimeInterface $since = null): array;

    /** The candidate's current released panel, or null if none has been released. */
    public function panelForStudent(int $studentId): ?array;
}
```

---

## 4. New Core contract: `SuppliesAppointmentDispatch`

**Status:** proposed, not applied.

**Reason.** Acceptance slips come back on paper or by email, so Hani's
panel page is where a decline is recorded. It is far easier to act on when
it shows whether each main examiner's appointment pack has actually gone out.
Jason's module already records that in `appointment_examiners.pack_sent_at`.

**Impact.** Additive.
- **Jason:** implements and binds it in his own `ModuleProvider`.
- **Hani:** reads it only if it is bound
  (`PanelController::lettersSent()`). Until then the "Appointment letter"
  column simply does not appear.

### File: `app/Modules/Core/Contracts/SuppliesAppointmentDispatch.php` (new)

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
    /** Null when nothing has been sent to this examiner for this application. */
    public function packSentAt(int $applicationId, int $examinerId): ?\DateTimeInterface;
}
```

Jason's binding, in `app/Modules/Jason/ModuleProvider.php` `register()`:

```php
$this->app->bind(\App\Modules\Core\Contracts\SuppliesAppointmentDispatch::class, \App\Modules\Jason\Support\AppointmentDispatch::class);
```

---

## 5. Optional `approveLabel` on Core's decision form

**Status:** proposed, not applied.

**Reason.** At the examiner nomination's `cgs_final` stage, the Senior
Executive's one Approve records two decisions, `cgs_final` and then
`cgs_release` ("Finalise and release",
`ExaminerNominationController::finaliseAndRelease()`). Core's decision form
always labels the button "Approve". Until it can be relabelled, Hani's stage
card shows a line beside it: "Approving this stage finalises the list AND
releases it to CGS operations (two steps recorded)."

**Impact.** Backward compatible. The prop defaults to "Approve", so every
other module renders exactly as before.

### File: `app/Modules/Core/Resources/views/components/decision-form.blade.php`

```blade
@props(['application', 'route', 'allowReturn' => false, 'returnAction' => null, 'approveLabel' => 'Approve'])
...
<button type="submit" name="decision" value="approve">{{ $approveLabel }}</button>
```

### File: `app/Modules/Core/Resources/views/partials/queue.blade.php`

Pass it through. This is optional per queue, like `$returnRoute`:

```blade
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

## 6. `TODO.md`: Hani's section is out of date (notes for whoever edits it)

**Status:** for the team. `TODO.md` is shared, so Hani does not edit it.

- **Missing entirely: Viva Voce Assessment** (`viva_voce`, `viva_softbound`).
  It is built, and should be recorded:
  - the eligibility checklist and the Notice of Thesis Submission
  - the softbound thesis (Form #019)
  - the AE acknowledgement, with the supervisor prompted to nominate examiners
  - viva scheduling (date, venue, chairman, co-supervisor, calendar event)
  - the student step trackers, and the single "Viva" sidebar entry (item 1
    above)
- **"Conflict detection has no feature test"**: done.
  `tests/Feature/Hani/ConflictDetectionTest.php` covers Conflict Detection,
  the compiled report's clash set, the form warning, the AE card and the
  compile notification on one two-department fixture.
- **Six-stage chain:** `cgs_release` (same key) now belongs to **Senior
  Executive CGS** and is labelled "Release to CGS operations". At `cgs_final`
  one Approve records both stages. Non-Executive CGS has read-only access to
  the Examiner Report and is notified on release.
- **Examiner pool:** now maintained by **Senior Executive CGS**, not
  Non-Executive. New features:
  - import from CGS's Excel layout or the UResearch template, with a preview
  - an edit page
  - expertise keywords and publications
  - optional email ("Email needed")
  - fuzzy duplicate flags with "Not the same person"
- **Lifecycle:** release ties up the two **main** examiners only. CGS
  confirmed that letters go to mains only. Declines are recorded on the panel
  page:
  - the backup is promoted, and the seat columns are updated
  - the seat history is kept
  - Non-Executive CGS is told who needs a letter

  Pending Evaluation and mark-complete are scoped to the AE's department.
- **New:**
  - explainable examiner suggestions (TF-IDF, plain PHP)
  - the conflict-of-interest pre-check (UTP/CGS/026)
  - the `examiners:sweep` daily command
  - the examiner availability dashboard
  - `HaniDemoSeeder` (on demand)
- **Viva automation (Prompt 2):**
  - one-page eligibility and notice, with abstract and keywords
  - candidacy period checked by the system
  - softbound System checks, with the Turnitin report read by a built-in
    PDF reader
  - examiner progress, with rejection promoting the backup, and the
    divergence alert
  - scheduling clash, weekend and holiday warnings, with suggested slots
  - `viva:reminders` (daily, scheduled from the module)
  - step 5: result and corrections, Viva Results page
  - the Senior Executive can log re-vivas
  - both viva workflows decide one at a time

  The module's "Not built: step 5" line and known limitation 1 (bulk
  actions) can be removed.
- **Examiner email upkeep (Prompt 1 follow-up):**
  - Non-Executive CGS reads the pool and may edit an examiner's email only
  - contact history on a new examiner page
  - "Report email not working", which blocks sends until the email is
    updated
  - "Confirm email still valid"
  - freshness filter and dashboard tiles
  - emails proposed at nomination and confirmed by CGS
  - Non-Exec can open the examiner dashboard
- **Test count:** Hani's own folder is now 173 tests
  (`php artisan test tests/Feature/Hani`).

---

## 7. `phpunit.xml`: force the test queue to `sync`

**Status:** proposed, not applied. Nureen proposed the same fix (her
SHARED_CHANGES.md §7c).

**Reason.** Inside the app container docker-compose sets
`QUEUE_CONNECTION=redis`, and phpunit.xml's `QUEUE_CONNECTION=sync` has no
`force="true"`, so the container's value wins. Every queued notification a
test does not fake lands on the **real** Redis queue. The dev worker then
fails it with `SQLiteDatabaseDoesNotExistException: Database file at path
[uresearch] does not exist`, because the job names a model on the test's
in-memory SQLite connection, and writes a `failed_jobs` row into the dev
database. On 2026-11-03 that had built up to 4,243 failed jobs across six
modules' notifications. Measured after flushing, with Hani's and Nureen's
tests already guarded, one full run of the suite still adds 93:
- 88 Core `ApplicationDecided`
- 2 Chloe `CandidacyAppealApproved`
- 2 Jason `HardboundSubmissionRejected`
- 1 Jason `ExaminerSignatureRequest`

No email is sent (`MAIL_MAILER=array` still applies). The cost is a worker
log full of FAILs that look like real bugs.

**Impact.** Tests only. Every member's tests run queued jobs synchronously
again, as phpunit.xml already intends. Hani's and Nureen's per-folder traits
(`KeepsJobsOffTheDevQueue`) can be deleted afterwards.

### File: `phpunit.xml`

```xml
<env name="QUEUE_CONNECTION" value="sync" force="true"/>
```

---

## 8. New Core contract: `SuppliesCandidature`

**Status:** proposed, not applied.

**Reason.** "Within candidacy period" is now checked by the system before
a student files the Notice of Thesis Submission. The candidature start date
and programme type live in Norhanis's RPD masterlist (`candidacies`). With no
contract, Hani's `Support/Eligibility/CandidacyPeriodSource` reads that table
directly:
- read-only
- guarded with `Schema::hasTable()` / `hasColumns()`
- never importing her classes

If she renames a column, the check quietly falls back to "Self-declared"
instead of breaking. A contract makes the dependency explicit.

**Impact.** Additive.
- **Norhanis:** implements and binds it, from `candidacies`.
- **Hani:** prefers it when bound and keeps the guarded lookup as the
  fallback. No change for anyone else.

### File: `app/Modules/Core/Contracts/SuppliesCandidature.php` (new)

```php
<?php

namespace App\Modules\Core\Contracts;

/**
 * A student's candidature, for modules that need the clock without owning
 * it. Implemented by the module that keeps the RPD masterlist (Norhanis).
 */
interface SuppliesCandidature
{
    /**
     * Null when the student has no candidacy record.
     *
     * @return array{start_date: \DateTimeInterface, programme_type: string, status: string}|null
     *         programme_type: msc_ft | msc_pt | phd_ft | phd_pt
     */
    public function candidatureFor(int $studentId): ?array;
}
```

---

## 9. `smalot/pdfparser` in `composer.json` (optional)

**Status:** optional, not needed. The team has decided not to add it for
now.

**Reason.** The softbound checks read the student's Turnitin report and
thesis PDFs. Hani's module has its own small reader
(`Support/Pdf/BasicPdfText`), which handles:
- Flate-compressed streams and object streams
- standard fonts
- ToUnicode maps

It reads the fictional fixtures reliably. Some real PDFs it cannot read, for
example CID fonts with no ToUnicode map, unusual filters or scanned pages.
For those the panel shows "Not checked automatically", and the student's
declared figure is used. `smalot/pdfparser` handles more of these edge cases.

**Impact.** One dependency, pure PHP, no extension. `Support/Pdf/PdfReader`
detects it with `class_exists()` and uses it automatically. No code change
is needed in any module.

### File: `composer.json`

```json
"require": {
    "smalot/pdfparser": "^2.11"
}
```

---

## 10. Turnitin API keys in `.env.example` (for later)

**Status:** proposed, not needed until UTP licenses API access.

**Reason.** `Support/Similarity/TurnitinApiSource` is a documented stub. It
reads these two keys through `config('hani.similarity.turnitin_api.*')`, and
switching to it is `hani.similarity.source = 'turnitin_api'`.

**Impact.** None. They are empty placeholders, and nothing reads them unless
that source is selected.

### File: `.env.example`

```dotenv
# Hani, viva softbound checks: Turnitin Core API, only with a UTP licence.
TURNITIN_API_KEY=
TURNITIN_API_URL=
```

---

## 11. `viva:reminders` in `routes/console.php` (optional alternative)

**Status:** not needed. Like `examiners:sweep` (item 2), the command
schedules itself from `Hani\ModuleProvider::boot()` through the scheduler
hook. This entry is for the team in case they would rather keep every
schedule visible in one file.

**Reason.** It sends the viva's daily reminders:
- examiners who have not replied to the appointment letter
- examiner reports that are overdue
- the 7-day and 1-day panel reminders
- softbound and corrections deadlines

Each reminder is sent once (log table with a unique key, written before
sending), and the command has `--dry-run`.

**Impact.** None on other modules. If this line is added, **remove the
`viva:reminders` entry from the `callAfterResolving` block in
`Hani\ModuleProvider`**.

### File: `routes/console.php`

```php
// Hani: viva reminders (examiner replies and reports, panel reminders,
// softbound and corrections deadlines). See App\Modules\Hani\Console\VivaReminders.
Schedule::command('viva:reminders')->dailyAt('07:45')->withoutOverlapping();
```

---

## 12. A staff-only flag for `DocumentStore` documents

**Status:** proposed, not applied.

**Reason.** `ApplicationDocument::visibleTo()` shows a student every
document on an application they own, so anything stored through
`DocumentStore` on a student's application is visible to that student.
Examiner reports must never be shown to the candidate. For now, Hani stores
them on the private disk under `hani/examiner-reports/`:
- with random names
- outside `DocumentStore`
- downloaded through an AE- and Senior-Exec-only route
- logged with `activity('document')`, the same way `DocumentStore` logs

Since 2026-11-03 the same applies to the examiner CV a supervisor may attach
when proposing an examiner's email at nomination (`hani/examiner-cvs/`,
CGS-only download): on the nomination it would be visible to the
candidate.

A flag would let both move into `DocumentStore` and the Documents page.

**Impact.** One nullable boolean column on `application_documents`
(defaults to `false`). That is a shared table, so this needs the team's
agreement. A small change to `visibleTo()` / `scopeVisibleTo()`.
Backward compatible: every existing document stays visible exactly as
today.

### Sketch

```php
// migration (Core)
$table->boolean('staff_only')->default(false)->after('doc_type');

// ApplicationDocument::visibleTo()
if ($this->staff_only && $application->student_id === $user->id) {
    return false;
}

// DocumentStore::attach(..., bool $staffOnly = false)
```
