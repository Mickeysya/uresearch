# Changelog: Hani's modules

## 2026-11-07: Conflict Detection can act, not only flag

- **Conflict rows:** every number links to wherever the viewer can open that
  list, the released list is marked as keeping the examiner (Manage panel →
  Replace), and pending lists get "Ask department to substitute".
- **Decision: the return remarks stay generic.** `returnTo()` sends them to the
  candidate, who must never learn their examiners, so the reason lives on
  `examiner_substitution_requests`.
- **Decision: a return also records a request.** A returned list otherwise had
  no way to change a seat. Seats can only change at academic_exec or
  cgs_compile, never under a signature further up the chain.
- **Decision: Senior Director and Dean now reach Conflict Detection,** or the
  return action could not be used from it. Duplicates stay Senior Exec only.
- **Duplicates:** one line per pair, with "Not the same person" and "Same person:
  merge" (`Support/ExaminerMerger`, logged in `examiner_merges`). Nothing is
  deleted. Old re-viva letter links resolve to the record kept.
- **Known gap:** Jason's module keys sent letters by examiner id, so a letter
  sent under a merged record reads as unsent for the record kept. The merge
  warns when it can detect this.

## 2026-11-04: Across the modules, and wrap-up (Prompt 4)

Branch `feature/hani-automation`. Commits: `5b1683d` (a), `43076e6` (b),
`cdaf8f5` (c), `90a3eb6` (d), and the verification commit (e).

### Problem

- **Nothing chased a stage that simply waited.** The viva and re-viva
  reminders cover examiners and deadlines, but a nomination sitting at CGS
  compile, a notice nobody acknowledged or a softbound nobody checked waited
  silently.
- **Nobody could see where the time goes.** `approval_history` records every
  decision, but no screen turned it into stage times, bottlenecks or
  turnaround, so "the chain is slow" could not be answered with a figure.
- **The demo had no history.** Ten live scenarios cannot make analytics
  meaningful.
- **The documentation had drifted:** SHARED_CHANGES.md had grown to 13
  numbered items, and the behaviour doc predated Prompt 2.

### Decisions

- **One reading of "overdue".** `Support/StageSla` is used by both the
  reminders and the analytics page, so the two never disagree.
- **Never two reminders for one wait.** Stages another command already chases
  are in `hani.sla.skip` (re-viva panel review, `re-viva:monitor`), and a
  test fails if any skipped stage gets an SLA limit.
- **The limits are Provisional.** CGS has set no service levels for these
  stages; 5 and 10 working days is our default.
- **Analytics read existing records only.** Stage times come from
  consecutive `approval_history` rows; nothing new is recorded to feed them.
  Every chart says what it is computed from and over which period. Same
  scoping as the Examiner Dashboard; Non-Exec reads everything, read-only.
- **History through the real engine.** The 24 fictional cases run
  `WorkflowEngine` and Hani's services with the clock set to each moment,
  so the durations are honest and `status` / `current_stage` are never
  written. No email, and the pool's state is restored, so the live
  scenarios are unaffected.

### What changed

| Item | What it does |
|---|---|
| a. SLA reminders | `hani:sla-reminders` (08:15, `--dry-run`): the current approver at the first threshold, the Senior Exec copied at the second; once per threshold per visit (`hani_sla_reminder_logs`); a returned list starts a fresh clock |
| b. Process Analytics | `/process-analytics`: days per stage per module (average, median, share), slowest stage, overdue now, viva pipeline, re-viva rounds and outcomes, report turnaround (MSc vs PhD), pool utilisation by month; 3/6/12 months; xlsx (one sheet per table) and csv; Chart.js with token colours (dark mode); linked from the dashboard and the sidebar |
| c. Demo history | `Concerns/SeedsHistoricalCases`: H01–H24, completed between 5 and ~330 days ago, five lists sent back, four softbounds returned, four re-vivas to an outcome; idempotent |
| d. Tidy up | SHARED_CHANGES.md one section per target file; README rewritten (features at a glance, how each intelligent feature explains itself, commands, config, demo); behaviour doc rewritten for Prompts 1–4 |
| e. Verification | full suite, re-seed, Chromium journey, every command dry-run; the fixes below |

