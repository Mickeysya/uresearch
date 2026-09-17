# Architecture

## Layers

| Layer | What it is here |
|---|---|
| Presentation | Blade templates + the stylesheets below; Chart.js + chartjs-plugin-datalabels served from `public/js` |
| Application | Laravel controllers, one thin controller per module |
| Shared services | `WorkflowEngine`, `ModuleRegistry`, `DocumentStore`, `EnsureRole`, notifications |
| Data | MySQL 8.4 in Docker, accessed through Eloquent |
| External | SMTP via Mailpit locally; real SMTP in production |

## The two halves

**Core** owns everything shared: the user record, the application spine, the
audit trail, uploads, auth, the layout and the engine. One team decision, one
implementation.

**Module folders** own one application type each. A module never touches
Core's tables and never writes `status` or `current_stage`.

## Data model

```
users ──────────< applications >────── approval_history
                      │      │
                      │      └───────< application_documents
                      │
                      └── your detail table (travel_details, ga_extension_details, ...)
```

### `applications` — the spine

| Column | Meaning |
|---|---|
| `student_id` | the student the application is **about** — they see it and get the emails |
| `submitted_by_id` | who **filed** it; usually the same person, but a supervisor files examiner nominations |
| `module_type` | which module owns the row (`travel`, `ga_extension`, …) |
| `status` | `draft` · `pending` · `approved` · `rejected` — only whether it is open, done or dead |
| `current_stage` | the `Stage::key` it is sitting on |

`status` and `current_stage` answer different questions on purpose. The legacy
schema had `status` also carrying `endorsed` and `reviewed`, duplicating what
`current_stage` said, and the two drifted apart.

### Why VARCHAR and not ENUM

`role`, `status`, `module_type` and `decision` are all `VARCHAR` with the valid
values listed in PHP (`Support\Role`, `Application::STATUS_*`).

An ENUM would mean that adding a role or a decision verb requires altering a
table five other people also use. That is exactly how the legacy app ended up
with three incompatible copies of `schema.sql` in three different folders — and
how `ga_extension_cgs.php` came to write `decision='reviewed'` into an ENUM
that did not contain it.

## The workflow engine

`WorkflowEngine` is the only code that writes `status` or `current_stage`.

```php
$engine->submit($application);                              // onto stage 1
$engine->decide($application, $user, 'approve', $remarks);  // advance or finish
$engine->queue('travel', 'chair');                          // who is waiting
$engine->progress($application);                            // for the stepper
```

`decide()` in one transaction: checks the actor's role against the stage,
appends to `approval_history`, advances to the next stage (or marks the
application approved when the chain runs out), and notifies the student.

Rejection sets `status = rejected` and **leaves `current_stage` where it was**,
so the stepper can show the student exactly where it stopped.

`queue()` returns a **Builder**, not a collection, and
`Concerns\ApprovesApplications::queueFor()` paginates it (20 a page), searches
it and sorts it. All fourteen queue controllers go through that one method, so
none of them holds a queue in memory. A queue that was a plain `->get()` is
fine for the two rows a demo has and an out-of-memory error for a real
backlog.

Deciding a whole page at once goes through `POST /queue/{module}/decide`
(`Core\Http\Controllers\QueueController`), which is generic so a module does
not add a route for it. It loops `decide()` one row at a time rather than
doing anything clever: that keeps the engine the only writer, re-checks the
actor against each row's own stage, and means a row someone else already
decided cannot roll back the rest of the batch.

### Conditional routing

`stages()` receives the application, so the chain can depend on its data:

```php
if ($this->isInternational($application)) {
    $chain[] = new Stage('chair',      'Chair of Department', Role::CHAIR,        'endorsed');
    $chain[] = new Stage('cgs_review', 'Non-Executive CGS',   Role::NON_EXEC_CGS, 'reviewed');
    $chain[] = new Stage('dean',       'Dean of PGR',         Role::DEAN_PGR,     'approved');
} else {
    $chain[] = new Stage('chair', 'Chair of Department', Role::CHAIR, 'approved');
}
```

The student's stepper shows the right number of steps from the moment they
submit, because the same declaration drives both the routing and the display.

`stages()` is also called with `null` to ask for the **superset** — every stage
the module can ever route through. The sidebar and the approval queues are
built from that, so a stage which only some applications reach (CGS and the
Dean on international travel) is still visible to the role that owns it.


## The dashboards

