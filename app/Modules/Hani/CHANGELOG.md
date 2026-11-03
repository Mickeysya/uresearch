# Changelog: Hani's modules

## 2026-11-03: Viva Voce Assessment: automation and intelligence (Prompt 2)

Branch `feature/hani-automation`. Commits: `9da3394` to `59027e7`.

### Problem

The viva was a set of forms with the checking still done by hand:

- **Eligibility:** every item was a self-declared tick, including the
  candidacy period, which UResearch can check from the candidature start
  date.
- **Two pages:** the checklist and the Notice of Thesis Submission were
  separate pages, and the notice gave the examiner suggestions nothing to
  match on beyond the title.
- **Softbound:** the AE opened every Turnitin report and thesis PDF to check
  the similarity figure, the title and the word count against the form.
- **Examiners:** acceptance slips and reports came back on paper or by
  email and were recorded nowhere. Nobody was chased when an examiner went
  quiet or a report was late, and disagreeing reports surfaced at the viva
  itself.
- **Scheduling:** clashes, weekends and public holidays were checked from
  memory, and reminders were sent by hand.
- **Step 5:** the result and corrections were not built, so the viva
  tracker never completed.
- **Bulk actions:** the shared queue's bulk decide skipped the supervisor
  email on acknowledgement and the mandatory reason on a softbound return.

### Decisions

- **Enhance, do not rebuild.** No new stages and no new module keys. The
  examiner progress, the result and the corrections are module-side state
  on Hani's own tables.
- **Explainable, local intelligence.**
  - Every system check says what it found ("Read: 12% similarity index …",
    "Examiner X is already at Y's viva at …").
  - Nothing is sent to an outside service.
  - Nothing blocks except the confirmed hard rules: candidacy period, 25%
    similarity and the word limits.
- **PDF reading (confirmed with the team).** No `smalot/pdfparser` for now.
  The module's own reader (`Support/Pdf`) handles:
  - Flate and object streams
  - standard encodings
  - ToUnicode maps

  It reads the fictional fixtures reliably. When it cannot read a file, the
  panel says "Not checked automatically" and the student's declared figure
  is used. smalot is picked up automatically if it is ever installed.
- **Examiner reports stay staff-only.** Core shows a student every
  `DocumentStore` file on their applications, so examiner reports are
  stored on the private disk:
  - with random names
  - outside `DocumentStore`
  - downloaded through an AE- and Senior-Exec-only route
  - logged with `activity('document')`, as `DocumentStore` does

  A staff-only flag is proposed in SHARED_CHANGES.md.
- **Category 4 (confirmed).** The main examiners stay Assigned for the
  re-viva and are released by its outcome (Prompt 3). If the resubmission
  window closes with no re-viva logged, they are freed with no gap and the
  AE is told.
- **Student view (confirmed).** Students see the final result category,
  their deadline and the chairman's viva report. They never see examiner
  names or the examiners' own categories.
- **Re-viva logging (confirmed).** The Senior Executive can log re-vivas
  (`/re-viva/new?student=` pre-selects the student). Non-Executive keeps
  access until Prompt 3.

### What changed

| Item | What it does |
|---|---|
| a. Bulk safety | `viva_voce` and `viva_softbound` implement `DecidesOneAtATime` |
| b. One page | Checklist and notice on `/viva/eligibility`, one submit; abstract (100–500 words) and 3–8 keywords feed the examiner suggestions; `/viva/notice` redirects |
| c. Verification | `EligibilitySource` seam: `CandidacyPeriodSource` reads `candidacies` (guarded, read-only). Items show Verified by system / Self-declared / Not met. Results are snapshotted on the notice. The AE sees a summary, and Acknowledge confirms it |
| d. Softbound checks | `SimilaritySource` (uploaded report / Turnitin API stub / simulated). The form pre-fills the index from the chosen report. 25% enforced on the figure read. A queued `RunSoftboundChecks` job feeds the "System checks" panel: real PDF, encryption, text, pages, word count, title, mismatch, sources above 5%, exclusions, report age |
| e. Examiner progress | `viva_examiner_progress`: letter → accepted / rejected → thesis → report with category, optional ratings and a private report. A rejection calls `PanelSeats::decline`. Divergence alert (major when 1–3 vs 4–5), with a neutral line for the chairman only. `viva:reminders`: no-reply chaser (14 days), overdue report (4 / 6 weeks) |
| f. Scheduling | Clash, weekend and holiday warnings with an override; the next 3 free weekday slots. Reminders: panel with `.ics` 7 days and 1 day before, softbound nudge at 14 days, AE told when the date passes |
| g. Step 5 | Viva Results page: chairman's report and category. Deadlines 1 / 3 / 6 months, or the +6 to +12 month window. Examiner housekeeping. Corrections upload and acceptance (tracker completes). Reminders at 30 and 7 days, then overdue. Category 4 goes to the Senior Exec; category 5 is a cross |
| h. Demo seeder | Every date relative to the run day (`dates_relative_to`). The 10 demo students at their stages through the real engine, rebuilt each run |

Bugs fixed along the way:
- Viva notifications did not call `afterCommit()` explicitly. They now do.
- After a demo walkthrough, the demo pool could stay On Gap (the import
  never moves dates backwards). The seeder now restores the dataset dates.

### Verification

- `php artisan test tests/Feature/Hani`: 163 passed. Full suite: 599
  passed.
- Re-seeded, then a headless Chromium walkthrough with one MSc student (S01)
  and one PhD student (S02). Every check passed, with no JavaScript errors:
  1. Eligibility and notice on one page. The server refuses a 60-word
     abstract and keeps the input. The AE sees the eligibility summary, with
     no bulk checkboxes, and acknowledges.
  2. Form #019, MSc. A 31% report is flagged in the browser and refused by
     the server, and the form reopens on step 2. The 12% report is read and
     pre-filled.
  3. Form #019, PhD. The ToUnicode-font report is read at 9%.
  4. System checks finish on the queue worker. The PhD's 6% source is
     warned. The AE verifies both.
  5. Examiner progress, MSc. The external main rejects, and the backup is
     promoted. Categories 2 and 4: major divergence on the examiner page,
     the scheduling list and the Set Date form.
  6. Scheduling. A slot clashing with S07 (venue and chairman) is warned;
     the AE schedules on a suggested slot.
  7. Results. MSc category 2: the student uploads corrections and the AE
     accepts ("Your viva is complete"). PhD category 4: the student sees
     the window, and the Senior Exec opens `/re-viva/new` with the student
     pre-selected.

  The panels for the walkthrough students were released with the real
  six-stage chain run off-browser (verified end to end in Prompt 1). The
  viva date was moved to yesterday so that results could be recorded.

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
