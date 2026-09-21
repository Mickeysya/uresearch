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
    Core/         the engine, the seams, CSP, the pages, the profile,
                  the demo seeder — the team's
    Norhanis/     ClaimsTest, PublicationTest, RpdTest, TravelTest
    Nureen/       AttendanceTest, AttendanceAppealTest, GaExtensionTest,
                  SupervisionTest, CertificationTest
    Hani/         ExaminerNominationTest, ReVivaTest
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

### Putting dates on the calendar

`/calendar` is a Core screen but owns no dates. If your module has a deadline
worth showing, implement `SuppliesCalendarEvents` on a workflow class you
already register — `ModuleRegistry` finds it the same way it finds
`ProvidesLinks`, so there is no wiring:

```php
class MyWorkflow implements WorkflowModule, SuppliesCalendarEvents
{
    public function calendarEvents(User $user, CarbonInterface $from, CarbonInterface $to): array
    {
        return [[
            'date' => $detail->due_on,     // required
            'title' => 'Thesis due',       // required
            'tone' => 'warn',              // good | info | warn | critical
            'meta' => 'Cycle 2',
            'url' => route('applications.show', $application),
        ]];
    }
}
```

**Scope your own query.** Core cannot know which rows this user may see, so the
supplier decides — a student gets their own, staff get what their role covers,
and `[]` is always a valid answer. A supplier that throws is skipped and
reported rather than blanking the calendar for everyone.

**Do not add an events table.** Every date on that page is already recorded
somewhere; a second copy is only a second thing to keep in sync.

### Blocking alerts on a dashboard

If your module has a rule that **stops** someone doing something, say so with
`ProvidesDashboardAlerts` alongside `WorkflowModule`, the same way
`ProvidesLinks` declares a sidebar link:

```php
public function alerts(User $user): array
{
    return HardboundSignature::forUser($user->id) ? [] : [[
        'tone' => 'critical',
        'title' => 'No signature on file',
        'body' => 'You cannot approve a Hardbound Submission until you upload one.',
        'action' => ['label' => 'Upload my signature', 'route' => 'hardbound.signature'],
    ]];
}
```

Core lays it out; Core never names your module or your models. Return `[]`
when nothing is in the way, which should be the normal case.

**Only for things that block.** A count, a reminder or a nice-to-know belongs
in a panel. An alert strip that is usually on is one nobody reads by the
second week — so raise it only for someone who is actually about to hit the
rule (the signature alert stays quiet for an approver with an empty queue).

### The page shell

Every screen is the same shape and the same width. Wrap the page in
`.card-container-inline` and open it with the header component:

```blade
<div class="card-container-inline">
    <x-core::page-header title="Examiner List"
                         subtitle="One line saying what this screen is for.">
        <a href="..." class="btn">Add an examiner</a>
    </x-core::page-header>

    ...the page...
</div>
```

**The width is the layout's job, not yours.** `layouts/app.blade.php` wraps
every screen in `.page-shell`, which is `max-width: var(--page-max)` —
`clamp(880px, 92vw, 1320px)`, so it grows with the viewport instead of holding
a laptop-era number. There used to be five page widths (480, 680, 880,
`--page-max`, and whatever the dashboard grid came to), and then a sixth
problem on top: a queue page wrapped itself in nothing and so ran the full
width of the content area while every card page sat centred. Putting the cap
in the layout means a view cannot forget it. `.card-container-inline` is now
only a bottom spacer.

**A queue's guidance goes *into* the partial.** `core::partials.queue` renders
the page header, so anything a module prints above the include lands above the
page title and the screen reads as though it has no heading. Pass `'intro' =>
'...'` instead. `PageShellTest` fails the build on markup above the include.

**A dashboard takes the whole screen.** The cap is right for one kind of page
and wrong for the other. A page of prose or a form is *read*, left to right,
and a 1900px line is hard to track back to the start of — that is what
`--page-max` defends. A dashboard is *scanned*: it is tiles, and tiles want
room. `layout.css` releases the cap with `.page-shell:has(> .sdash)`, so root
your dashboard in `.sdash` and it fills the screen with no view change.
Everything else stays capped.

