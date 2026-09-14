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


## The student dashboard

`Services\StudentDashboard` gathers every figure the student dashboard shows,
one named method per panel, so changing a data source is a one-method edit —
no view, route or controller changes.

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

The three dashboard services also share `Services\Concerns\BuildsPanels` —
`safely()`, `remember()`, `withTrend()` and the unavailable-panel bookkeeping,
which had been written out three times and had already started to drift.

The application list itself is registry-driven, so a new module appears there
with no edit.

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
