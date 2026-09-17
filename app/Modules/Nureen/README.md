# Nureen Nellysha Binti Norazizi (22006973)

**Scope:** Attendance Record · GA Extension · Supervision · GA/GRA Certification Letter. Proactive intervention and document workflow automation.

## Built

- **GA Extension** — complete. Supervisor → CGS Staff → Senior Director CGS,
  with a required supporting document enforced at validation so incomplete
  applications never reach CGS.

- **Attendance Record** — complete. UTrace data comes in as a CGS-uploaded
  CSV (`/attendance/upload`), not a live API — there's no UTP system access
  for this FYP; see `Support\AttendanceRiskEvaluator` for the rule-based
  early-warning logic (threshold + declining-trend) and
  `Notifications\AttendanceAtRisk` for the proactive alert. The appeal chain
  (`attendance_appeal`) is a single stage straight to Non-Executive CGS.

- **Supervision** — complete. Supervisor → CGS eligibility review. CGS's
  approval sets `users.supervisor_id`. Because the engine can't yet scope a
  queue to a *specific* person (only a role — see TODO.md's cross-cutting
  notes), `SupervisionController` adds its own guard so a supervisor only
  ever sees and can act on requests actually addressed to them. Stall
  reminders run daily via `supervision:remind-stalled`.

- **GA/GRA Certification Letter** — complete. CGS verify GA/GRA →
  Senior Director CGS endorses → Dompdf generates the certificate and
  `DocumentStore::storeGenerated()` attaches it, so the existing download
  route and permission check apply with no extra code.

## Tests

`tests/Feature/Nureen/` — 22 cases, all four chains covered.

| File | Guards |
|---|---|
| `AttendanceTest` | the template round-trip (download it, feed it straight back), the header check, the date formats Excel writes, a bad row being skipped with a reason while the good rows save, re-uploading a period replacing rather than duplicating, and the at-risk alert firing on the transition |
| `GaExtensionTest` | document completeness — the module's point per `nureen.md` Module 2 — and the three-stage chain, asserting the position after *every* decision, because the middle stage is what the legacy app got wrong |
| `AttendanceAppealTest` | the single stage being final, and that a student cannot attach another student's flagged record to their own appeal (403) |
| `SupervisionTest` | the required document, and that it lands on the private disk under a random name |
| `CertificationTest` | the letter being generated, attached as an `ApplicationDocument`, and actually dispatched |

Run them with `./vendor/bin/sail artisan test --filter=Nureen`.

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