**The page is full width; the text inside it is not.** A subtitle caps at
68ch and a form's own fields cap at 46rem, because a 1320px line is
unreadable however wide the monitor is. If your screen has a small object in
it (an upload, a signature), put it in its own column rather than stretching
it.

When the context line needs Blade of its own, pass it as a slot instead of an
attribute:

```blade
<x-core::page-header title="Documents">
    <x-slot:subtitle>
        {{ $documents->count() }} {{ Str::plural('file', $documents->count()) }}
    </x-slot:subtitle>

    <a href="..." class="btn">Upload</a>
</x-core::page-header>
```

Below 720px the header stacks and its actions go full width. No second
layout, no breakpoint of your own.

**A stepper form uses the same header.** `form-stepper` looks for an `<h2>`
inside the card; with the header above the card there is none, so it puts the
step counter into the page header instead of building a band of its own. The
wizard is the page width now, not its old 880px, and the field column caps at
46rem like every other form.

`tests/Feature/Core/PageShellTest.php` scans every signed-in view and fails on
a screen that declares its own heading, keeps a heading inside the card, or
writes a page width of its own. Two screens are exempt and say why: login is
on the guest layout, and the four dashboards open with the welcome banner,
which is a hero rather than a page header.

Three older names still resolve to the page header so nothing breaks
mid-branch: `.rpd-header`, `.notif-header` and the `.rpd-page` / `.notif-page`
wrappers. They are deprecated. Do not write new ones.

### The sidebar's queue list

Nothing to do: the nav builds itself from the stages your module declares.
One thing worth knowing, because it decides how your module reads there —
**a module owning more than one stage for the same role gets a group of its
own**, labelled with your module name, whose items are your **stage** labels
(`core::partials.queue-links`). A module with one stage for that role stays a
plain link.

So a stage label is user-facing in two places, not one: the queue page's title
and the sidebar. `Stage::$label` is display-only and safe to reword;
`Stage::$key` is stored in `applications.current_stage` and is not.

### Dashboard panels

A dashboard is locked to one viewport on a desktop (`dashboard-student.css`,
≥1201×700), so every panel has to have decided what it does when that squeezes
it. There are two right answers and a panel declares one on its body:

| Class | For | Behaviour |
|---|---|---|
| `approver-scroll` | a list of unknown length | scrolls internally; the `*-scroll` name is what `dashboard-states.css` hides the bar on |
| `approver-fit` | known, fixed content (a chart and a short legend) | sized to hold it, never scrolls |

A panel declaring neither overflows the layout.
`tests/Feature/Core/PageShellTest.php` fails the build on one, matching inside
a `class="…"` attribute rather than anywhere in the file — a guard a comment
can satisfy is not a guard.

**Overriding a dashboard component from `layout.css` needs two classes.**
`layout.css` is linked *before* the dashboard sheets, so `.sdash-chair { … }`
written there loses to `.sdash { … }` at equal specificity and silently does
nothing. Write `.sdash.sdash-chair`. `PageShellTest` fails on a single-class
`.sdash-*` rule in `layout.css`; this cost three rounds of debugging a layout
that was never the problem.

### Charts

Chart.js, loaded by `core::dashboard.partials.chartjs`. Two rules:

**Pick the type from the question, not from habit.** Every dashboard reaching
for a bar chart is how a portal ends up looking like one report repeated.
Ordered magnitudes (how long has each band of work waited) are a bar; parts of
a whole (how does my cohort split across attendance bands) is a doughnut. The
Chair's screen and the Supervisor's deliberately draw different charts.