Bugs found and fixed along the way:
- **Finished panels reopened** (c). Pending Evaluation, `examiners:sweep`
  and the cross-department clash check decided "open" from the examiners'
  current `assigned_until`. A panel examined months ago reappeared as soon
  as one of its examiners joined a later panel: listed as pending, swept
  for an "evaluation overdue" reminder, and flagged as a clash. All three now
  share `ExaminerNomination::evaluationOpen()`, read from the panel's own
  seat history. `EvaluationOverTest` fails without it.
- **Dry runs over-reported** (e). `viva:reminders`, `re-viva:monitor` and
  `examiners:sweep --dry-run` listed every reminder due, including ones
  already sent, as "would send". They now skip what is logged and say "N
  already sent", as a real run does.
- **Invented waits in the live demo** (e). The seeder backdated S03–S10's
  notices and nominations but decided them "now", so analytics showed
  40–180-day waits ("Notice: 55.9 days average, median 5"). Notices are now
  acknowledged two days after filing, and chains move every four days.
- **Utilisation against an empty pool** (e). The monthly pool counted
  examiners by `created_at`, which is the import date, so earlier months
  showed "16 holding a seat of 0". It is now today's active pool, and the
  caption says so.

New table: `hani_sla_reminder_logs`. No shared file or shared table was
changed.

### Verification

- `php artisan test tests/Feature/Hani`: 250 passed. Full suite:
  1897 passed (34,211 assertions).
- `./sync.sh` (one migration, worker restarted), then re-seeded from a clean
  run.
- **Headless Chromium, one journey across every account**: 172 checks, 71
  clicks, all passed, no JavaScript errors.
  1. S01 files eligibility and notice; `ae@` acknowledges.
  2. `nurul.aziz@` nominates with suggestions ("matched on", load shown).
  3. `ae@` → `seniorexec@` → `director@` → `dean@` → `seniorexec@`
     "Finalise and release". `cgs@` gets the Mailpit email and the in-app
     notice.
  4. Softbound: the Turnitin report is read in the browser (12%), and System
     checks finish on the worker. Verified.
  5. Examiner progress, categories 3 and 4: major divergence. Scheduled on a
     suggested slot.
  6. Result category 4: the student sees the category, not the examiners'.
     The viva date was moved to yesterday in Hani's own `viva_details` row,
     since a result needs a held viva.
  7. `seniorexec@` logs the re-viva; it is early for the window, so it is
     warned, not blocked.
  8. `ae@` sends to panel. The link from the real Mailpit email opens logged
     out and the document downloads.
  9. Re-evaluations 2 and 2, suggested Level 2, confirmed. The student sees
     the level only.
  10. `cgs@`: Cycles, Report, Analytics and Dashboard return 200 with no
      action forms; 403 on logging.
  11. Dashboard and analytics as `seniorexec@` and `ae@`, light and dark:
      every chart drawn, every period filter, both downloads, the theme
      toggle and Back.
- **A test-script mistake, corrected.** On the first run the script clicked
  the first decide form on the AE's re-viva queue. That was application #3,
  a `student@utp.edu.my` round created on 11 October, which it advanced from
  Under Panel Review to Report Received (and the student was emailed). It
  was returned through `WorkflowEngine::returnTo()` with a remark saying
  why, and the script now targets the journey's own records by id.
- **Every command with `--dry-run`** after the final re-seed:

  ```
  examiners:sweep     Dry run: 0 reminder(s) would be sent (0 already sent); 0 stale import preview(s) would be purged.
  viva:reminders      would send panel_7d (S07, 5 recipients), softbound_overdue (S05, 1); 2 would be sent, 0 already sent.
  re-viva:monitor     Dry run: 0 re-viva reminder(s) would be sent; 1 already sent.   (S09's chase, sent by the seeder)
  hani:sla-reminders  sla_2 for student2's re-viva #1 (Report Received, 25 working days), S03 (CGS Compilation, 18), S04 (Senior Director, 16)
  ```
- Re-seeded at the end, so the demo starts as the README describes.

## 2026-11-04: Merge of origin/feature/hani-automation (= develop 0a23bad)

**What came in that touches Hani.**
- **In-app document viewer** (`<x-core::doc-link>`, `documents.view`): the
  re-viva queue's and Outcomes' DocumentStore lists now open in the viewer,
  with a Download link. The viewer and the new Documents page read only
  `application_documents` through `ApplicationDocument::visibleTo()`.
  Staff-only re-viva letters, UTP/CGS/024 reports, the Confirmation of
  Correction, viva examiner reports and examiner CVs are not DocumentStore
  rows, so they stay on Hani's own role-checked routes. Checked in Chromium:
  the student's Documents page and My Re-viva list none of them, name no
  examiner, and get 403 on the staff routes.
