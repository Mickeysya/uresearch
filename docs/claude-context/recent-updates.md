# Recent Updates

Changelog of changes made to this repo in recent Claude Code sessions, kept
for syncing a Claude Project's context. Each entry lists the problem, the
decision, what actually changed, and how it was verified, so a future
session (or a teammate) doesn't have to re-derive the reasoning from the
diff alone.

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
