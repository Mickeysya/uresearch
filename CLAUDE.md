# UResearch 2.0

Centralized postgraduate administrative portal for the Centre for Graduate
Studies (CGS), Universiti Teknologi PETRONAS. Six-person Final Year Project.

The Laravel application **is** the repository root — `app/`, `config/`, `routes/`
and the rest sit directly here.

## Stack

Laravel 12 · PHP 8.2+ · MySQL 8.4 (Docker) · Blade · Chart.js (CDN) · Dompdf

MySQL, phpMyAdmin and Mailpit run in Docker; Laravel runs on the host via
`php artisan serve`. `setup.sh` does first-time setup.

## The one thing to understand

Six people share this repo, and **each owns exactly one folder** under
`app/Modules/`:

| Folder | Owner | Modules |
|---|---|---|
| `Core/` | the team | shared foundation — do not edit without asking |
| `Norhanis/` | Norhanis Erna Natasha (22006318) | Travel · Publication · Claims · RPD |
| `Nureen/` | Nureen Nellysha (22006973) | Attendance · GA Extension · Supervision · Certification |
| `Hani/` | Nur Hani Sofia (22001418) | Examiner Nomination · Conflict Detection · Re-viva |
| `Jason/` | Jason | to be scoped |
| `Chloe/` | Chloe | to be scoped |

Everything a module needs — migrations, models, controllers, routes, views —
lives in its own folder. Nothing central lists the modules, so adding one never
causes a merge conflict. `ModuleServiceProvider` discovers them by convention.

**When helping someone, stay inside their folder.** If a task seems to need a
change in `Core/`, say so explicitly and explain the impact rather than just
making it — it affects all six people.

## The workflow engine

A module declares its approval chain once:

```php
public function stages(Application $application): array
{
    return [
        new Stage('supervisor', 'Lecturer/Supervisor', Role::SUPERVISOR, 'endorsed'),
        new Stage('chair',      'Chair of Department', Role::CHAIR,      'approved'),
    ];
}
```

From that: routing, queue queries, authorisation, the progress stepper, the
tracking page, notification emails, and sidebar links. `stages()` takes the
application, so conditional routing is just an `if` — see `TravelWorkflow`,
where international travel returns four stages and local travel two.

`WorkflowEngine` is the **only** code that writes `applications.status` or
`applications.current_stage`. Call `submit()` and `decide()`; never set those
columns directly. This is the rule that keeps the modules from drifting apart
the way the legacy app's did.

## Publishing — the human's call

**Never push, and never publish anything to GitHub.** Not `git push`, not
`gh pr create`, not releases, not changing a remote. Sharvin does all of that.

This is enforced, not just requested: `.claude/hooks/block-remote-writes.py`
rejects those commands as a PreToolUse hook. Do not try to work around it.

What you *should* do instead: commit locally on a branch, then tell the user
exactly what to run, e.g.

```bash
git push -u origin laravel-rewrite
```

Still fine: committing, branching, `git fetch` / `pull`, and read-only `gh`
(`gh pr view`, `gh pr list`, `gh repo view`, `gh api` without `-X`).

Also: do not commit on `main`. Branch first.

## Hard rules

1. Never write `status` or `current_stage` outside `WorkflowEngine`.
2. Never add columns to `users`, `applications`, `approval_history` or
   `application_documents`. Your module gets its own `<module>_details` table.
3. `module_type` and `Stage::key` are stored in rows — permanent. Labels are
   display-only and safe to reword.
4. Uploads go through `DocumentStore`. Never `move_uploaded_file()`, never
   write under `public/`.
5. Validate every input with `$request->validate()`. Never touch `$_POST`.
6. Blade `{{ }}` escapes; `{!! !!}` does not. Never pass user input to `{!! !!}`.
7. Secrets live in `.env`. Never hardcode credentials.
8. Scope every query — students see only their own rows, approvers only their
   stage.

## Reference

| Doc | Covers |
|---|---|
| `README.md` | setup, test accounts, commands |
| `TODO.md` | what is built, what is not, and the agreed order of work |
| `docs/architecture.md` | layers, engine, data model |
| `docs/adding-a-module.md` | full worked example |
| `docs/conventions.md` | naming, ownership, the eight rules |
| `docs/module-keys.md` | claim a `module_type` here |
| `docs/migration-from-legacy.md` | every bug the rewrite fixed |

`app/Modules/Norhanis/` is the reference implementation — a complete module
with a workflow, model, migration, controller, routes and views. Point people
at it, and copy its patterns rather than inventing new ones.

## Test accounts

Password for all: `password`. See the table in `README.md`.
`student@utp.edu.my` → `supervisor@utp.edu.my` → `chair@utp.edu.my` walks a
full local-travel chain.

## Context

The FYP scope documents are in `docs/scope/`: `technical.md` (system-wide),
`norhanis.md`, `nureen.md`, `hani.md` (per-person module breakdowns). They
describe the intended behaviour, including modules not yet built — so when a
module looks half-finished, check there before assuming it is a bug.

`TODO.md` is the source of truth for what is actually built — several modules
described in the scope documents do not exist yet. Check it before telling
someone a feature is missing or broken.

The pre-rewrite raw-PHP app is preserved in `legacy-archive.tar.gz`; see
`LEGACY.md`. Do not copy patterns out of it without reading
`docs/migration-from-legacy.md` first.