- **RPD Candidacy removed** (Norhanis, `2026_10_30_000100_remove_rpd_candidacy`
  drops `candidacies`). `Eligibility\CandidacyPeriodSource` read the
  candidature start date there; with the table gone it fell back to
  "Self-declared" without failing, and three tests broke. It now reads
  Chloe's Study Candidacy record (`study_candidacies.candidacy_expiry_date`,
  which includes extensions, and `status`): inside the expiry date is
  verified, past it or dismissed is not met. Guarded and read-only as before.

**Conflict.** `re_viva/outcomes.blade.php`: develop switched the student's
document list to `<x-core::doc-link>`, and Prompt 3 had replaced that list
with the evaluations and staff files. Both are kept: the list, with the
viewer, then the evaluations, staff files and suggestion.

## 2026-11-04: Resend letter

**Why.** An examiner's link works for 35 days, and the panel is escalated at
25 working days, about when the link runs out. A late examiner landed on
the expired-link page with no way for CGS to send a new link.

**What.**
- "Resend letter" beside each correction letter while the round is Under
  Panel Review, for the AE of the student's department (queue) and the
  Senior Executive (Re-viva Cycles). It emails the same stored letter, with
  a fresh link for `link_days` from now. The subject starts "Resent:".
  `ReVivaLetters::resend()` and `resendBlock()` hold the rules, re-checked
  under a row lock. Refused: the Non-Executive and other departments (403),
  any other stage, and an examiner who no longer holds the seat.
- A flagged email is not resent, and the AE sees the usual reason.
- `re_viva_letter_sends` (migration `2026_11_06_000800`) logs every send,
  first and resend: who, when, emailed or refused and why, and the date the
  link works until. The email writes that date when it is built in the
  queue worker. Existing letters are backfilled as first sends. The
  activity log records each resend too.
- "last sent {date} by {name} (resent); link works until {date}" per
  examiner, on the AE's queue, Cycles and Outcomes. The Non-Executive sees
  it without the button.
- The expired-link page now tells the examiner to ask CGS to resend the
  letter.

**Tests.** `ReVivaResendTest` (6). The click-through in Chromium resent a
letter as the AE and as the Senior Executive, and opened the resent email's
link logged out.

**After pulling.** Migrate, and restart the queue worker: the letter
notification's constructor changed.

## 2026-11-04: Fix Confirm Outcome 404 on a stale page

**Problem.** Clicking "Confirm Outcome" on Re-viva Outcomes (AE Applied
Sciences, S09) landed on `/re-viva/outcomes/11` with 404 NOT FOUND. The
access log showed the page opened at 04:10:46 and the confirm at 04:11:15.
In between, a demo re-seed at 04:10:56 had rebuilt the re-viva rounds and
deleted round 11. The route's model binding found no round and answered
404 at a POST-only URL. The same bare 404 also came from two ordinary
states: a confirm when the outcome was already recorded (a second tab, a
double click, Back and resubmit), and a confirm before consolidation was
complete. RegionSwap was not involved: it binds GET forms only.

**Fix.**
- `ReVivaController::recordOutcome()`: "already recorded" and "not ready
  yet" now return to Outcomes with a message saying what happened (the
  level, when and by whom). Nothing is changed. `ReVivaOutcome::record()`
  still re-checks both under a row lock.
- `ReVivaController::gone()`, used through `->missing()` on every re-viva
  route that binds a record (decide, send to panel, re-evaluation, outcome,
  corrections accepted, final thesis, staff-file download): a record that
  no longer exists returns to the page the form was on, saying so.

**Checked by clicking** in headless Chromium, every Prompt 3 form: Resend
letter, Advance at each stage, both re-evaluations, Confirm Outcome (fresh,
stale tab, deleted round), the student's final-thesis upload, Corrections
accepted, the digital Summary of Corrections (save, check, PDF), Log round
2 with the digital summary, Send to panel, and the staff-file downloads for
the AE, the Senior Executive and the Non-Executive. Each lands on a 200 page
with the expected message.

**Tests.** `ReVivaOutcomeTest`: a stale second confirm, a confirm before
consolidation, and a deleted round on each re-viva form.

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
