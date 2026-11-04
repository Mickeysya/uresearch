# Nur Hani Sofia Binti Mohd Azam (22001418)

**Scope:** Examiner Nomination & Matching · Conflict Detection · Re-viva Monitoring. Constraint-based rule engine.

## Built

- **Examiner pool** — `examiners` with the four-state machine (Assigned,
  On Gap, Available, Unavailable). State is *derived* in `Examiner::state()`
  rather than stored, so an examiner comes off gap automatically at 90 days
  with no job needing to run.
- **Examiner Nomination** — a supervisor names **four** seats for one of their
  own candidates: internal main and backup, external main and backup. The
  internal pair must come from the **candidate's own department**, checked in
  `store()` (no foreign key can say "the same department as the student on
  this application") and shown in the form, where picking a candidate hides
  every internal examiner from anywhere else. Touchpoint 1 (individual
  eligibility at nomination time) is enforced in the dropdown and again on
  submit.
- **The chain** (agreed 2026-09-22, `ExaminerNominationWorkflow`) —
  six stages, because what travels along it stops being one nomination after
  the first stage:

  | Stage | Who | What they do |
  |---|---|---|
  | `academic_exec` | Academic Executive | clears their own department's panels (department-scoped) |
  | `cgs_compile` | Senior Executive CGS | merges every department into one list, checks for clashes |
  | `senior_director` | Senior Director CGS | verifies, or sends it back to the department |
  | `dean` | Dean of PGR | approves, or sends it back to the department |
  | `cgs_final` | Senior Executive CGS | holds the finalised report |
  | `cgs_release` | Non-Executive CGS | releases the final list |

- **Sending a list back** — the Senior Director and the Dean do not reject a
  list they will not sign off; they return it to `academic_exec` with a
  mandatory reason (`WorkflowEngine::returnTo()`, added to Core for this).
  The candidate keeps one application and one audit trail however many times
  it loops, and the chain replays forward, so CGS re-compiles before it
  reaches the Dean again. Both the candidate and that department's Academic
  Executives are emailed.
- **The compiled report** — `/examiner-nomination/report`, readable by the
  Academic Executive (their own department only), CGS, the Senior Director,
  the Dean and the Non-Executive. Grouped faculty → department → candidate,
  with cross-department clashes flagged inline. `?format=xlsx` or `csv` on
  `/examiner-nomination/report/export` is the same rows as a file, off one
  `ExaminerListExport`, scoped by the same query as the screen — an Academic
  Executive cannot export a department they cannot read.
- **Examiner lifecycle** — `ExaminerNominationController::decide()` ties up
  every seat on the panel, but **only when the chain finishes**, not at the
  first approval: six stages now sit between the supervisor and the release,
  and an early tie-up would hold four examiners for 180 days while the list
  was still being argued over. The AE's "Pending Evaluation" screen
  (`/examiner-nomination/pending-evaluation`) marks an evaluation complete,
  which clears `assigned_until` and stamps `last_examination_date` — the
  event that actually starts the 90-day gap.
- **Conflict detection, touchpoint 2** — `/examiner-nomination/conflicts`
  (AE) compiles nominations by the examiner's faculty and flags any examiner
  nominated by more than one department. Read-only; never auto-rejects. The
  same check runs inline on the compiled report, which is where CGS sees it
  at the `cgs_compile` stage.
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
- **Re-viva monitoring** — rebuilt around CGS practice in Prompt 3; see
  "Re-viva Monitoring: what the system does" below. The Senior Executive
  logs a round, the AE runs the four stages (stage 1 sends the correction
  letters), the outcome is suggested from the examiners' UTP/CGS/024
  reports, and a level-4 outcome opens round N+1, linked by
  `re_viva_details.previous_cycle_id`.

## Examiner emails (who keeps them current)

- **Non-Executive CGS** reads the pool and may change an examiner's email,
  and nothing else. A reason is required.
- **The Senior Executive** edits everything on the full form.
- **Every change is logged** in `examiner_contact_history`, shown on the
  examiner page (`/examiners/{id}`), whether it came from the form, the
  email-only edit, an import or a confirmed proposal.
- **Report email not working:** the AE (own department, or its students'
  panels), Non-Exec or the Senior Exec. The email is flagged Invalid, CGS is
  notified, and invitations, panel reminders and the appointment-letter feed
  skip the examiner until a new email is saved.
- **Freshness:** `email_confirmed_at` is set on add, change or "Confirm email
  still valid". The pool's Email filter and the dashboard tiles list missing
  or invalid emails, and emails not confirmed in
  `hani.examiner_contact.stale_months` (12, provisional).
- **At nomination:** a supervisor picking an examiner with no working email
  can give the current one and a CV. CGS confirms it from "Proposed emails
  to confirm" in the pool. A working email is never replaced automatically.

## Examiner suggestions (how the matching works)

The nomination form lists **Suggested examiners** for each seat type, top 5,
each with its score, the terms it matched on ("matched on: anomaly detection,
time series"), how long since the examiner last examined, and any
conflict-of-interest flag. One click fills a seat; the dropdowns stay free.

**Method: TF-IDF with cosine similarity, in plain PHP** (`Support/Matching/`).
No external AI service, and nothing leaves the server.

1. **Documents.**
   - *Examiner:* area of expertise, expertise keywords and publication titles.
   - *Thesis:* the title, plus the abstract and keywords once the Notice of
     Thesis Submission carries them. Until then it falls back to the
     nomination's title.
2. **Terms** (`Tokeniser`, `Stemmer`): lower case, stop words removed
   (including words every thesis title has, such as "study" and "approach"),
   and a light suffix-stripper so "detection" meets "detecting".
   - A multi-word keyword ("anomaly detection") is also kept whole as a
     phrase term.
   - A title's adjacent word pairs count as phrases only where some examiner
     has that phrase.
3. **Weights.**
   - Term frequency is log-scaled (1 + ln tf).
   - Inverse document frequency, ln((N+1)/(df+1)) + 1, is taken over the
     **eligible** pool, so a word everyone shares counts for little and a rare
     one counts for a lot.
   - Phrases are boosted by `hani.matcher.phrase_boost`.
4. **Score:** the cosine of the two vectors, shown as 0–100.
5. **Explanation:** the overlapping terms, ordered by their share of the score.
   A matched phrase stands in for its own words.
6. **Who is ranked:** only examiners who could be nominated now. That means
   Available, and for the internal seats from the candidate's own department
   (`Support/Departments`). A suggestion is therefore never something the form
   would refuse.
7. **Tie-breakers** (`hani.matcher.weights`, provisional): fewer main-seat
   examinations in the last 12 months, longer since the last examination, and
   for external examiners years of experience. These spread the load without
   overriding expertise.
8. **Cache:** the term statistics are cached under a key built from the pool
   itself, plus today's date because gaps run out:
   - examiners
   - expertise keywords (which touch their examiner)
   - publications (which also touch their examiner)

   Any change produces a new key, and the table is rebuilt on next use.

**Who sees it:**
- supervisors, for their own candidates
- Academic Executives, for their own department
- the Senior Executive CGS, for anyone

Never students (`ExaminerSuggestionController`).

**Where an embeddings model would plug in.** `ExaminerMatcher` is the seam.
A sentence-embedding model run locally (for example a small multilingual
MiniLM served over HTTP on the same host) would implement
`rank($query, $candidates)`:
- embed the thesis and each examiner document
- cache the examiner vectors under the same pool-version key
- score by cosine similarity

Bind it in `ModuleProvider::register()` instead of `TfIdfMatcher`. Keep the
explanation: report the expertise keywords closest to the thesis, so a score
is never shown without its reason.

## Viva Voce: what the system checks and chases

All settings are in `config/hani.php`. Values CGS has not confirmed are marked
"Provisional" there.

- **One page:** the eligibility checklist and the Notice of Thesis
  Submission are on `/viva/eligibility`. The abstract and keywords are the
  examiner suggestions' query document.
- **Candidacy period:** checked by the system from the candidature start
  date (`Support/Eligibility/CandidacyPeriodSource`, read-only). The other
  items are self-declared because no data source exists for them.
- **Softbound System checks:**
  - `Support/SimilaritySource` reads the ULearn Turnitin report.
  - `Support/Pdf/PdfReader` is the built-in reader; smalot is used if
    installed.
  - `Jobs/RunSoftboundChecks` checks both PDFs. The AE sees Pass /
    Warning / Not checked, each with its evidence.
- **Examiner progress:** `/viva/{notice}/examiners` (AE, Senior Exec).
  - A rejection promotes the backup.
  - Reports are stored privately, never in `DocumentStore`.
  - Diverging categories are flagged before the viva.
- **Scheduling:** clash, weekend and public-holiday warnings
  (`Support/VivaSlotFinder`), with the next free weekday slots.
- **Step 5:** `/viva/results` (AE) records the chairman's report and the
  category, sets the deadline, and closes the examiners' evaluation (or
  holds them for a re-viva). Students upload corrections at
  `/viva/corrections`.
- **`viva:reminders`:** runs daily from the module, sends each reminder
  once, and has `--dry-run`. It covers:
  - examiners who have not replied
  - overdue reports
  - the 7-day and 1-day panel reminders, with the calendar file
  - the softbound deadline
  - the corrections deadlines
  - the category 4 window closing

## Re-viva Monitoring: what the system does

Settings in `config/hani.php` under `reviva`; unconfirmed values are marked
"Provisional".

- **Roles.** Senior Executive: logs rounds (`/re-viva/new`), reads Re-viva
  Cycles. Non-Executive CGS: reads Re-viva Cycles and downloads the
  staff-only files (a return contact on the letter), and gets 403 on every
  action. The AE runs the stages and the outcome for their own department.
- **Viva hand-off.** A category-4 viva (or a level-4 round) appears under
  "Expected resubmissions"; the round links the viva and stores its window.
  Logging outside the window warns, never blocks. Legacy students: the
  Senior Executive enters the viva result date.
- **Stage 1, Send to panel** (`Support/ReVivaLetters`): a Dompdf "Correction
  of Master's/Doctoral Thesis" letter per current main examiner
  (`Resources/views/re_viva/pdf/correction_letter.blade.php`, styles in one
  block), stored staff-only, emailed with a signed link (no login, expiring,
  throttled) to the corrected thesis and Summary of Corrections. The link
  works for `hani.reviva.link_days` from when the email is built, not from a
  backdated send. `Http/Middleware/ValidPanelLink` answers a tampered link
  with 403 and an expired one with a 410 page giving the CGS contacts.
  Flagged emails are skipped and named. The stage completes in the same
  action.
- **Stage 2.** Working days (`Support/WorkingDays`, the public-holiday list)
  with the panel; 20 → AE reminded, 25 → Senior Executive told. "Resend
  letter" (AE of the department, Senior Executive) emails an examiner the
  same letter with a fresh link; every send is logged in
  `re_viva_letter_sends` and shown as "last sent" per examiner.
- **Stage 3.** Each main examiner's UTP/CGS/024: category 1–4, "viva again"
  for a fail, signed form (staff-only), optional ratings. Both are needed.
  `ExaminerDivergence::betweenReEvaluations` flags disagreement.
- **Outcome** (`Support/ReVivaOutcome`): suggested when the examiners agree
  (1→1, 2→2, 3→3, 4+YES→4, 4+NO→5), otherwise "Consolidation needed"; the AE
  confirms. Final thesis due 1/3/6 months after; levels 1–3 and 5 complete
  the mains' evaluation (the gap starts); level 4 holds them for round N+1.
  Students and supervisors never see examiner names or recommendations.
- **Corrections accepted** (`Support/ReVivaCorrections`): the student
  uploads the final thesis on My Re-viva; the AE accepts; the Confirmation
  of Correction is generated pre-filled, staff-only.
- **`re-viva:monitor`**: daily, once per alert, `--dry-run`. Panel limits,
  window and final-thesis reminders, overdue flags, level-4 windows closing.
- **Risk score** (`Support/ReVivaRisk`): 0–100 from time used, time in
  stage, divergence and earlier rounds, each factor shown; Re-viva Cycles
  sorts by it. Rule-based, not a prediction.
- **Digital Summary of Corrections** (`Support/SummaryOfCorrections`): the
  student lists each examiner's comments, actions and pages; the system
  checks every comment has an action and every page exists in the
  corrected thesis (built-in PDF reader), then renders the PDF, which the
  Senior Executive can attach at logging.

## Examiner Report export layout (for Jason's appointment letters)

`/examiner-nomination/report/export?format=xlsx|csv`. There is one row per
candidate, one sheet, and the same columns in both formats
(`Exports/ExaminerListExport.php`).

| # | Column | Notes |
|---|---|---|
| 1 | **Application No.** | `applications.id` = `examiner_nominations.application_id`. The key for reading the seat ids exactly. Jason's import does not recognise this heading yet (its aliases lack the full stop); he is adding an `application no.` alias on his side. Until then it falls back to matching by name. |
| 2–6 | Faculty, Department, Candidate, Matric No, Programme | the candidate |
| 7–8 | Thesis Title, Supervisor | |
| 9–11 | Internal Main, Internal Main Dept, Internal Backup | the **current** panel, after any decline |
| 12–14 | External Main, External Main Institution, External Backup | the **current** panel |
| 15–17 | Stage, Status, Submitted | |
| 18–19 | Internal Main Email, External Main Email | blank where CGS has no email yet |

**Stability rule:** columns are only ever added at the end; none is renamed,
reordered or removed. Code-level access is the proposed Core contract
`SuppliesReleasedExaminers` (SHARED_CHANGES.md, item 3), implemented by
`Support/ReleasedExaminerProvider`. It returns released panels only and is
bound automatically once the contract exists.

On release, every Non-Executive CGS user gets "New examiner list released"
(email and in-app). Letters go to the two **main** examiners only. Backups
stay on standby, and a decline promotes one (`Support/PanelSeats`), which
notifies Non-Executive CGS again with the name to send the letter to.

## Still to build

Nothing from the original TODO list remains unscoped. The re-viva student
page and deadline reminders listed here before are built (Prompt 3).
Candidates for follow-up:
- The real signatory and CGS contacts in `hani.reviva.letter` (fictional
  for now).
- A staff-only flag in Core so the re-viva files can move into
  DocumentStore (SHARED_CHANGES.md item 12).

## Demo data (`HaniDemoSeeder`)

Fictional, on demand only, never called from `DatabaseSeeder`:

```bash
docker exec uresearch-app php artisan db:seed --class="App\Modules\Hani\Database\Seeders\HaniDemoSeeder"
```

Run it after `migrate`, and again whenever the demo should look fresh.
**Every date is relative to the day it runs:**
- The dataset's dates (`Database/demo/hani_demo_dataset.json`) are written
  as of `dates_relative_to` and moved to today, so the On Gap examiners stay
  On Gap.
- Each run rebuilds the demo students' viva, softbound and nomination
  records from scratch, so S07's viva is always 7 days away.
- Running it twice gives the same picture.
- It never changes the shared test accounts or any other module's records.

**Accounts.** The demo students and supervisors are `@example.com`, with
password `password`, like the test accounts. Their approvers are the shared
test accounts:
- the candidate's department AE, for example `ae@utp.edu.my` for Computing
  and `ae.petroleum@utp.edu.my` for Petroleum Engineering
- `seniorexec@`, `director@` and `dean@`

| Student | Email | Programme / department | Stage |
|---|---|---|---|
| S01 Aiman Hakim | `aiman.roslan@example.com` | MSc FT, Computing | Not started: the full live walkthrough (MSc) |
| S02 Nurin Batrisyia | `nurin.azlan@example.com` | PhD FT, Petroleum Eng. | Notice waiting for the AE: the live walkthrough (PhD) |
| S03 Harith Iskandar | `harith.z@example.com` | MSc PT, Chemical Eng. | Nomination at CGS compile; clash on Firdaus Kamarudin with S04 |
| S04 Siti Khadijah | `khadijah.mazlan@example.com` | PhD FT, Civil & Env. Eng. | Nomination at the Senior Director; "Hassan bin Omar" vs S10's "Hassan Omar, PhD" duplicate |
| S05 Amirah Sofea | `amirah.kamal@example.com` | MSc FT, Management | Released; softbound returned (Turnitin receipt 45 days old: System checks warn) |
| S06 Lee Jia Hui | `lee.jiahui@example.com` | PhD PT, Mechanical Eng. | Ready to schedule; examiner reports diverge (Internal 2, External 4: major) |
| S07 Muhammad Danish | `danish.faisal@example.com` | MSc FT, Electrical & Electronics Eng. | Viva in 7 days: `php artisan viva:reminders` sends the 7-day reminder |
| S08 Tan Yi Xuan | `tan.yixuan@example.com` | MSc FT, Computing | Viva 14 days ago, result category 2; uploads corrections, then the AE accepts |
| S09 Nur Aleesya | `aleesya.hisham@example.com` | PhD FT, Applied Sciences | Category-4 viva about 7½ months ago; re-viva round 1 Under Panel Review for 22 working days, so the AE has been reminded to chase the panel |
| S10 Kavitha Selvaraj | `kavitha.selvaraj@example.com` | MSc PT, Chemical Eng. | Category-4 viva; re-viva round 1 finished (internal 3, external 4 + viva again), consolidated to level 4 three days ago; the Senior Exec logs round 2 |

**History for Process Analytics (H01-H24).** Twenty-four further fictional
candidates (`history.h01@example.com` to `history.h24@example.com`, password
`password`) each have one *completed* journey, finished between 5 and about
330 days before the run day: notice, the six-stage nomination chain (lists
H03, H08, H13, H18 and H23 sent back once by the Senior Director or the
Dean), softbound (H05, H11, H17 and H23 returned once), both examiner
reports (PhD slower, some late), viva, result and corrections accepted. H04,
H10, H16 and H22 are category 4 and go through a re-viva round to an outcome
(levels 2, 3, 1 and 5). Durations are varied but fixed per case, so the picture is the
same every run relative to today. This step sends no email and puts every
examiner's `assigned_until` and `last_examination_date` back afterwards, so
the live scenarios above see the same pool.

The dataset says S03's clash partner is S10. Both are in Chemical
Engineering, though, and a clash is by definition across departments, so
the clash is with S04 instead. Every chain runs through `WorkflowEngine`;
only Hani's own tables are written directly.

## Tests

`tests/Feature/Hani/`: 218 tests. Re-viva (Prompt 3): `ReVivaRolesTest`,
`ReVivaHandoffTest`, `ReVivaLettersTest`, `ReVivaMonitorTest`,
`ReVivaEvaluationsTest`, `ReVivaOutcomeTest`, `ReVivaCorrectionsTest`,
`ReVivaRiskTest`, `ReVivaSummaryTest`, with fixtures in
`Concerns/BuildsReVivas`. Examiner email upkeep is covered by
`ExaminerContactTest`. The viva files added in Prompt 2:
`VivaBulkSafetyTest`, `VivaEligibilityVerificationTest`,
`VivaSoftboundChecksTest`, `VivaExaminerProgressTest`,
`VivaSchedulingAssistTest`, `VivaResultTest`; fixtures are in
`tests/Feature/Hani/Concerns/` (`BuildsVivas`, `FilesVivaNotices`).

| File | Guards |
|---|---|
| `ExaminerNominationTest` | the four-seat panel, the internal-examiner department rule, the internal/external seat types, that only a FINISHED chain ties the panel up, the Senior Director's and the Dean's return-to-department path (including that a reason is required and that the first stage cannot send anything back), and that the compiled report is scoped by department for an Academic Executive and forbidden to a student |
| `ExaminerPoolTest` | the internal/external split: which columns each list shows, tab-scoped counts, the derived student column, the external-field strip on an internal save, `institution` being required for an external examiner, and the add form's stepper contract (two steps, external block disabled for an internal examiner) |
| `ReVivaTest` | one open round at a time and the level-4 loop back |

Run them with `docker exec uresearch-app php artisan test tests/Feature/Hani`
(the path, not `--filter=Hani`, which also matches Norhanis's tests).

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
   `WorkflowEngine::submit()`, `::decide()` or `::returnTo()`.

See `docs/adding-a-module.md` for the full walkthrough.
