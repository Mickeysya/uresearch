# Hani's Modules: Current Behaviour

**Owner:** Nur Hani Sofia (22001418) · **Folder:** `app/Modules/Hani/`
**Up to date with:** Prompts 1 to 4 and their follow-ups, branch
`feature/hani-automation`, 4 November 2026.
**Purpose of this file:** an accurate description of how the features behave
*now*, taken from the code. It describes what is built. The history of each
change, with its reasons, is in `CHANGELOG.md`; proposed shared-file changes
are in `SHARED_CHANGES.md`.

---

## 1. Overview

Hani owns three connected modules in UResearch 2.0 (Laravel 12, the CGS
postgraduate portal for UTP), plus two features that span them:

| # | Module | Purpose | `module_type` key(s) |
|---|---|---|---|
| 1 | **Examiner Matching & Pool** | The examiner pool; supervisor nominations with explained suggestions; a six-stage approval chain; conflict, duplicate and clash checks; declines and the panel's seat history | `examiner_nomination` |
| 2 | **Viva Voce Assessment** | Eligibility and Notice of Thesis Submission → softbound thesis (Form #019) with System checks → examiner progress → scheduling → result and corrections | `viva_voce`, `viva_softbound` |
| 3 | **Re-viva Monitoring** | A re-examination round after a category-4 viva: letters to the panel, working-day limits, re-evaluations, a suggested outcome, final thesis | `re_viva` |
| – | **SLA reminders** and **Process Analytics** | Across all three: chasing stages that wait too long, and showing where the time goes | (no key; read the others) |

The real-world order is Viva → (Examiner Matching runs inside the viva) →
Re-viva (only after a category-4 result or a level-4 outcome).

### How the modules relate
- **The viva reads Examiner Matching.** A viva cannot be scheduled until the
  student's latest nomination is released. The viva's examiner progress
  works on the released panel's two main seats, and a rejection there
  promotes the backup through the same `PanelSeats` service as a decline on
  the panel page.
- **The viva result drives the panel's lifecycle.** Categories 1–3 complete
  the main examiners' evaluation (their 90-day gap starts). Category 4 holds
  them for the re-viva.
- **The re-viva follows the viva.** A category-4 result appears under
  "Expected resubmissions" for the Senior Executive, who logs the round.
  The re-viva outcome then completes or keeps holding the same mains.
- **Appointment letters** are Jason's module. Hani hands over the released
  list as an export, and through the Core contract `SuppliesReleasedExaminers`.

### Platform rules these modules follow
- Approval chains are `Stage` lists in a `WorkflowModule`. Only Core's
  `WorkflowEngine` writes `applications.status` / `current_stage` (via
  `submit()`, `decide()`, `returnTo()`).
- Each module stores its own data in its own tables. Shared tables
  (`users`, `applications`, `approval_history`, `application_documents`) are
  never altered.
- Student uploads go through Core's `DocumentStore`. Files that name or come
  from the examiners are kept staff-only on the private disk, outside
  `DocumentStore`, because Core shows a student every document on their
  applications.
- The AE is **department-scoped** everywhere: queues, reports, the
  dashboard, analytics and exports.
- Every module workflow implements `DecidesOneAtATime`, so Core's bulk
  decide cannot skip a module's own checks.
- Notifications are queued and sent after the database commit.
- No external AI or LLM service: every "intelligent" feature is plain PHP
  and explains its result.

---

## 2. Roles involved

| Role | System role key | What they do in Hani's modules |
|---|---|---|
| Student | `student` | Eligibility and notice, softbound, corrections; My Re-viva (final thesis, digital Summary of Corrections). Sees statuses, the result category and the chairman's viva report; **never examiner names, categories or staff-only files** |
| Supervisor | `supervisor` | Nominates examiners for their own students (with suggestions); proposes a missing examiner email; reads their students' viva progress |
| Academic Executive (AE) | `academic_exec` | The main operator, own department only: first stage of the chain; acknowledges notices; checks softbounds; records examiner progress; schedules vivas; records results and accepts corrections; runs the re-viva stages and outcome; Pending Evaluation; Conflict Detection; Examiner Dashboard and Process Analytics for their department |
| Senior Executive CGS | `senior_exec_cgs` | Maintains the examiner pool (the only role that full-edits and imports); compiles every department's lists; "Finalise and release"; examiner progress; logs re-viva rounds; Re-viva Cycles; resends letters; second SLA threshold; dashboards and analytics for everyone |
| Senior Director CGS | `senior_director_cgs` | Verifies the compiled list, or sends it back to the department; reads the report, dashboard and analytics |
| Dean of PGR | `dean_pgr` | Approves the list, or sends it back; reads the report, dashboard and analytics |
| Non-Executive CGS | `non_exec_cgs` | Manages the pool: adds examiners, marks them Unavailable or Active, updates emails (not full edit, import, the review list or "Not the same person"). Otherwise read-only: the released Examiner Report (notified on release), the dashboard, Process Analytics, Re-viva Cycles and the staff-only re-viva files (a return contact on the letter). Every other action is 403 |
| Examiners, chairman, co-supervisor | not system users | Receive emails (invitations, reminders, re-viva letters with signed links) |

---

## 3. Module 1: Examiner Matching & Pool

### 3.1 Examiner pool (`/examiners`, Senior Executive CGS)
- Two lists behind tabs, **Internal** and **External**. External examiners
  carry nine extra columns (faculty approval reference, institution, sector,
  UTP cluster, area of expertise, years of experience, MSc and PhD
  supervised, first examination date). Institution is required for an
  external examiner; an internal save strips the external fields.
- Expertise keywords and publications per examiner; current UTP appointment
  and "former UTP until" for the conflict-of-interest check; email optional
  ("Email needed").
- Add (two-step wizard), edit page, examiner page with contact history,
  toggle Unavailable (reason required).
- **Import** (`/examiners/import`): CGS's two-sheet workbook or the
  UResearch template → a preview (New / Update / Unchanged / Possible
  duplicate / Rejected / Missing email) → confirm. Department names map
  through `hani.departments.aliases`; an unmappable internal row is rejected.
  Unconfirmed previews are purged after 24 hours.
- **Duplicates**: names are normalised and compared; pairs at or above the
  threshold are flagged in the pool and the preview, with "Not the same
  person" to dismiss.
- **Non-Executive CGS** may add an examiner, mark one Unavailable (reason
  required) or back to Active, and change an examiner's email (reason
  required). Full edit, import, the review list and "Not the same person" stay
  with the Senior Executive.