There are six: student, CGS, admin, **Chair**, **Supervisor**, and the shared
**approver** screen every other approving role lands on. Each is a service
plus a view of partials, one named method per panel, so changing a data source
is a one-method edit with no view, route or controller change.

`ChairDashboard`, `SupervisorDashboard` and `GeneralApproverDashboard` all
extend `Services\ApproverDashboard`, which holds everything they share — the
queues, the longest wait, the overdue count, the ageing profile, the triage
list, the decision trail, the blocking alerts. What a subclass adds is the
part that makes it a different screen rather than the same one retitled: a
Chair files examiner panels, a Supervisor is accountable for **named
students**, and `GeneralApproverDashboard` adds nothing at all, which is the
point of it.

**Who lands where.** Student, admin and CGS have their own branches. Then
Chair, then Supervisor. Everyone else who approves anything — the Dean of PGR,
the Academic Executive, the Registry, the Faculty office, the Senior Executive
— gets the shared approver screen, because what each of them owns is a set of
stages and `ApproverDashboard` derives a whole dashboard from that. The
Academic Executive's examiner conflicts and pending evaluations live in
`app/Modules/Hani`, which Core cannot read; they reach that screen anyway as
Quick actions, because the module declares them through `ProvidesLinks`.

All of them exist because the old approver view built one stat card per queue.
A Chair owns five stages, a Supervisor seven and the Academic Executive six, so
it rendered six, eight and seven cards — most reading zero — over a chart of
mostly-empty categories, and never showed the figure any of them is actually
measured on. Every dashboard is on `.sdash` now; that was the last one.

**They draw different charts on purpose.** The Chair's asks how long work has
been sitting (four ordered bands — a bar); the Supervisor's asks whether the
cohort is healthy (parts of a whole — a doughnut). Same library, same tokens,
different question. See the Charts section in `docs/conventions.md`.

**One screen on a desktop.** `.sdash` is height-locked at ≥1201×700, so every
panel declares how it behaves when squeezed — `approver-scroll` for a list of
unknown length, `approver-fit` for content that must not scroll. Below either
threshold the lock lifts and the page returns to natural height. Two things
about that layout are easy to get wrong and are both guarded: a `.sdash-*`
override written in `layout.css` needs two classes to win the cascade, and a
panel that declares neither class overflows.

Two things about it are worth knowing before building on it:

**It degrades instead of failing.** Every panel query runs through
`safely()`, which catches, reports, and marks that panel unavailable. The view
holds a skeleton placeholder for those panels rather than showing an empty
state — "nothing to show" and "could not load" mean different things to a
student looking at their own record — and one dead query costs that panel
rather than the whole page.

**It names no module.** Attendance belongs to `app/Modules/Nureen`, and this
service used to import its Eloquent models directly behind a `class_exists()`
guard — the one place Core reached into someone else's folder. It now asks for
`Core\Contracts\SuppliesAttendance`, which Nureen implements
(`Support\AttendanceProvider`) and binds from its own `ModuleProvider`.

The dependency points from the module to Core, like every other arrow in the
system. If no module binds the contract, the attendance panels hold their
skeletons — the same degradation as before, expressed as a contract rather
than a string class name. Core hands out `Support\AttendanceReading`, a small
readonly DTO, so Core never sees another module's Eloquent model.

The dashboard services share `Services\Concerns\BuildsPanels` —
`safely()`, `remember()`, `withTrend()` and the unavailable-panel bookkeeping,
which had been written out three times and had already started to drift.

**Blocking alerts follow the same rule as attendance.** A Chair cannot approve
a hardbound thesis without a signature on file, and nothing said so until they
tried. That rule and the model behind it live in `app/Modules/Jason`, so Core
cannot read them: the module declares the alert through
`Core\Contracts\ProvidesDashboardAlerts` and the registry merges them, exactly
as `ProvidesLinks` does for the sidebar. The arrow points from the module to
Core, like every other arrow here.

The application list itself is registry-driven, so a new module appears there
with no edit.

## Repo-wide guards

Four tests in `tests/Feature/Core/` scan every Blade view in the repo rather
than only the screens a test happens to render, because the failures they
catch all render perfectly:

