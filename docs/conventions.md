# Conventions

## Ownership

| Path | Owner | May edit |
|---|---|---|
| `app/Modules/Core/**` | the team | by agreement — it affects all six of us |
| `app/Modules/<Name>/**` | that person | only them |
| `database/migrations/**` | the team | framework tables only; put yours in your module |
| `public/css/uresearch.css` | Norhanis | do not edit — her original sheet |
| `public/css/tokens.css` | the team | the design system — add a token, never a literal |
| `public/css/layout.css` | the team | the shared shell: cards, forms, buttons, tables |
| `public/css/dashboard-*.css` | the team | one dashboard screen each |
| `public/css/charts.css` | the team | put new shared chart styling here |
| `routes/web.php` | nobody | it only redirects `/` — your routes go in your folder |

If you need something from Core, ask. A five-minute conversation beats a
merge conflict across six branches.

## Naming

| Thing | Convention | Example |
|---|---|---|
| Module folder | `PascalCase`, the person's name | `Norhanis` |
| View namespace | folder name lowercased | `norhanis::travel.form` |
| `module_type` | `snake_case`, unique, permanent | `ga_extension` |
| `Stage::key` | `snake_case`, unique within a chain | `cgs_review` |
| `Stage::label` | as CGS says it | `Non-Executive CGS` |
| Route names | `<module>.<action>` | `travel.queue` |
| Detail table | `<module>_details` | `travel_details` |
| Detail partial | leading underscore | `_detail.blade.php` |
| Test file | `tests/Feature/<You>/<Feature>Test.php` | `tests/Feature/Nureen/AttendanceTest.php` |

`module_type` and `Stage::key` end up in database rows. Renaming one later
means writing a migration to rewrite existing data. Labels are display-only
and safe to reword.

## Rules

**1. Never write `status` or `current_stage` yourself.**
`WorkflowEngine::submit()` and `::decide()`. This is the single most important
rule — it is what stops the modules drifting apart again.

**2. `stages(null)` must return every stage you can ever use.**
The registry calls it that way to build the sidebar and gate the approval
queues. If a conditional branch adds stages, the null case must include them,
or the roles that own those stages get no queue at all.

**3. Never add columns to Core's tables.**
`users`, `applications`, `approval_history`, `application_documents`. Your
module gets its own table keyed by `application_id`.

**4. Every query is scoped.**
A student sees only their own applications. An approver sees only the stage
they own. `queueFor()` and the `role:` middleware do this for you — do not
write a bare `Application::all()`.

**5. Escape everything.**
Blade's `{{ }}` escapes; `{!! !!}` does not. Only ever use `{!! !!}` on markup
you generated yourself, never on anything a user typed.

**6. Validate every input.**
`$request->validate()` at the top of every `store()`. Never read `$_POST`.

**7. Derive, don't trust.**
If a value can be computed from other inputs, compute it server-side. Travel
duration is derived from the dates rather than accepted from the form.

**8. Uploads go through `DocumentStore`.**
Never `move_uploaded_file()`, never write under `public/`.

**9. No secrets in code.**
Credentials belong in `.env`, which is git-ignored. Read them with `config()`.

## Tests

`php artisan test` — or `./vendor/bin/sail artisan test` if your host has no
`pdo_sqlite`. SQLite in memory, so it needs no Docker and can never touch your
own database.

```
tests/
  Support/        helpers shared across files — MakesUsers is the cast
  Feature/
    Core/         the engine, the seams, CSP, the profile — the team's
    Norhanis/     ClaimsTest.php, PublicationTest.php
    Nureen/       AttendanceTest.php, SupervisionTest.php, CertificationTest.php
    Hani/         ExaminerNominationTest.php, ReVivaTest.php
    Jason/ Chloe/ Haziq/     — when you write your first one
  Unit/           pure functions, no database
```

**One folder per person, one file per feature, mirroring `app/Modules/`.**
A new module is a new file in your own folder, so nobody edits a file someone
else is also editing. Namespace follows the path: `Tests\Feature\Nureen`.

`use Tests\Support\MakesUsers` for `student()`, `supervisor()`, `cgs()`,
`academicExec()`, `seniorDirector()`, `chair()` — names and emails match
`DatabaseSeeder`, so a failure names the same person you would see logging in.
Need a role that isn't there? Add it to the trait. Need a user with extra
fields? `$this->student(attributes: ['programme' => 'MSc Full-Time'])`, or
override `student()` in your own class and call `$this->user()`.

