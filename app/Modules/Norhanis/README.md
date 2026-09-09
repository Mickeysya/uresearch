# Norhanis Erna Natasha Binti Mohd Hufzaini (22006318)

**Scope:** Travel · Publication · Claims · RPD Candidacy. Multi-tier workflow routing and real-time status tracking.

## Built

- **Travel** — complete, and the reference implementation for the whole team.
  Local travel finishes at the Chair; international routes on to Non-Executive
  CGS and the Dean of PGR. The branch lives in `TravelWorkflow::stages()`.

## Still to build

- **Publication** — Supervisor → Chair → Non-Exec CGS → Senior Director CGS.
  A four-`Stage` chain plus a detail table; the engine does the rest.
- **Claims (student)** — Supervisor → Chair → Non-Exec CGS → Manager CGS.
  Needs a second table for the itemised expense rows.
- **RPD Candidacy** — the largest remaining piece, and three separate flows:
  reminders at 3/2/1 months (a scheduled command — see `routes/console.php`),
  appeals with automatic deadline recalculation on the Dean's approval, and
  dismissals routing to Dean → Faculty → Registry.

## Layout

```
Norhanis/
├── ModuleProvider.php          registers your workflows
├── routes.php                  loaded automatically
├── Workflows/                  one class per application type
├── Models/                     your detail tables
├── Http/Controllers/           your controllers
├── Database/Migrations/        your tables only
└── Resources/views/            view('norhanis::your.view')
```

## The three rules

1. **Only edit files inside this folder.** If you need something from
   `Core/`, raise it with the team — it affects all six of us.
2. **Your `module_type` must be unique.** Claim it in `docs/module-keys.md`.
3. **Never write `status` or `current_stage` yourself.** Call
   `WorkflowEngine::submit()` and `::decide()`.

See `docs/adding-a-module.md` for the full walkthrough.