| Test | Catches |
|---|---|
| `ContentSecurityPolicyTest` | an inline `<script>` without `@cspNonce`, or a CDN tag — the browser refuses it and the page still returns 200 |
| `PageShellTest` | a screen with its own heading, its own page width, or markup above a queue's page header; a dashboard panel that declares neither `approver-scroll` nor `approver-fit`; a single-class `.sdash-*` override in `layout.css`; a dashboard that has lost its `.sdash` root |
| `ProseTest` | an em dash used as a sentence connector |
| `QueueTest` | the queue paging, searching, sorting and bulk-deciding, including rows that are not yours |

## Stylesheets

Four files, linked in this order, and the order is load-bearing — later
sheets override earlier ones exactly as they did when this was one file:

| File | Owner | Contents |
|---|---|---|
| `uresearch.css` | Norhanis | her original sheet, never edited in place |
| `layout.css` | the team | status pills, steppers, queue and page chrome |
| `sidebar.css` | the team | the collapsible sidebar nav |
| `dashboard-banner.css` | the team | the student dashboard's welcome banner |
| `dashboard-student.css` | the team | the student dashboard's one-screen grid |
| `dashboard-gauge.css` | the team | the attendance gauge's sweep, legend and facts |
| `dashboard-states.css` | the team | skeletons and the hidden-scrollbar rule |
| `dashboard-cgs.css` | the team | the CGS dashboard and its workload donut |
| `notifications.css` | the team | the `/notifications` feed |
| `dashboard-admin.css` | the team | the admin dashboard and Application Overview |
| `charts.css` | the team | every Chart.js surface — containers, hover, tooltip |
| `sidebar-identity.css` | the team | the sidebar's identity card |

New styling goes in the sheet that owns that screen; anything shared by all
three dashboards goes in `charts.css` or `dashboard-states.css`. The order
is declared once in `core::partials.stylesheets` and included by both
layouts. Each is cache-busted with `?v=<file mtime>`, so a change always
reaches the browser.

## Charts

Chart.js, the stack document's choice, loaded once per page by
`core::dashboard.partials.chartjs`. Three things that partial sets up which
individual charts then rely on:

- **Defaults run synchronously**, not on `DOMContentLoaded`. Charts are built
  from scripts pushed to the end of `<body>`, which execute first — defaults
  set in that listener would land after the charts already exist.
- **An external tooltip.** Chart.js draws its tooltip inside the canvas, where
  it clips at the edge and covers the chart. Ours is a `<div>` on `<body>`,
  clipped by nothing, positioned away from what it describes: pushed outward
  from a doughnut's centre, and above a bar's value label.
- **`chartjs-plugin-datalabels`**, registered but `display: false` by default.
  A chart opts in; the bar chart does, the doughnuts do not.

## Audit trail

Two layers, and they answer different questions:

| | `approval_history` | `activity_log` |
|---|---|---|
| Written by | `WorkflowEngine` only | anything calling `activity()` |
| Records | decisions on applications | sign-ins, decisions, uploads, document views |
| Read by | the stepper, tracking page | `/admin/audit-logs` |

`approval_history` is the workflow's own record and stays authoritative for
where an application has been. `activity_log` (spatie/laravel-activitylog) is
the administrator's view of who did what, and is the only place a document
*view* is recorded.

## Notifications

Two channels. `mail` goes to Mailpit locally and real SMTP in production;
`database` writes to the `notifications` table and is what the in-app feed and
the unread badge read. A notification opts into either by listing it in its own
`via()`, so adding the channel to one notification does not touch any other.

## Module discovery

`ModuleServiceProvider` scans `app/Modules/*` and wires up, if present:

| Path | Effect |
|---|---|
| `ModuleProvider.php` | registered as a service provider — register your workflows in `boot()` |
| `routes.php` | loaded with the `web` middleware group |
| `Database/Migrations/` | picked up by `php artisan migrate` |
| `Resources/views/` | available as `view('<lowercase-folder>::…')` |

Nothing central lists the modules, which is why adding one causes no conflict.

## Authorisation

Two independent locks:

1. `role:` middleware on the route — a wrong role never reaches the controller.
2. `WorkflowEngine::decide()` re-checks the actor's role against the stage the
   application is **actually** on.

The second matters because one queue route serves every stage of a chain. A
Chair can open the travel queue, but cannot act on a row sitting at the Dean.

## Uploads

`DocumentStore` is the only sanctioned path. Files go to the private `local`
disk under `storage/app`, are renamed to a random string, and are streamed back
through `DocumentController`, which checks that the viewer is the student, an
approver on the chain, or an admin.

Nothing uploaded is ever reachable under `public/`.
