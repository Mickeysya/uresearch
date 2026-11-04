# Changelog: Hani's modules

## 2026-11-04: Fix examiner signed link signature

**Problem.** The examiner's link in a seeded "Correction of thesis for
re-evaluation" email (S10, Kavitha) gave "403 Invalid signature" logged
out. The signature was correct. The link had expired before it arrived:
its 35 days counted from `sent_to_panel_at`, which the demo seeder backdates
to 19 September, so it expired on 24 October. Laravel's `signed` middleware
reports an expired link as an invalid signature. S09's seeded link would
have died the same way on 9 November.

**Fix.**
- `ReVivaLetters::panelLinkExpiry()`: a link works for `link_days` from when
  it is made, which is when the queue worker builds the email. The send date
  is only the earliest start. The reply-by date still counts from the send.
- `ReVivaCorrectionLetter` uses it for both the link and the date the email
  states.
- The letter PDF no longer prints a link expiry date (it is rendered at the
  send, and could contradict the email). It points to the email instead.
- `Http/Middleware/ValidPanelLink` replaces `signed` on the two panel routes:
  a wrong or tampered signature is still 403; a correct but expired link is
  410, with a page giving the date and the CGS contacts.

**Tests.** Added `ReVivaLettersTest::test_the_emailed_link_works_from_when_the_email_is_built_not_from_a_backdated_send`
and `HaniDemoSeederTest::test_the_seeded_examiner_emails_carry_links_that_open_logged_out`.
Both fail on the old rule. The expiry assertions in the existing link test
now expect 410.

**Walkthrough.** The walkthrough's new first step opens the four seeded
emails' links from the Mailpit HTML in a logged-out Chromium. All four open.

**After pulling.** Restart the queue worker (`php artisan queue:restart`)
and re-seed. Emails already in Mailpit carry the old, expired links.

## 2026-11-04: Re-viva Monitoring: automation and intelligence (Prompt 3)

Branch `feature/hani-automation`. Commits: `7bf4d94` to `14d9b62`, plus
this documentation commit.

### Problem

Re-viva Monitoring was a four-click stepper with nothing behind it:

- **Logging:** Non-Executive CGS could log re-vivas (CGS practice: the
  Senior Executive does), with no link to the viva and fixed 6- and
  12-month deadlines that ignored the viva's resubmission window.
- **Letters:** the AE wrote each examiner's "Correction of Thesis" letter
  by hand and posted or emailed the thesis.
- **Chasing:** nobody counted the panel's twenty working days.
- **Reports:** the UTP/CGS/024 re-evaluations were recorded nowhere, so
  disagreement surfaced only at consolidation.
- **Outcome:** a bare 1–5 dropdown that released nobody, notified nobody
  and set no deadline. The subtitle said the student opens the next round.
- **After the outcome:** no final thesis, no Confirmation of Correction, no
  reminders.
- **Scoping:** the Outcomes page showed every department to every AE.

### Decisions

- **Enhance, do not rebuild.** Same module key and four stage keys; the
  new state lives on Hani's own tables.
- **Roles (confirmed).** The Senior Executive logs; the AE runs the stages
  and the outcome; the Non-Executive is read-only and, as a return contact
  on the letter, may download the staff-only files.
- **Examiner files stay out of `DocumentStore`.** Letters, signed
  UTP/CGS/024 forms and the Confirmation of Correction name or come from
  the examiners, so they sit in `re_viva_staff_documents` on the private
  disk with a logged staff download (SHARED_CHANGES.md item 12).
- **Level 4 (confirmed).** Levels 1–3 and 5 complete the mains' evaluation
  (the gap starts); level 4 keeps them held until round N+1 is logged or
  its window closes, when they are freed with no gap.
- **Next round window (confirmed, provisional).** [0, 6] months from the
  level-4 outcome date.
- **Explainable, local.** Every check says what it found, the outcome is a
  suggestion the AE confirms, and the risk score shows each factor. No
  external AI service.

### What changed

