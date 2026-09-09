# Architecture

## Layers

| Layer | What it is here |
|---|---|
| Presentation | Blade templates + Norhanis' stylesheet (`public/css/uresearch.css`), Chart.js via CDN |
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
