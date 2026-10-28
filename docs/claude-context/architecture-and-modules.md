# UResearch 2.0 — Architecture & Nureen's Modules

Reference doc for syncing a Claude Project's context. Written as a standalone
summary — the canonical, always-current versions of this material live in
`docs/architecture.md`, `docs/scope/nureen.md`, `docs/conventions.md` and
`TODO.md`; this file will drift from the code over time, those won't. Point
a connector at them directly if your Project setup allows it.

## What it is

Centralized postgraduate administrative portal for the Centre for Graduate
Studies (CGS), Universiti Teknologi PETRONAS. A six-person Final Year
Project, rewritten from a legacy raw-PHP app into Laravel.

**Stack:** Laravel 12 · PHP 8.3 · MySQL 8.4 (Docker) · Blade · Chart.js ·
Dompdf. MySQL, phpMyAdmin and Mailpit run in Docker; Laravel runs on the
host via `php artisan serve`.

## The one idea everything else follows

Six people share this repo, and each owns exactly one folder under
`app/Modules/`:

| Folder | Owner | Modules |
|---|---|---|
| `Core/` | the team | shared foundation |
| `Norhanis/` | Norhanis Erna Natasha | Travel · Publication · Claims · RPD |
| `Nureen/` | Nureen Nellysha | Attendance · GA Extension · Supervision · Certification |
| `Hani/` | Nur Hani Sofia | Examiner Nomination · Conflict Detection · Re-viva |
| `Jason/` | Yau Jia Sheng | Hardbound Submission · Appeal · Appointment Letters |
| `Chloe/` | Chloe Ching Qing En | Workstation · Study Candidacy Reminder/Appeal/Dismissal |
| `Haziq/` | Abdul Haziq bin Abdul Farouk | GRA · GA · Stage Gates · Allowance Eligibility |

Everything a module needs — migrations, models, controllers, routes, views —
lives entirely inside its own folder. Nothing central lists the modules:
`ModuleServiceProvider` scans `app/Modules/*` at boot and wires up whatever
it finds there (`ModuleProvider.php`, `routes.php`,
`Database/Migrations/`, `Resources/views/`). This is what lets six people
add modules in parallel without merge conflicts — nobody edits a shared
registry file to plug their module in.

`Core/` is the one shared folder, changed only by team agreement, because a
change there can affect all six people at once.

## Layers

| Layer | What it is here |
|---|---|
| Presentation | Blade templates + per-screen stylesheets; Chart.js |
| Application | Laravel controllers, one thin controller per module |
| Shared services | `WorkflowEngine`, `ModuleRegistry`, `DocumentStore`, role middleware, notifications |
| Data | MySQL 8.4, accessed through Eloquent |
| External | SMTP via Mailpit locally; real SMTP in production |

## Data model

```
users ──────────< applications >────── approval_history
                      │      │
                      │      └───────< application_documents
                      │
                      └── your own <module>_details table
```

- **`applications`** is the shared spine every module's rows live in:
  `student_id` (who the application is about), `submitted_by_id` (who filed
  it — usually the same person, but not always), `module_type`, `status`
  (`draft`/`pending`/`approved`/`rejected` — only whether it's open, done or
  dead), `current_stage` (a `Stage::key`).
- **`application_documents`** is already **one-to-many per application** —
  `Application::documents(): HasMany`. Every upload, from every module,
  lands here. This matters: a module never needs a schema change to attach
  more than one file to an application (see Recent Updates).
- A module never adds columns to `users`, `applications`,
  `approval_history` or `application_documents`. It gets its own
  `<module>_details` table instead (e.g. `ga_extension_details`,
  `supervision_details`).

## The workflow engine

A module declares its approval chain once, as an array of `Stage` objects
(key, label, role, decision verb), optionally branching on the
application's own data:

```php
public function stages(?Application $application = null): array
{
    return [
        new Stage('supervisor', 'Lecturer/Supervisor', Role::SUPERVISOR, 'endorsed'),
        new Stage('chair',      'Chair of Department', Role::CHAIR,      'approved'),
    ];
}
```

From that one declaration: routing, the student's progress stepper, every
approver's queue, authorization, the sidebar, and notification emails.

`WorkflowEngine` is the **only** code allowed to write `applications.status`
or `applications.current_stage`. Three entry points matter:

- **`submit()`** — puts the application onto stage 1.
- **`decide()`** — approve/reject. Re-checks the actor's role against the
  application's *actual current stage* (not just the route's role
  middleware — one queue route serves every stage of a chain), appends to
  `approval_history`, advances to the next stage or marks the application
  approved when the chain runs out.