| Item | What it does |
|---|---|
| a. Roles | `/re-viva/new` Senior Executive only; new read-only Re-viva Cycles (Senior Exec, Non-Exec); sidebar links by role; every action 403 for Non-Exec |
| b. Hand-off | Expected resubmissions list; `?student=` pre-selects; `viva_application_id` and the window stored; outside the window warns; legacy viva result date; hold extended |
| c. Uploads | Corrected thesis and Summary of Corrections, PDF, DocumentStore |
| d. Send to panel | Dompdf letter per current main (after declines), staff-only, emailed with a signed, expiring, throttled link; flagged emails skipped and named; stage 1 completes in the same action; `DecidesOneAtATime` |
| e. Panel limit | `re-viva:monitor`: 20 working days → AE, 25 → Senior Exec, once each; counter on the card |
| f. Re-evaluations | UTP/CGS/024 per main: category, viva again, signed form, ratings; both required; divergence on stages 3–4, Outcomes and Cycles |
| g. Outcome | Suggested level or "Consolidation needed"; deadlines 1/3/6 months; student and supervisor told without examiner details; level 4 prompts the Senior Exec; PanelSeats release; department scoping; subtitle fixed |
| h. Corrections | My Re-viva page with final thesis upload; Corrections accepted; pre-filled Confirmation of Correction, staff-only |
| i. Monitor | Window and final-thesis reminders (30, 7 days), overdue flags to AE and Senior Exec once, level-4 window closing frees the mains |
| j. Risk score | 0–100 from four weighted factors, each shown; Cycles sorted by it |
| k. Demo seeder | S09 Under Panel Review for 22 working days (reminder fired); S10 round 1 at level 4, ready for round 2; every step run at its own moment, idempotent |
| c2. Digital summary | Student fills the Summary of Corrections; checks every comment has an action and every page exists (built-in PDF reader); renders the PDF; usable at logging |

New tables:
- `re_viva_staff_documents`
- `re_viva_evaluations`
- `re_viva_reminder_logs`
- `re_viva_summaries`

New columns on Hani's `re_viva_details`:
- `viva_application_id`, `resubmission_opens_at`, `resubmission_closes_at`,
  `viva_result_date`, `logged_outside_window`
- `sent_to_panel_at`, `sent_to_panel_by_id`
- `suggested_level`, `outcome_recorded_at`, `outcome_recorded_by_id`
- `final_thesis_submitted_at`, `corrections_accepted_at`,
  `corrections_accepted_by_id`
- `correction_deadline` and `hardbound_deadline` became nullable. Old rows
  keep both; new rows use the window, and `correction_deadline` holds the
  final-thesis deadline after the outcome (documented in the migration).

No shared table was changed. No stage was added or removed.

Bugs fixed along the way:
- Re-viva Outcomes and recording an outcome were not department-scoped.
- The Outcomes subtitle described level 4 wrongly.
- Found in the walkthrough: the Re-viva Cycles table was clipped on the
  right; re-evaluations now sit in the wide column.

### Verification

- `php artisan test tests/Feature/Hani`: 218 passed. Full suite: 654
  passed.
- Re-seeded. Headless Chromium walkthrough, all checks passed, no
  JavaScript errors:
  1. The Senior Exec logs S09's re-viva from the category-4 hand-off (S09's
     seeded round was removed first so it could be logged live).
  2. The AE sends to panel: two letters, stage 1 complete.
  3. Both emails reach Mailpit with the PDF. The signed link opens logged
     out, serves the document, and refuses a tampered signature.
  4. Stage 3 refuses without both reports. The AE records 2 and 4 + viva
     again (major divergence on stages 3–4 and Outcomes), consolidates, and
     confirms level 3 with remarks.
  5. S09's My Re-viva shows the level and deadline, and no examiner names.
  6. The Senior Exec logs round 2 for S10, with the window from the level-4
     outcome.
  7. The Non-Exec reads Re-viva Cycles with no action forms, downloads a
     letter, and gets 403 on logging.

  Re-seeded afterwards, so the demo starts from the state described in
  the README.
- The queue worker had been running since before these changes and failed
  the seed's four letter jobs on a missing route. After `queue:restart` and
  `queue:retry`, all four were delivered. Restart the worker after pulling.

