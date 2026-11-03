# Changelog: Hani's modules

## 2026-11-03: Examiner Matching & Pool: automation and intelligence (Prompt 1)

Branch `feature/hani-automation`. Commits: `45afa54` to `61138a2`.

### Problem

The supervisor's feedback was that the system lacked automation,
intelligence and real technology integration. In practice, the Academic
Executive and CGS staff still did most of the examiner work by hand:

- **The pool list:** Puan Waheeda kept it in Excel, and re-typing it into the
  portal was the only way in.
- **Choosing examiners:** supervisors picked from a bare dropdown, with no
  help finding someone whose expertise fits the thesis.
- **Conflicts:** they were checked by memory, with nothing pre-checked
  against UTP/CGS/026.
- **Duplicates:** the same examiner appeared under two spellings, and nothing
  noticed.
- **Cross-department clashes:** these surfaced only on one screen, late in
  the chain.
- **Declines:** they came back on paper or by email and were recorded nowhere.
  Backups were tied up for 180 days even though letters go to mains only.
- **Hand-off:** Puan Waheeda emailed Encik Syahmi an Excel file of the final
  list.
- **Chasing:** nobody was reminded to close out an evaluation after the viva.

### Decisions

- **Roles (confirmed by CGS practice).** The Senior Executive CGS compiles,
  finalises **and** releases the list, and maintains the pool. The
  Non-Executive CGS is read-only here and is notified on release.
  - `cgs_release` keeps its key and is relabelled.
  - One Approve at `cgs_final` records both stages.
- **Mains only.** CGS confirmed that appointment letters go to the two main
  examiners, so only they are tied up. Backups stay on standby.
- **Departments** are mapped to Core's canonical names through a provisional
  alias list, and an unmappable **internal** row is rejected. An **external**
  row keeps its own department as written, because the template's external
  departments belong to other institutions (for example "Department of
  Structures and Materials"). Rejecting those would have rejected every
  external examiner in the template.
- **Intelligence stays local and explainable.** TF-IDF with cosine similarity
  runs in plain PHP, with no external AI service. Every suggestion names the
  terms it matched on, and every flag names its evidence.
- **Never block, except the one hard rule.** A supervisor or co-supervisor as
  examiner is refused. Clashes, duplicates and the other conflict-of-interest
  rules are flagged for a person to decide.
- **Shared files are untouched.** Core contracts, the `routes/console.php`
  line, the decision-form label and the `TODO.md` updates are proposed in
  `SHARED_CHANGES.md`. The module works without any of them.

### What changed

| Item | What it does |
|---|---|
| a. Roles | Senior Executive gets "Finalise and release" (two engine decisions, one transaction, row locked) and owns the pool; Non-Executive CGS is read-only |
| b. Data model | `examiner_expertise`, `examiner_publications`; `current_utp_appointment`, `former_utp_until`; nullable email |
| c. Pool import | CGS's two-sheet layout and the UResearch template; upload → preview (New / Update / Unchanged / Possible duplicate / Rejected / Missing email) → confirm; review list; template download; Edit Examiner |
| d. Suggestions | `ExaminerMatcher` / `TfIdfMatcher`; top 5 per seat with matched terms, days since last examination and load; cached statistics keyed on the pool |
| e. Duplicates | `NameNormaliser` + `DuplicateDetector` in the pool and at `cgs_compile`; "Not the same person" |
| f. Conflict of interest | UTP/CGS/026 pre-check on suggestions, the form, every stage card and the report |
| g. Clashes | warned on submit, on the cards and in suggestions; Senior Executive notified at `cgs_compile`; Conflict Detection scoped |
| h. Declines | `PanelSeats`: decline frees the examiner, promotes an eligible backup, updates the seat columns in place, keeps `examiner_seat_history`, tells Non-Executive CGS; mains-only tie-up |
| i. `examiners:sweep` | evaluation-overdue reminders (idempotent log table, `--dry-run`), purges stale previews; scheduled from the module |
| j. Hand-off | `ReleasedExaminerProvider` (bound only once the Core contract exists); release notification; export "Application No." column |
| k. Dashboard | pool by state and department, returning in 30 days, nominations by stage, top expertise, expertise gaps; light and dark |
| l. Demo seeder | `HaniDemoSeeder` (on demand) loads the pool through the import, idempotently |

Bugs fixed along the way:
- Pending Evaluation and mark-complete were not department-scoped.
- Conflict Detection showed every department to every AE.
- The conflicted-row colour was a light-mode literal, so it stayed light in
  dark mode.

### Verification

- `php artisan test tests/Feature/Hani`: 126 passed. Full suite: green, see
  the final run in the hand-over.
- Headless Chromium walkthrough with the seeded demo pool and the standard
  test accounts. Every check passed, with no JavaScript errors or CSP
  refusals:
  1. The Senior Executive imports CGS's workbook (preview shows the mapped
     departments) and sees the Hassan Omar duplicate flag.
  2. The supervisor nominates using suggestions and sees the conflict flag on
     Noor Azlina (UTP adjunct).
  3. A second department's nomination warns of the clash.
  4. A server-side error reopens the stepper on the right step with
     `.field-error`.
  5. The list goes AE → Senior Executive (sees the clash) → Senior Director
     (one return) → AE → Senior Executive → Senior Director → Dean →
     Senior Executive "Finalise and release".
  6. The AE marks the external main declined, and the backup is promoted.
  7. The dashboard draws 5 charts in light mode and in dark mode.