**Colours come from tokens, never literals.** A canvas is out of CSS's reach,
so a colour has to be handed to the library as a string:
`Chart.uresearchToken('--danger-fg', '#D0342C')`. Pass it as a *function* in
the config — Chart.js re-evaluates scriptable options on update, which is what
lets the charts follow the dark theme. Hard-coded hexes are why the admin
chart's labels were once near-black on a dark page.

`chartjs-plugin-datalabels` is registered but off. A bar chart opts in, so its
values read without hovering; a doughnut does not, because numbers stamped
across four slices are noise.

### Colour and contrast

Use the tokens; never a literal. `--text-grey` is the secondary text colour
and is **`--grey-600` in light mode**, which is 5.14:1 on white and passes
WCAG AA. It used to be `--grey-500` at 3.06:1, which failed, and was what made
every hint, queue meta line and stat note look greyed out. If a new colour is
needed, add it to `tokens.css` with both themes rather than writing a hex in a
component, or it will be a light-mode value that stays light in the dark theme.

### Narrowing your own queue

`queueFor()` hands back a **paginator**, not a collection. Read it freely
(`->pluck('id')` for your detail rows is fine and only loads the page being
shown), but **never filter the rows it returns**:

```php
// WRONG. Reports the unfiltered total, pages over rows it then discards,
// and -- because a paginator forwards unknown calls to its collection --
// hands the view a plain Collection, which 500s on ->total().
$queue['applications'] = $queue['applications']->filter(...);

// RIGHT. Narrow the QUERY, before the count and before the page.
$queue = $this->queueFor($request, $engine, ['documents'],
    function ($query, Stage $stage) use ($request) {
        if ($stage->key !== 'supervisor') {
            return;
        }

        $query->whereIn('id', SupervisionDetail::query()
            ->where('requested_supervisor_id', $request->user()->id)
            ->select('application_id'));
    });
```

Nureen's Supervision queue is the live example: the engine scopes by role, and
every supervisor-role account would otherwise be handed every request at that
stage. This closure is per-call and is **not** the general answer to "scope
approver queues to the right people" in `TODO.md` — that still needs the team
to pick between a `Stage` property and a module hook.

### Empty states

An empty state's one way forward is a **button**, not a sentence with a
hyperlink in it. `.btn` (filled, the one real action) and `.btn-secondary`
(outlined, an alternative) both already work on an `<a>` and need no new CSS:

```blade
<div class="empty-state">
    <p>No examiners yet.</p>
    <p class="queue-meta">Add the first one and they appear on the nomination form.</p>
    <a href="{{ route('appointment-letter.examiners.create') }}" class="btn">Add an examiner</a>
</div>
```

A link that sits inside a sentence stays a link. The rule is the one already
written above the button block in `layout.css`: filled is the one real
action, outlined is an alternative, destructive is destructive, and anything
else is a link.

### Words on the screen

**No em dash as a sentence connector.** "We did X — it means Y" is the most
recognisable tell that a sentence was not typed by a person, and this project
gets read by supervisors. Use a comma, a semicolon, a colon or a full stop.

Two things this does *not* ban, both of which are ordinary typography:

- The **en dash** in a range: `75% – 84%`, `Jan – Mar`.
- A bare **em dash standing in for an empty cell**: `{{ $x ?? '—' }}`.

`tests/Feature/Core/ProseTest.php` scans every Blade view in the repo for the
spaced em dash and `&mdash;`, ignoring Blade comments, `@php` blocks, `<style>`,
`<script>` and code comments. It fails with the file and line.

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

The review step's one line of prose says the form goes to the first approver,
which is true of every application form. If yours does not file an application
— a list a module keeps for itself, an image uploaded once — say what it does
instead with `data-stepper-review="..."` on the `<form>`. Text only; it is set
with `textContent`.

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
migrations, a migration may have added a column the seeder is what actually
fills (the department list is the usual one), compiled Blade views from the
previous branch are still being served, and `queue:work` holds the app in
memory so the queue worker is still running pre-pull code. `./sync.sh --check` reports all of that without changing
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