**State is derived, not stored** (`Examiner::state()`):

| State | Condition |
|---|---|
| Unavailable | `is_active = false` |
| Assigned | `assigned_until` in the future |
| On Gap | examined within the last 90 days |
| Available | none of the above |

Only Available examiners can be nominated (`Examiner::scopeEligible`).

**Examiner emails.** Every change is logged in `examiner_contact_history`.
"Report email not working" (AE, Non-Exec, Senior Exec) marks it Invalid,
notifies CGS, and blocks invitations, reminders and the letter feed until a
new email is saved. "Confirm email still valid" refreshes
`email_confirmed_at`; emails not confirmed in 12 months are listed. A
supervisor picking an examiner with no working email can propose one (and a
CV, staff-only); CGS confirms or dismisses it. A working email is never
replaced automatically.

### 3.2 Nomination (`/examiner-nomination/new`, Supervisor)
- For one of the supervisor's own students. Four seats: internal main and
  backup (from the **student's own department**), external main and backup.
- **Suggested examiners** per seat type: top 5 by TF-IDF cosine similarity
  between the examiner's expertise, keywords and publications and the
  thesis (title, abstract, keywords from the notice). Each names the matched
  terms, days since the last examination, examinations in the last 12
  months, and any conflict flag. One click fills a seat. Only eligible
  examiners are ranked.
- **Conflict of interest** (UTP/CGS/026 pre-check): supervisor or
  co-supervisor as examiner is **refused**; co-authorship, a current UTP
  appointment, left UTP under 5 years, or an "external" at UTP are warnings
  naming their evidence.
- **Clashes**: an examiner on another department's open nomination is
  warned on submit.

### 3.3 The approval chain (`ExaminerNominationWorkflow`)

| # | Stage key | Who | Decision word |
|---|---|---|---|
| 1 | `academic_exec` | Academic Executive (own department) | approved |
| 2 | `cgs_compile` | Senior Executive CGS: merges departments; sees clashes and duplicates; notified of clashes | compiled |
| 3 | `senior_director` | Senior Director CGS | verified |
| 4 | `dean` | Dean of PGR | approved |
| 5 | `cgs_final` | Senior Executive CGS | finalised |
| 6 | `cgs_release` | Senior Executive CGS, "Release to CGS operations" (recorded by the same Approve as stage 5) | released |

- **Send back to department** at stages 3 and 4 (reason required,
  `returnTo()`): one application, one audit trail; the chain replays.
- **Release** ties up the two **main** examiners for 180 days and puts the
  backups on standby; Non-Executive CGS is notified.
- **Declines** (panel page): the examiner is freed, an eligible backup is
  promoted into the seat, `examiner_seat_history` records it, and
  Non-Executive CGS is told who now needs a letter.
- **Pending Evaluation** (AE, own department): released panels whose
  evaluation is not over. Mark complete clears the tie-up and starts the
  90-day gap. A panel is over once its mains were **examined or freed**
  (read from its own seat history), so an examiner tied up by a later panel
  never reopens an earlier one.

### 3.4 Report, conflict detection, dashboard
- **Examiner Report** (`/examiner-nomination/report`): AE (own department),
  CGS, Senior Director, Dean, Non-Exec. Faculty → department → candidate,
  clashes and conflict flags inline. xlsx/csv export with the same scope;
  column 1 "Application No." is the key for Jason's letters; columns are
  only ever added at the end. Each released seat shows its letter status ("Not
  sent", "Sent <date> (letter module)", "Sent <date> (entered by AE)", "Replaced
  <date>"), counting both sources with Jason's date winning, and a "Letters: 2/2
  sent" badge per candidate.
  The download defaults to "Awaiting letters" (released, not yet examined on, a
  main seat not sent; only those seats flagged); "Full list" is every row.
- **Conflict Detection** (`/examiner-nomination/conflicts`, AE own
  department, Senior Exec, Senior Director, Dean): examiners nominated by more
  than one department on **open** nominations (pending, or released and not
  yet examined). Flags, never rejects. Each number links to wherever the viewer
  can open that list (queue card, panel, or report row; an AE gets no link to
  another department's). A sole released list is marked as keeping the
  examiner, with Manage panel → Replace. On a pending list, "Ask department to
  substitute" (required reason) is offered to whoever holds it at cgs_compile,
  senior_director or dean. From the last two it also sends the list back. The
  candidate's AE and supervisor are notified and replace the seat on a page
  with suggestions, while the list is at academic_exec or cgs_compile. The
  reason and the examiner stay out of the return remarks, because the engine
  sends those to the candidate.
- **Possibly the same person** (Senior Exec, on that page): one line per pair
  with its applications. "Not the same person" dismisses the pair. "Same person:
  merge" (`Support/ExaminerMerger`) picks the record to keep; the other goes
  Unavailable, "Duplicate of ...", and is never deleted. Pending and released
  seats, viva progress, re-viva letters and evaluations, tie-up and gap,
  expertise, publications and blank details move across, logged in the seat
  history, `examiner_merges` and the activity log. Refused if the kept record
  is Unavailable, is the other type, or could not sit on one of the panels.
- **Examiner Dashboard** (`/examiner-dashboard`): pool by state and
  department, returning to Available in 30 days, nominations by stage,
  email upkeep tiles, top expertise, expertise gaps against recent theses.
  Links to Process Analytics.

### 3.5 What the student sees
Only the nomination's status ("Examiner nomination in progress", "Examiners
appointed", "Examiner nomination not approved"). Never names.

---

## 4. Module 2: Viva Voce Assessment

### 4.1 End-to-end flow (MSc by Research and PhD; MSc by Coursework has no viva)

| Step | Who | In UResearch |
|---|---|---|
| 1. Requirements met | Student | Eligibility checklist (candidacy verified by the system) |
| 2. Notice of Thesis Submission | Student | Same page; creates `viva_voce` |
| 3. AE contacts supervisor | AE | Acknowledge → supervisor emailed to nominate |
| 4. Examiners | Supervisor, chain | Module 1 |
| 5. Softbound (Form #019) | Student → AE | `viva_softbound`, with System checks |
| 6. Examiner progress | AE | letters, acceptance, thesis, reports, divergence |
| 7. Viva date | AE | Scheduling, `.ics` invitations, reminders |
| 8. Result and corrections | AE, Student | Viva Results; corrections upload and acceptance |

### 4.2 Eligibility and notice (`/viva/eligibility`, Student)
- One page: the Handbook checklist (MSc / PhD items, part-time and
  full-time from `users.programme`) and the notice (title, abstract 100–500
  words, 3–8 keywords, expected softbound date at least 3 months ahead,
  publication proof PDF). One submit. `/viva/notice` redirects here.
- "Within candidacy period" is **verified by the system** from Chloe's Study
  Candidacy record (expiry date with extensions, status), read-only and
  guarded; without a record it is self-declared. Each item shows Verified by
  system / Self-declared / Not met. The result is snapshotted on the notice.
- The page is the student's viva home: a step tracker and the next action.

| Stage key | Who | Decision word | Queue title |
|---|---|---|---|
| `notice_received` | Academic Executive | acknowledged | Notices Received |

The AE sees the eligibility summary; Acknowledge confirms it and emails the
supervisor to nominate examiners.

### 4.3 Softbound thesis, Form #019 (`/viva/softbound`, Student)
- Its own application, linked to the notice; four steps mirroring the real
  form. Similarity ≤ 25% and word limits (MSc 50,000 / PhD 100,000) are
  enforced on the figure **read from the Turnitin report** where it can be
  read.
- **System checks** (queued `RunSoftboundChecks`): real PDF, not encrypted,
  has text, page count, word count against the declared figure, title on the
  first pages, declared vs read similarity, any source above 5%, exclusions,
  report age (Digital Receipt). Each shows Pass / Warning / Not checked with
  its evidence. Similarity source: uploaded report (default), Turnitin API
  stub, or simulated.

| Stage key | Who | Decision word | Queue title |
|---|---|---|---|
| `softbound_check` | Academic Executive | verified | Theses to Check |

Reject returns it to the student (reason required); they file again.

### 4.4 Examiner progress (`/viva/{notice}/examiners`, AE, Senior Exec)
- Per main seat: letter sent (manual, or from Jason's dispatch once bound),
  accepted / rejected (a rejection promotes the backup), thesis received,
  report received with category 1–5, optional ratings and the report file
  (staff-only, private disk).
- **Divergence**: minor when categories differ; major when one is a pass
  (1–3) and the other re-examination or fail (4–5). Shown on the examiner
  page, the scheduling list and the Set Date form. The chairman's
  invitation carries a neutral line only.

### 4.5 Scheduling (`/viva/scheduling`, AE)
- Ready to Schedule (softbound verified and nomination released), Scheduled,
  Waiting (says what is missing).
- Clash warnings (an examiner, the chairman, the supervisor or the venue
  within 2 hours of another viva), weekend and public-holiday warnings, with
  an override and the next 3 free weekday slots.
- Invitations with an `.ics` file to the student, supervisor, mains,
  chairman and co-supervisor; a reschedule moves the same calendar event.

### 4.6 Result and corrections (`/viva/results`, AE; `/viva/corrections`, Student)
- After the viva: the chairman's report (shared with the student) and the
  category. Categories 1–3: corrected thesis due 1 / 3 / 6 months after the
  viva; the mains' evaluation completes. Category 4: resubmission window
  +6 to +12 months; mains held; the Senior Executive is asked to log the
  re-viva. Category 5: final.
- The student uploads corrections; the AE accepts; the tracker completes.

### 4.7 `viva:reminders` (daily 07:45, `--dry-run`)
Each once: examiner no reply (14 days) and report overdue (4 / 6 weeks) →
AE; panel reminders 7 days and 1 day before, with `.ics`; softbound nudge at
14 days → student, overdue → AE; corrections 30 and 7 days before → student,
overdue → AE; category-4 window closed with no re-viva → mains freed, AE
told.

---

## 5. Module 3: Re-viva Monitoring

**Logging** (`/re-viva/new`, Senior Executive only): "Expected
resubmissions" lists category-4 vivas and level-4 rounds with nothing
logged. The round links the viva and stores its window (round 1 from the
viva; round N+1 [0, 6] months from the level-4 outcome). Outside the window
it warns and flags, never blocks. Uploads: corrected thesis and Summary of
Corrections, or the student's checked digital summary.

**Stages** (AE, own department):

| # | Stage key | What happens |
|---|---|---|
| 1 | `report_sent` | **Send to panel**: a Dompdf correction letter per current main, staff-only, emailed with a signed link to the thesis and summary (works 35 days from when the email is built; tampered → 403, expired → 410 with the CGS contacts). Flagged emails are skipped and named. Completes in the same action |
| 2 | `under_panel_review` | Working days with the panel counted; `re-viva:monitor` reminds the AE at 20 and tells the Senior Exec at 25. **Resend letter** (AE, Senior Exec) with a fresh link; every send logged |
| 3 | `report_received` | Each main's UTP/CGS/024: category 1–4, "viva again" for a fail, signed form (staff-only), optional ratings. Both required |
| 4 | `consolidation_scheduled` | Divergence alert when the two map to different levels |

**Outcome** (`/re-viva/outcomes`): suggested when the examiners agree
(1→1, 2→2, 3→3, 4+YES→4, 4+NO→5), otherwise "Consolidation needed"; the AE
confirms. Levels 1–3: final thesis due 1 / 3 / 6 months; the mains'
evaluation completes. Level 4: mains held; the Senior Exec logs round N+1.
Level 5: final. Students and supervisors are told the level and next step,
never examiner details.

**After the outcome:** the student uploads the final thesis on My Re-viva;
the AE clicks Corrections accepted, which generates the pre-filled
Confirmation of Correction (staff-only).

**Re-viva Cycles** (`/re-viva/cycles`, Senior Exec and Non-Exec,
read-only): every round grouped by state, with a rule-based **risk score**
(0–100 from time used, time in stage, divergence and earlier rounds; each
factor shown).

**Digital Summary of Corrections** (student): every comment needs an action,
every page cited must exist in the corrected thesis (built-in PDF reader);
renders a PDF the Senior Exec can attach at logging.

**`re-viva:monitor`** (daily 08:00, `--dry-run`): panel limits; window and
final-thesis reminders at 30 and 7 days; final thesis overdue → AE and
Senior Exec; level-4 window closed → mains freed.

---

## 6. Across the modules (Prompt 4)

### 6.1 SLA reminders: `hani:sla-reminders` (daily 08:15, `--dry-run`)
- For every open application of Hani's modules, `Support\StageSla` measures
  the time at the current stage from the decision that brought it there (or
  submission), in working days (Monday–Friday less
  `hani.scheduling.public_holidays`).
- Limits in `hani.sla.stages` (all Provisional): nomination stages 5 and 10
  working days (3 and 6 at `cgs_final` / `cgs_release`); `notice_received`
  and `softbound_check` 5 and 10; re-viva `report_sent` and
  `report_received` 5 and 10, `consolidation_scheduled` 10 and 15.
- First threshold → the stage's approver (the AE of the candidate's
  department, or everyone holding the role). Second → the approver again,
  with the Senior Executive CGS copied. Past both on the first run → the
  second only.
- Once per threshold per visit (`hani_sla_reminder_logs`, keyed on the time
  it reached the stage), so a returned list starts a fresh clock.
- **No double reminders:** `hani.sla.skip` excludes re-viva
  `under_panel_review` (already chased by `re-viva:monitor`). Examiner
  replies, report deadlines, panel and softbound reminders are not workflow
  stages and stay with `viva:reminders`.

### 6.2 Process Analytics (`/process-analytics`)
- Linked from the Examiner Dashboard and the sidebar. AE: own department;
  Senior Exec, Senior Director, Dean, Non-Exec: everyone, read-only (no
  forms on the page).
- Period filter: last 3, 6 or 12 months (default 12). Every chart states
  what it is computed from and over which period.

| Panel | Computed from |
|---|---|
| Days per stage per module: average, median, share; slowest highlighted; headline slowest stage | consecutive `approval_history` rows (first from submission), decisions in the period |
| Overdue now | `StageSla`, today |
| Viva pipeline | notices filed in the period → softbound filed → all current main reports in → viva held → result recorded; average days between steps |
| Re-viva rounds | open rounds by stage (today); outcomes by level, round 1 vs later, in the period |
| Report turnaround | thesis received → report received, MSc vs PhD, against the 4 / 6-week limit, reports in the period |
| Pool utilisation | active examiners holding a main seat on a released panel in each month, from `examiner_seat_history`, as a share of the active pool |

- Export: `/process-analytics/export?format=xlsx|csv&months=`, the same
  tables (`ProcessAnalytics::tables()`), same scope. xlsx has one sheet per
  table; csv stacks them with a "Table" column.
- Chart.js from `public/js/hani/process-analytics.js`, colours from CSS
  tokens, so dark mode repaints.

---

## 7. Step trackers

`Support/ModuleTracker` with `partials/module_stepper.blade.php` (Core's
stepper markup). Every step's state comes from stored status: completed,
current (with "What to do now"), upcoming, rejected.

| Where | Steps |
|---|---|
| Student viva home, softbound form, corrections | Notice → Softbound → Examiner Appointment (status only) → Viva Session → Result & Corrections |
| AE's Set Date form | the same viva steps |
| Examiner Nomination queue cards | the six chain stages |
| Re-viva queue, Outcomes, Cycles, My Re-viva | "Re-viva round N": Resubmission → Panel Review → Report & Consolidation → Re-viva Result |

---

## 8. Sidebar entries

Generated by Core from each workflow's declarations.

| Role | Entries from Hani's modules |
|---|---|
| Student | My Application → Notice of Thesis Submission (one "Viva" entry once SHARED_CHANGES section 2 is applied); My Re-viva when they have a round or a category-4 viva |
| Supervisor | Nominate Examiners · Students' Viva Progress |
| AE | queues: Examiner Nomination, Notice of Thesis Submission, Softbound Thesis, Re-viva (4 stages). Links: Pending Evaluation · Conflict Detection · Examiner Report · Examiner Dashboard · Process Analytics · Viva Scheduling · Viva Results · Re-viva Outcomes |
| Senior Director / Dean | nomination queues. Links: Conflict Detection · Examiner Report · Examiner Dashboard · Process Analytics |
| Senior Exec CGS | nomination queues; Examiner Pool · Conflict Detection · Examiner Report · Examiner Dashboard · Process Analytics · Log Re-viva Submission · Re-viva Cycles |
| Senior Director / Dean | nomination queues; Examiner Report · Examiner Dashboard · Process Analytics |
| Non-Exec CGS | Examiner Pool (manages it) · Examiner Report · Examiner Dashboard · Process Analytics · Re-viva Cycles (read-only) |

---

## 9. Data model (Hani's own tables)

| Table | One row per |
|---|---|
| `examiners` | examiner (state dates, external fields, email status) |
| `examiner_expertise`, `examiner_publications` | keyword / publication |
| `examiner_nominations` | nomination application (four seat columns, current panel) |
| `examiner_seat_history` | seat event: nominated, released, standby, declined, promoted, vacant, replaced, examined, freed, held |
| `examiner_import_batches`, `examiner_review_items` | import preview / review row |
| `examiner_duplicate_dismissals` | "Not the same person" pair |
| `examiner_contact_history`, `examiner_email_proposals` | email change / proposal |
| `examiner_reminder_logs` | `examiners:sweep` reminder sent |
| `viva_eligibility` | student (ticks, system checks, verification) |
| `viva_details` | notice (programme level, title, abstract, keywords, schedule, result, deadlines, corrections) |
| `viva_softbound_details`, `viva_softbound_checks` | softbound attempt / its System checks |
| `viva_examiner_progress` | main seat on a viva (letter, acknowledgement, thesis, report, category) |
| `viva_reminder_logs` | `viva:reminders` reminder sent |
| `re_viva_details` | re-viva round (window, panel, outcome, final thesis) |
| `re_viva_evaluations`, `re_viva_staff_documents`, `re_viva_letter_sends` | re-evaluation / staff-only file / letter send |
| `re_viva_reminder_logs`, `re_viva_summaries` | alert sent / digital summary |
| `hani_sla_reminder_logs` | SLA threshold reached (Prompt 4) |

---

## 10. Commands

| Command | When | `--dry-run` |
|---|---|---|
| `examiners:sweep` | 07:30 | yes |
| `viva:reminders` | 07:45 | yes |
| `re-viva:monitor` | 08:00 | yes |
| `hani:sla-reminders` | 08:15 | yes |

All scheduled from `Hani\ModuleProvider`; each reminder is sent once.

---

## 11. Demo data

`HaniDemoSeeder`, on demand, idempotent, every date relative to the run
day:
1. The examiner pool, through the import.
2. Ten live scenarios, S01–S10, at the stages in the README.
3. S09's and S10's re-viva rounds.
4. **24 fictional completed cases (H01–H24)** over the past year, through
   the real engine with the clock set to each step: varied durations, five
   lists sent back, four softbounds returned, four re-vivas to an outcome.
   No email; examiner lifecycle dates restored afterwards, so the live
   scenarios see the same pool.

---

## 12. Known limitations

1. **No live pop-up** for new work: an email and the unread count, updated on
   the next page load.
2. **Candidacy only as good as Study Candidacy:** without a record the item
   is self-declared; RCS results, registration and fees have no data source.
3. **Chairman and co-supervisor are typed in** by the AE; neither is stored
   elsewhere.
4. **PDF reading** cannot read every real PDF (CID fonts without ToUnicode,
   scans); those show "Not checked automatically".
5. **Examiner files are outside `DocumentStore`** until a staff-only flag
   exists (SHARED_CHANGES section 8), so they are not in the Documents page.
6. **Analytics stage times are calendar days**; SLA limits are working days.
   The two measure different things (elapsed time against a service level)
   and say so.
7. **Provisional values:** every unconfirmed figure in `config/hani.php` is
   marked; CGS has not signed them off.

## 13. Open questions (pending CGS)
- Does MSc go through faculty-level compilation and Dean endorsement, or
  straight from DAC to FSMC-AC? The chain applies to both today.
- The SLA limits per stage, and whether CGS wants the second threshold to
  copy someone other than the Senior Executive.

---

## 14. Tests

`tests/Feature/Hani/`, run with
`docker exec uresearch-app php artisan test tests/Feature/Hani`. The file
list by prompt is in the README ("Tests").
