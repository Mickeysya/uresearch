# Norhanis Erna Natasha Binti Mohd Hufzaini (22006318)

**Scope:** Travel · Publication · Claims · RPD Candidacy. Multi-tier workflow routing and real-time status tracking.

## Built

- **Travel** — complete, and the reference implementation for the whole team.
  Local travel finishes at the Chair; international routes on to Non-Executive
  CGS and the Dean of PGR. The branch lives in `TravelWorkflow::stages()`.
- **Publication** — Supervisor → Chair → Non-Exec CGS → Senior Director CGS.
  A four-`Stage` chain plus a detail table; the engine does the rest.
- **Claims (student)** — Supervisor → Chair → Non-Exec CGS → Manager CGS.
  Server-side total/balance calculation, itemised expense rows in a second table.
- **RPD Candidacy** — three approval-chain flows plus one plain admin action,
  all keyed off the `candidacies` table (the module's own "masterlist", never
  `applications`). `programme` (Masters/PhD) and `study_mode`
  (Full-Time/Part-Time) together decide every duration in this module —
  Table 5 of the FYP I interim report:
  - **Reminders** — `rpd:remind`, scheduled daily from `routes/console.php`,
    emails a student at 3/2/1 months before their candidacy's *current*
    deadline. `rpd_reminder_logs` (unique on candidacy + month mark) stops a
    reminder ever firing twice.
  - **RPD Appeal / Extension** (`rpd_appeal`) — student-submitted. Supervisor →
    Chair → Non-Exec CGS → Dean of PGR. The student requests a number of
    months (1-6, agreed with CGS), not a date; on the Dean's approval the
    current deadline is extended by that many months. See
    `RpdAppealController::extendCandidacy()`.
  - **Record Failed RPD Attempt** — Non-Exec CGS records an outcome that
    reached them manually from the AE (department-level RPD assessment
    scheduling is explicitly outside this module's scope). Not a
    `WorkflowModule` — there's no approver, just CGS entering a result. Sets
    the candidacy to `failed_awaiting_resubmission`, computes
    `resubmission_deadline` from Table 5 (`Candidacy::resubmissionMonthsFor()`), and bumps
    `attempt_number`. See `CandidacyController`.
  - **RPD Dismissal** (`rpd_dismissal`) — Non-Exec CGS-initiated from a list of
    candidacies overdue on either their original or resubmission deadline,
    not student-submitted (`createRoute()` returns `null`). Dean of PGR →
    Faculty is the whole chain — Registry is **not** a stage (same resolution
    as Claims' Project Director): Faculty's approval is final, and Registry's
    termination notice is a post-approval action, covered by the generic
    `ApplicationDecided` email. See `RpdDismissalController::decide()`.
  - `Candidacy.rpd_completed_at` is read by Haziq's future Stage Gate module
    to know the RPD milestone is done — a real cross-module dependency, not
    a coincidence of naming.

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
