# Nureen Nellysha Binti Norazizi (22006973)

**Scope:** Attendance Record · GA Extension · Supervision · GA/GRA Certification Letter. Proactive intervention and document workflow automation.

## Built

- **GA Extension** — complete. Supervisor → CGS Staff → Senior Director CGS,
  with a required supporting document enforced at validation so incomplete
  applications never reach CGS.

## Still to build

- **Attendance Record** — the predictive at-risk piece. Needs a decision on
  where UTrace data comes from before the risk model matters.
- **Supervision** — supervisor appointment requests, with reminder escalation
  when an approval stalls.
- **GA/GRA Certification Letter** — Dompdf is already in `composer.json`;
  generate on final approval and stream it through `DocumentController`.

## Layout

```
Nureen/
├── ModuleProvider.php          registers your workflows
├── routes.php                  loaded automatically
├── Workflows/                  one class per application type
├── Models/                     your detail tables
├── Http/Controllers/           your controllers
├── Database/Migrations/        your tables only
└── Resources/views/            view('nureen::your.view')
```

## The three rules

1. **Only edit files inside this folder.** If you need something from
   `Core/`, raise it with the team — it affects all six of us.
2. **Your `module_type` must be unique.** Claim it in `docs/module-keys.md`.
3. **Never write `status` or `current_stage` yourself.** Call
   `WorkflowEngine::submit()` and `::decide()`.

See `docs/adding-a-module.md` for the full walkthrough.
