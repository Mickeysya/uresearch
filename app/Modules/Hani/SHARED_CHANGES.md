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
 *       'internal_main'   => ['name', 'email', 'department', 'faculty'],
 *       'internal_backup' => ['name', 'email', 'department', 'faculty'] | null,
 *       'external_main'   => ['name', 'email', 'institution', 'department'],
 *       'external_backup' => ['name', 'email', 'institution', 'department'] | null,
 *     ],
 *   ]
 *
 * Always the CURRENT panel: after a decline, the promoted backup is in the
 * main seat. An examiner's email may be null (CGS's pool file has none);
 * skip and flag them rather than send.
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
