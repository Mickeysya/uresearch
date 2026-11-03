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
