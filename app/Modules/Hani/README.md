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
- **Examiner lifecycle** — `ExaminerNominationController::decide()` sets
  `assigned_until` on both examiners when the AE approves. The AE's
  "Pending Evaluation" screen (`/examiner-nomination/pending-evaluation`)
  marks an evaluation complete, which clears `assigned_until` and stamps
  `last_examination_date` — the event that actually starts the 90-day gap.
- **Conflict detection, touchpoint 2** — `/examiner-nomination/conflicts`
  (AE) compiles nominations by the examiner's faculty and flags any examiner
  nominated by more than one department. Read-only; never auto-rejects.
- **Examiner admin** — `/examiners` (Non-Exec CGS): add an examiner, toggle
  Unavailable. Internal and external are kept as two lists behind a tab
  strip, because CGS keeps two spreadsheets and the external one carries nine
  columns the internal one has no equivalent of: faculty approval reference,
  institution, technical or research, UTP cluster, area of expertise, years
  of experience, MSc and PhD graduates supervised, and the first examination
  date. All nullable, all external only. `institution` is required for an
  external examiner, and `store()` strips `Examiner::EXTERNAL_FIELDS` when an
  internal one is saved, so an internal row can never hold half an external
  record. The add form is a **two-step wizard** (Examiner / External record)
  — the external columns took it to thirteen fields, past the eight
  `docs/conventions.md` puts the line at. For an internal examiner the
  external block is `hidden` **and** `disabled`: a disabled fieldset submits
  none of its controls, so a half-typed external record cannot reach the
  controller at all, and the generated review cannot list values that are not
  going to be saved. `Arr::except` in `store()` stays as the guard that does
  not depend on the browser.
  Three columns on the CGS sheets are deliberately *not* stored:
  "Date 2nd" is `last_examination_date`, which already drives the 90-day gap;
  "Student Name" is derived from `examiner_nominations`; and "Remark" is what
  `unavailable_reason` already shows.
- **Re-viva monitoring** — CGS Staff logs the re-corrected thesis once it
  reaches them (`reviva.create`, Non-Exec CGS, with an eligibility-aware
  student picker like the examiner dropdown), which stamps `resubmission_at`
  and computes the 6-month correction / 1-year hardbound deadlines. Not a
  student self-service upload — see `ReVivaWorkflow`'s docblock. The AE
  advances a 4-stage stepper (Report sent → Under panel review → Report
  received → Consolidation scheduled), then records the 5-level outcome on a
  separate screen (`/re-viva/outcomes`) that never touches
  `applications.status`. A level-4 outcome doesn't loop the Stage graph; it
  opens the door for CGS to log the next cycle, which links back to the
  prior one via `re_viva_details.previous_cycle_id`.

## Still to build

Nothing from the original TODO list remains unscoped. Candidates for
follow-up, not currently tracked as required:
- A student-facing view of their own re-viva cycle history (currently they
  only see the generic tracking page's stepper for whichever cycle is open).
- Automated reminders as correction/hardbound deadlines approach — `TODO.md`'s
  cross-cutting section notes scheduled commands are still a gap generally.

## Tests

`tests/Feature/Hani/` — 16 cases.

| File | Guards |
|---|---|
| `ExaminerNominationTest` | that a final approval ties up both nominees, and that a rejection leaves the pool untouched |
| `ExaminerPoolTest` | the internal/external split: which columns each list shows, tab-scoped counts, the derived student column, the external-field strip on an internal save, `institution` being required for an external examiner, and the add form's stepper contract (two steps, external block disabled for an internal examiner) |
| `ReVivaTest` | the four-stage stepper and the level-based outcome |

Run them with `./vendor/bin/sail artisan test --filter=Hani`.

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