- **`returnTo()`** — sends an application back to an earlier stage with a
  **mandatory** reason, instead of ending it. Used where a stage wants
  "someone earlier has to redo this" rather than "rejected, full stop" (the
  Senior Director and the Dean use it on examiner lists).

If a module's own `decide()` does more than delegate straight to the engine
(checks which supervisor a row names, moves a deadline, generates a
letter), it implements the marker interface `Core\Contracts\DecidesOneAtATime`
so Core's generic bulk-decide refuses that module rather than silently
skipping its extra logic. Supervision is one of the modules that implements
this — bulk-approving the queue cannot bypass the "only the named
supervisor may endorse" check.

## Document storage

`DocumentStore` is the **only** sanctioned upload path — never
`move_uploaded_file()`, never anything written under `public/`. A file is
renamed to a random string on the private `local` disk (so an uploaded
`.php` can never be requested back as a script) and is only ever streamed
back through `DocumentController`, which checks the viewer is the student
who owns the application, an approver on its chain, or an admin.

Because `application_documents` is already one-to-many, attaching several
files to one application has never needed a schema change — only a field
that submits an array and a loop over `DocumentStore::attach()` (see Recent
Updates, below, for the specific change that made this true for GA
Extension and Supervision).

## Dashboards (brief)

Six dashboards total: student, CGS, admin, Chair, Supervisor, and a shared
"approver" screen every other approving role lands on. Each is a service
class (one named method per panel) plus a Blade view built from partials —
changing a data source is a one-method edit, no view/route/controller
change needed.

The student dashboard is natural-height (the page scrolls); CGS and Admin
are locked to one viewport on desktop "by requirement," with each panel
declaring whether it scrolls internally or is sized to never overflow. See
`docs/architecture.md` → "The dashboards" for the full detail (why they
draw different charts, how attendance's three states work, the
`approver-scroll`/`approver-fit` contract).

## Nureen's four modules

| Module | What it does | Approval chain |
|---|---|---|
| **Attendance** | Predictive at-risk flagging from a CSV/.xlsx upload (UTrace has no API access for this FYP — documented as future work). Proactive alerts to student + supervisor, a CGS "at-risk" list, and a student-filed appeal. | Appeal → Non-Executive CGS |
| **GA Extension** | Extends a Graduate Assistantship. Mandatory document-completeness validation runs *before* CGS ever sees the application. | Supervisor → CGS Staff → Senior Director CGS |
| **Supervision** | Supervisor appointment requests. Must be decided by the *specific* named supervisor, not just anyone holding the supervisor role — enforced in the module's own `decide()`, which is why it implements `DecidesOneAtATime`. | Request → named Supervisor → CGS eligibility review |
| **Certification** | GA/GRA completion letters. On final approval, Dompdf auto-generates, formats and dispatches the official PDF, downloadable by the student instantly. | CGS verifies appointment status → approver endorses → PDF generated |

All four are built and complete per `TODO.md` (not merely scoped — check
`TODO.md` before assuming anything described in `docs/scope/nureen.md` is
unbuilt).

## Hard rules that govern any change here

1. Never write `status` or `current_stage` outside `WorkflowEngine`.
2. Never add columns to `users`, `applications`, `approval_history` or
   `application_documents` — a module gets its own `<module>_details`
   table.
3. `module_type` and `Stage::key` are stored in rows and are permanent once
   live. Labels are display-only and safe to reword.
4. Uploads go through `DocumentStore`. Never `move_uploaded_file()`, never
   write under `public/`.
5. Validate every input with `$request->validate()`. Never touch `$_POST`.
6. Blade `{{ }}` escapes; `{!! !!}` does not — never pass user input to
   `{!! !!}`.
7. Secrets live in `.env`, never hardcoded.
8. Scope every query — students see only their own rows, approvers only
   their stage.
9. A change inside `Core/` affects all six people — flag it and explain the
   impact rather than making it silently.

## Where the canonical docs live

| Doc | Covers |
|---|---|
| `README.md` | setup, test accounts, commands |
| `docs/architecture.md` | full layer/engine/data-model detail, dashboards, stylesheet load order, the audit trail |
| `docs/conventions.md` | naming, ownership table, the nine rules, which stylesheet to edit |
| `docs/scope/nureen.md` | Nureen's module breakdown as originally scoped for the FYP report |
| `docs/module-keys.md` | claiming a `module_type` |
| `docs/migration-from-legacy.md` | every bug the Laravel rewrite fixed |
| `TODO.md` | what is actually built vs. only scoped, and the agreed order of work |
| `docs/test-accounts.md` | every seeded login, by role |
