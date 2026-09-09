# Nur Hani Sofia Binti Mohd Azam (22001418)

**Scope:** Examiner Nomination & Matching · Conflict Detection · Re-viva Monitoring. Constraint-based rule engine.

## Built

- **Examiner pool** — `examiners` with the four-state machine (Assigned,
  On Gap, Available, Unavailable). State is *derived* in `Examiner::state()`
  rather than stored, so an examiner comes off gap automatically at 90 days
  with no job needing to run.
- **Examiner Nomination** — supervisors nominate a main and optional backup
  examiner for their own candidates; the Academic Executive approves.
  Touchpoint 1 (individual eligibility at nomination time) is enforced both in
  the dropdown and again on submit.

## Still to build

- **Conflict detection, touchpoint 2** — cross-department duplicates found
  when CGS merges department lists into a faculty (FOE/FSMC) list. Needs the
  compilation screen; `examiners.faculty` and `users.faculty` are ready for it.
  Flag and surface availability rather than auto-rejecting.
- **Re-viva monitoring** — formal submission timestamp, the discrete stepper,
  and the 5-level outcome scale where level 4 loops back and level 5 is terminal.

## Layout

```
Hani/
├── ModuleProvider.php          registers your workflows
├── routes.php                  loaded automatically
├── Workflows/                  one class per application type
├── Models/                     your detail tables
├── Http/Controllers/           your controllers
├── Database/Migrations/        your tables only
└── Resources/views/            view('hani::your.view')
```

## The three rules

1. **Only edit files inside this folder.** If you need something from
   `Core/`, raise it with the team — it affects all six of us.
2. **Your `module_type` must be unique.** Claim it in `docs/module-keys.md`.
3. **Never write `status` or `current_stage` yourself.** Call
   `WorkflowEngine::submit()` and `::decide()`.

See `docs/adding-a-module.md` for the full walkthrough.