## 2026-11-03: Examiner email upkeep (Prompt 1 follow-up)

Branch `feature/hani-automation`. Commits: `3cd8c4b` to `f9d4d18`.

### Problem

Feedback from Encik Syahmi (Non-Executive CGS), who sends the appointment
letters:
- **No emails in the pool:** he looked examiners up on university websites
  by hand.
- **Stale emails:** external examiners move, and their universities do not
  update CGS.
- **Dead addresses found late:** he learnt an email was dead only when an AE
  reported a bounce, and nothing stopped the next email going to the same
  address.

Separately, `viva:reminders` sent no panel reminder at all to a viva set
for later the same day.

### Decisions

- **Non-Exec edits the email only.** The email-only form validates and
  writes that one field, whatever else is posted. The Senior Executive keeps
  the full examiner form.
- **One way in for every email change.** `Support\ExaminerContact` logs
  each change in `examiner_contact_history`, whatever the source: the form,
  the email-only edit, an import filling a missing address, or a confirmed
  proposal.
- **A reported address is blocked, not deleted.** It stays on record,
  flagged Invalid. Nothing emails the examiner until a new address is saved,
  and each skip says why.
- **Proposals are never applied automatically.** An email a supervisor gives
  at nomination waits for CGS. A working address is never replaced by a
  proposal.
- **The CV stays out of `DocumentStore`.** An `ApplicationDocument` on the
  nomination would be visible to the candidate, so the CV is kept on the
  private disk with a CGS-only, logged download. This is the same treatment
  as the viva examiner reports (SHARED_CHANGES.md item 12).
- **Existing addresses start as confirmed.** `email_confirmed_at` is
  backfilled to the migration date, so the pool does not open with every
  address "not confirmed in 12 months".

### What changed

| Item | What it does |
|---|---|
| e. Reminder fix | A viva less than a day away gets `panel_1d`, even later the same day. `panel_7d` goes only 6 to 7 days out and is never sent late to a viva set closer. The subject reads "Viva today" when it is |
| a. Contact details | Non-Exec reads the pool and has an "Update email" form (a reason is required). New examiner page with the contact history (old, new, who, when, reason). The Senior Exec form takes an optional reason for an email change |
| b. Email not working | AE (own department, or its students' panels), Non-Exec and Senior Exec can report an email, with a reason, from the examiner page or the viva examiner-progress screen. It becomes Invalid, CGS is notified (`afterCommit`), and an "Email needs updating" badge shows wherever the examiner appears. Viva invitations and panel reminders skip the examiner and say so. `ReleasedExaminerProvider` gives Jason's letters no address, plus `email_problem`. Saving a new email clears the flag |
| c. Freshness | `email_confirmed_at` is set on add, change, import fill and the new "Confirm email still valid" button, which is logged and also clears a mistaken flag. Pool Email filter and two dashboard tiles: "Missing or invalid emails" and "Emails not confirmed in 12 months" (`hani.examiner_contact.stale_months`, provisional). Non-Exec can open the dashboard |
| d. Email at nomination | Picking an examiner whose email is missing or invalid opens a "current email" field and an optional CV (PDF, 5 MB). The proposal is stored in `examiner_email_proposals`. "Proposed emails to confirm" in the pool: Confirm (one click, logged) or Dismiss |

New tables:
- `examiner_contact_history`
- `examiner_email_proposals`

New columns on Hani's `examiners` table:
- `email_status`
- `email_flagged_at`
- `email_confirmed_at`

No shared table was changed.

### Verification

- `php artisan test tests/Feature/Hani`: 173 passed. Full suite: 609
  passed.
- The new `ExaminerContactTest` covers:
  - the Non-Exec email-only edit
  - the history, from every source
  - the report flag: badges, blocked invitations, reminders and letters,
    and clearing on update
  - freshness: filters, dashboard counts and the backfill
  - the propose, confirm and log flow, and that a valid email is never
    overwritten
- `VivaSchedulingAssistTest` covers the reminder fix.
- Not walked through in a browser this time. The nomination form's
  email-field toggle is a small inline script, covered by the server-side
  tests only.

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