Fixtures only your own module needs — a CSV builder, an examiner row — stay a
`protected` method on your test class. They only move to `Tests\Support` once
a second person actually needs them.

**Test what breaks quietly**, not every method: authorisation, anything derived
server-side from what a form posted, and anything read before `validate()`.
A test that would have passed before your fix is not a test of your fix —
re-introduce the bug once and watch it fail.

## Design

`public/css/tokens.css` is the system. **Never write a colour, a font size, a
radius or a spacing literal in any other sheet** — add a token here and use it.
Before it existed there were 67 distinct hex colours, 47 font sizes and 16
border radii across thirteen sheets, and `#D0342C` alone was written out 27
times.

```css
/* no  */  color: #D0342C;  padding: 13px;  font-size: 12.5px;
/* yes */  color: var(--danger-fg);  padding: var(--space-3);  font-size: var(--text-sm);
```

**The load order is load-bearing.** `uresearch.css` declares eleven `:root`
variables; `tokens.css` redefines them, so it must load immediately after, and
everything else after that. `partials/stylesheets.blade.php` has the list and
the reason.

| Group | Use |
|---|---|
| `--navy` `--gold` | UTP brand. Fixed. |
| `--navy-50..900` `--gold-50..800` `--grey-0..900` | the ramps everything derives from |
| `--surface` `--surface-raised` `--surface-sunken` `--page-bg` | the four depths — that is the whole vocabulary |
| `--text-dark` `--text-body` `--text-grey` `--text-on-accent` | four text weights |
| `--border-subtle` `--border-grey` `--border-strong` | row divider / control outline / focused |
| `--success-*` `--warning-*` `--danger-*` `--info-*` | status, each with `-fg` `-bg` `-border` |
| `--tone-green` … `--tone-red` | category colour on a stat-card icon — **not** status |
| `--text-2xs..3xl` | one type scale, 1.125 from a 14px base |
| `--space-1..12` | a 4px grid |
| `--radius-sm/md/full` `--shadow-sm/md/lg` `--duration-*` `--ease` | shape, lift, motion |

**Both themes, always.** Every colour token is declared twice — once on
`:root`, once under `:root[data-theme="dark"]` *and* the
`prefers-color-scheme` media block (the `:not([data-theme="light"])` guard is
what lets someone on a dark OS still choose light). Use a token and you get
dark mode free; write a literal and you have made a white box on a dark page.
Check both before you push: the toggle is in the top bar.

Two tokens exist because one would not do the job: `--navy` is the *text and
border* accent, `--accent-solid` is the *fill* behind `--text-on-accent`. In
dark mode they are different colours. Filling with `--navy` puts white text on
pale blue.

Status colours are paired — `--danger-fg` is contrast-checked against
`--danger-bg` at WCAG AA, so use them together and the result is legible in
both themes. Mixing a `-fg` with an arbitrary background is not covered.

### Icons and controls

**Every UI icon is `stroke="currentColor"`** — `dashboard/partials/icon.blade.php`
and the three sidebar navs. That is what makes them theme for free: set a
colour on the container and the icon follows. Never put a `fill` or `stroke`
hex on an interface icon. The only exceptions are the two banner
illustrations, which are artwork on a navy banner that is navy in both themes.

`select` drops the native arrow and draws its own chevron, so a row of
selects lines up with the text inputs above it. The chevron is a
`background-image` data: URI, which **cannot read a custom property** — a
select can carry no pseudo-element to mask one onto — so its colour is baked
in and there is a light rule and a dark rule. Those hex values are
`--text-grey` in each theme; change one in `tokens.css` and change them here.

The `option` list is drawn by the operating system and cannot be styled at
all. It follows the theme because `tokens.css` declares `color-scheme` on
`:root` — that is what that property is for.

**Date fields get a panel** from `core::partials.date-picker`, included once
at the layout level, so you get it by writing `<input type="date">` and
nothing else. It **enhances** the native input rather than replacing it: the
input keeps the value, posts the form, and accepts typing exactly as before,
and the panel is skipped entirely on touch devices where the OS wheel is
better. Add `data-no-picker` to opt a field out.

**Set `min` / `max` on your date inputs.** They do double duty: they bound the
native validation the wizard's step gate reads, *and* they set the year range
the picker's dropdown offers. A field with neither gets ten years either side,
which works but is looser than it needs to be. `max="{{ now()->toDateString() }}"`
for a date that must be in the past, `min` for one that must be ahead.

### Forms

A form past about eight fields should be a wizard rather than one long
scroll. Add `data-stepper` to the `<form>`, wrap each section in a
`<fieldset class="fstep" data-label="...">`, and include the partial:

