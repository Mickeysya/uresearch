# Norhanis Erna Natasha Binti Mohd Hufzaini (22006318)

**Scope:** Travel · Publication · Claims. Multi-tier workflow routing and real-time status tracking.

## Built

- **Travel** — complete, and the reference implementation for the whole team.
  Both routes go Supervisor → Chair → Non-Executive CGS; local travel ends with
  the Senior Director CGS, international with the Dean of PGR. The branch lives
  in `TravelWorkflow::stages()`.
- **Publication** — Supervisor → Chair → Non-Exec CGS → Senior Director CGS.
  A four-`Stage` chain plus a detail table; the engine does the rest.
- **Claims (student)** — Supervisor → Chair → Non-Exec CGS → Manager CGS.
  Journal publication claims: bank details, the total, and the receipt, proof
  of payment and invoice it is checked against.

RPD Candidacy was removed from this module's scope on 2026-10-30.

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

## Demo data (local/dev only)

```bash
php artisan norhanis:demo-seed           # 30 Travel, 70 Publication, 35 Student Claims from existing users
php artisan norhanis:demo-seed --fresh   # clear the previous run first, then seed again
php artisan norhanis:demo-clear          # remove exactly what the seeder created
```

Uses only existing students, supervisors, Chairs and CGS staff (creates and
changes no users), spread across every department in proportion to its
students. Everything goes through the module's own services (WorkflowEngine,
ApproverNotifier, PublicationSignOffs, PublicationFollowUp, DocumentStore) with
the clock set back to when each step "happened", so history, documents,
sign-off PDFs and in-app notifications look real. No email is sent (array
mailer, sync queue). What it creates is listed in
`storage/app/demo-seed-manifest.json`; `demo-clear` deletes only those rows,
their files, their notifications and their activity-log entries. Refuses to run
when `APP_ENV=production`. Named `norhanis:*` because `demo:seed` is the team's
command. Code: `Support/Demo/`, `Console/Commands/DemoSeed.php`, `DemoClear.php`.