```blade
<form method="POST" action="..." enctype="multipart/form-data" data-stepper>
    @csrf
    <fieldset class="fstep" data-label="Trip details">
        <p class="fstep-hint">One line saying what this step is for.</p>
        ...fields...
    </fieldset>
    <fieldset class="fstep" data-label="Documents">...fields...</fieldset>
    <button type="submit">Submit Application</button>
</form>

@include('core::partials.form-stepper')
```

That is the whole API. The partial re-casts the `.card` it finds the form in:
your `<h2>` moves into a band at the top with the step counter beside it, the
rail becomes a left-hand column, and the fields get the rest (below 860px the
rail goes back on top, horizontal). The progress rail,
Back/Continue, and a **generated review step** are all built for you, and your
submit button is moved into the step nav and shown only on the last step.

**Do not add your own page heading or step counter.** The partial supplies
both from the `<h2>` already in your card. A second one is the thing this
layout exists to remove.

**Nothing changes on the server.** The form still POSTs once, to the same
route, with the same fields — `$request->validate()` is untouched. A
server-side wizard would need session state, partial validation and resume
logic, and a student cannot tell the difference.

**Do not write a review step.** It is generated from the form's own controls,
so it cannot drift from the fields and no module maintains one.

**It degrades.** Nothing has `display: none` until the script runs, so with
JavaScript off the form is exactly what it was: one page, one submit. The
per-step gate is `checkValidity()` only — the server re-checks everything, so
the gate is a courtesy, never a control.

**A rejected submission reopens on the failing step**, found via
`.is-invalid` / `.field-error`. Keep rendering those in your fields
(`@error(...) <p class="field-error">` and the `is-invalid` class) or a
student lands on step 1 while the error sits on step 3.

Module views inherit the shell. `.card`, `.card-container-inline`, a plain
`<form>`, `<table class="data-table">` and `.status-badge` are already styled —
if you are writing CSS for a form, check you actually need it first.

## Git

One branch per person: `feature/<name>-<module>`, e.g. `feature/nureen-attendance`.

**After every pull or branch switch, run `./sync.sh`.** A pull can leave your
checkout in a state the app cannot run in, and none of it is obvious:
`composer.lock` may have changed, a teammate may have added a key to
`.env.example` that your git-ignored `.env` does not have, there may be pending
migrations, compiled Blade views from the previous branch are still being
served, and `queue:work` holds the app in memory so the queue worker is still
running pre-pull code. `./sync.sh --check` reports all of that without changing
anything.

Because you only touch your own folder, conflicts should be rare. If you hit
one in `Core/`, stop and talk to the team rather than resolving it alone.

Do not commit `.env`, `vendor/`, `node_modules/`, or anything under
`storage/app/uploads`.

## Blade

- Extend `core::layouts.app` for signed-in pages, `core::layouts.guest` for auth.
- Reuse the existing classes: `.card`, `.card-wide`, `.app-item`, `.stat-card`,
  `.status-badge`, `.empty-state`, `.stepper`. Norhanis' palette is in `:root`.
- New styling goes in **the sheet that owns that screen** —
  `dashboard-student.css`, `dashboard-cgs.css`, `dashboard-admin.css`,
  `notifications.css`. Anything genuinely shared by all three dashboards goes
  in `charts.css` (chart surfaces) or `dashboard-states.css` (skeletons,
  scrollbars). `uresearch.css` is Norhanis' original and is not edited at all
  now, so it stays reviewable. To override a rule she wrote, append a new one
  — and scope it with at least two classes, because `uresearch.css` contains
  broad element rules like `.sidebar a` (0,1,1) that outrank a single class.
- The sheets load in a fixed order, listed once in
  `core::partials.stylesheets` and included by both layouts. Do not reorder
  them; the cascade depends on it. Adding a sheet is a one-line edit there.
- Charts are Chart.js. Include `core::dashboard.partials.chartjs` and the
  shared tooltip, defaults and data-label plugin come with it.
- Blade's directive regex is `\B`-anchored, so two directives written back to
  back as `@endif@if` silently fail to compile the second one. Put a newline or
  a non-word character between them — `}}@if` and `>@endif` are both fine.
- **Check tag balance after editing a Blade partial.** A single stray `</div>`
  leaks the rest of the panel out of its card and out of its grid row, which
  looks like a CSS bug and is not one. The same applies to CSS: removing one
  selector from a comma-separated group takes the declaration block with it
  and silently kills the whole rule.
