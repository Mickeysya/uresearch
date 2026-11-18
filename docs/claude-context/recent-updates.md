# Recent Updates

Changelog of changes made to this repo in recent Claude Code sessions, kept
for syncing a Claude Project's context. Each entry lists the problem, the
decision, what actually changed, and how it was verified, so a future
session (or a teammate) doesn't have to re-derive the reasoning from the
diff alone.

---

## Where Nureen's modules stand (snapshot, 3 Nov 2026)

A summary of everything below, module by module, for anyone starting fresh.
`app/Modules/Nureen/README.md` has the detail, and `SHARED_CHANGES.md` covers
every Core change and open team decision.

### GA Extension (`ga_extension`)
- **Chain:**
  - Default mode `cgs`: Supervisor endorses (3 working days), then
    Non-Executive CGS decides (2), and the Senior Director is emailed every
    outcome for information only.
  - Mode `senior_director`: Supervisor, then CGS review, then the Senior
    Director approves.
  - Each application keeps the chain it was submitted under
    (`final_approver_snapshot`).
- **Submission:** four required document types, multi-file. The supervisor
  stage is scoped to the student's own supervisor, recorded on the detail
  row. CGS can return an application to the supervisor (with a reason)
  instead of rejecting it.
- **Timing:** Client Charter due dates, expected decision dates and
  reminders (`ga-extension:remind-stalled`, daily 08:00). Shown on the
  calendar.

### Supervision (`supervision`)
- **Chain:** the requested supervisor, then the Chair of Department, then
  CGS.
- **Modes:**
  - `endorse` (default): CGS previews and endorses the appointment letter.
  - `automatic`: the Chair's approval is final, and the letter names no CGS
    endorser.
  - `manual`: CGS makes an eligibility decision.
- **On approval:** a Main Supervisor appointment updates the student's
  `supervisor_id` (and records the previous one).
- **The letter:** issued as `CGS/SVAPPT/<year>/<seq>` on the shared formal
  Dompdf layout, one A4 page, with a QR code to `/verify/appointment/{ref}`.
  CGS can revoke it, which emails the student and the supervisor with the
  reason.

### GA/GRA Certification Letter (`ga_certification`)
- **Chain:** Supervisor (3) → CGS Staff (2), who checks the appointment
  record ("Verified / Mismatch / Not found", CGS-only) → Senior Director
  (2), who can preview and then approves.
- **The letter:** `CGS/GACERT/<year>/<seq>`, with GA course or GRA project
  fields from one config list. Max field lengths keep it on one page. It is
  verifiable at `/verify/certificate/{ref}` and can be revoked.

### Attendance and Attendance Appeal (`attendance_appeal`)
- **Data in:**
  - CSV upload, an inbox folder, a push API, or a simulated source;
  - `attendance:sync` runs nightly at 02:00;
  - no period may end in the future, and mixed period lengths are refused.
- **Risk check:**
  - **The original rule:** below 80%, or three declining periods ending at
    or below 85%.
  - **A least-squares end-of-semester projection,** which may flag only
    from period 4 up to 60% of the semester. That window was tuned on the
    backtest.
  - **Automatic early-warning emails,** with a supervisor copy, that never
    show the projection.
  - **CGS's At-Risk list** shows current %, projected %, trend and reason.
- **Students** see their own figures and the dashboard's Attendance Action
  card ("Attend N of the next M sessions", "Justification needed", "On
  track", "Keep checking in"). Never the projection.
- **Appeals:**
  - require a Justification Letter and a Monthly Activity Breakdown;
  - CGS may approve fewer sessions than requested;
  - excused sessions count as attended or are left out of the total
    (`attendance.excused_sessions`, pending CGS's answer).
- **Evidence:** `attendance:backtest` (synthetic data or a real export,
  `--runs`, `--compare`) reports MAE, RMSE, precision, recall, F1 and
  earliness.

### Shared pieces built or extended along the way (in Core, recorded in SHARED_CHANGES.md)
- **Track My Applications** (`/applications`): tabs by each module's
  `CATEGORY`, status filters, and cards with per-application steppers.
- **Application details page:** progress against charter targets,
  timeline, "Your application", documents, help.
- **Optional contracts:** `SuppliesDecisionDates`, `SuppliesOverdueStatus`,
  `DescribesApplicationProgress` and `DescribesApplicationDetails`.
- **The shared `WorkingDayCalendar`** (`config/calendar.php`).
- **`WorkflowEngine::progress()`** never marks an undecided stage as done.
- **`phpunit.xml`** forces the test environment (no more leaked jobs).

### Demo data
Run with `db:seed --class=...`; none are wired into Core's seeder:
- `AttendanceRiskDemoSeeder`: the three viva cases.
- `NureenAttendanceDemoSeeder`: a weekly semester starting 5 Oct 2026.
- `NureenClientCharterDemoSeeder`.
- `NureenRealignedWorkflowDemoSeeder`.
- `GaAppointmentRecordSeeder`.

### Open items and decisions waiting on someone
- **Lee Xin Yi's GA Extension (#183):** resolved with no data change.
  Endorsed by `supervisor@` and approved by `cgs@` on 3 Nov.
- **No Electrical & Electronics Chair** in the seeded data: SHARED_CHANGES
  §13.
- **Six real letter emails** (4 `AppointmentLetterIssued`, 2
  `CertificationIssued`, all from 29 Oct–1 Nov) sit in `failed_jobs`. They
  failed on a since-fixed bug and are kept for Nureen to decide whether to
  retry.
- **Waiting on CGS:**
  - the excused-sessions rule;
  - the tab category names;
  - the help contact details;
  - the holiday list;
  - signatory titles;
  - whether a certification revocation should also tell the supervisor
    (SHARED_CHANGES §12).
- **Waiting on the team:**
  - SHARED_CHANGES §9b–d: Core's semester-vs-latest attendance figure,
    CGS dashboard counts, and `DemoDataSeeder`'s monthly attendance;
  - the `layout.css` phone gap above page-header actions;
  - each teammate's one-line `CATEGORY`.
- **`docs/design/application-details.png`** was never added, so the details
  page follows the written spec.

---

## 2026-11-12 — GA Extension follows the GA Application chain (Admin → CGS → Supervisor → Senior Director)

**Branch:** `feature/ga-extension-chain`, from `feature/cgs-assignment`
(`2e2e010`), uncommitted. Nureen's folder only; no Core change.
SHARED_CHANGES §19 has two shared issues found along the way.

### The ask
- **The confirmation:** CGS (Puan Akmar, Senior Executive CGS, 12 Nov 2026)
  confirmed that GA Extension's chain must match Haziq's GA Application chain
  minus its Research Centre interview, so Haziq's Admin stage stays.
- **Snapshot mode:** new applications get a third mode; in-flight ones keep
  theirs.
- **Returns:** CGS can return to Admin; the Senior Director to CGS or the
  supervisor; a reason is always required.
- **Emails:** the submission email goes to Admin; progress emails at every
  stage; the final outcome at the Senior Director; no "for information" copy.
- **Charter:** Admin 2, CGS 2, Supervisor 3; the Senior Director is null until
  CGS confirms.

### Key findings before writing any code
- **The two chains differed in four ways besides the interview:**
  - The order: CGS came after the Supervisor in GA Extension.
  - The final approver: the default `cgs` mode ended at CGS.
  - CGS's decision: it was final ("approved") instead of "reviewed".
  - The stage key: `cgs_verify` here, `cgs_review` in Haziq's. Kept as is,
    since keys are permanent.
- **Haziq's GA Application has no stage returns.** It has only "flag a
  correction", where the student is asked to fix fields and the application
  doesn't move. So the agreed returns were used.
- **Haziq has no Client Charter figures** to borrow for the Senior Director.

### The design
- **A third snapshot mode,** `ga_application`, is now the default for
  `final_approver`. Its chain is `admin_verify` (verified) → `cgs_verify`
  (reviewed) → `supervisor` (endorsed) → `senior_director` (approved). The
  existing stage objects are reused, and `stages(null)` still returns every
  stage any chain uses.
- **`GaExtensionWorkflow::returnTargets()`** lists the stages the current one
  may go back to. The controller's `returnToEarlierStage()` (route
  `ga-extension.return?to=`) validates against it. The decision bar shows one
  Return button per target.
- **`SubmissionNotifier`** names GA Extension's next actor stage by stage:
  - every Admin
  - the assigned CGS officer
  - the named supervisor
  - every Senior Director

### What changed
- **Workflow, config, controller and route:** the new chain, the default mode,
  and the general return.
- **Approver panel, decision bar and the queue and detail views:** the Return
  buttons and the line shown at the CGS stage.
- **`SubmissionNotifier`:** the next actor at every stage.
- **Tests:**
  - **New, `GaExtensionChainTest` (10 tests):** the chain read against
    Haziq's workflow, older snapshots, the emails at every stage, the
    returns, the CGS assignment, the Senior Director's charter, and
    Section E.
  - **Rewritten for the new default:** two in `SupervisorSafeguardsTest`
    and one in `DocumentPreviewTest`.
  - **Pinned to the `cgs` chain:** 11 tests that walk the old order, each
    with a comment.
- **Docs:** the README, `docs/functional-test-cases.md` (GAX-07, 08, 10 and
  11 rewritten; GAX-17 and 18 added; 59 cases), `results.md`, and
  SHARED_CHANGES §19.

### Bugs caught
- **A return to the supervisor emails all 24 supervisors** (Core
  `notifyReturn()`). Recorded as a proposal, not fixed.
- **`DemoDataSeeder` cannot decide `admin_verify`,** which has been the case
  since 10 Nov. Recorded as a proposal.

### Verification
- **Tests:** Nureen, `CgsAssignmentTest` and Haziq's tests: 658 passed. The
  one failure, `AttendanceActionCardTest`, already fails on this branch; its
  fix is commit `9a45624` on `feature/ga-extension-shortcut`.
- **Functional rerun** on a throwaway copy (results.md, run 2): GAX-07 to
  GAX-18 all pass, including GAX-13 now that the CGS assignment is present.
  SHR-03 is still a Fail because of Haziq's GA Application email link.
- **The form PDF's Section E** names Dr. Aisyah Rahman (endorsed), Zulkifly
  (reviewed) and En Zulkifly (approved).

---

## 2026-11-12 — Each module's CGS decisions go to one CGS officer

**Branch:** `feature/cgs-assignment` (from `origin/develop` at `fbf5a6c`),
uncommitted. This is an agreed Core change; SHARED_CHANGES §18 lists every
Core file, and a to-do for each teammate.

### The ask
- **Assignment:** CGS confirmed one Non-Executive CGS officer per team member's
  modules:
  - Norshahira: Norhanis
  - Zulkifly: Nureen
  - Syahmi: Jason, except Hardbound, which stays open to every officer
  - Amirul: Chloe
  - Akmar: Haziq (superseded the same day: open to all, see below)
  - Waheda: Hani (superseded the same day: not covered, see below)
- **What it restricts:** only that officer may approve, reject, return,
  endorse or issue a letter, or revoke one at a Non-Executive CGS stage.
  Everyone else gets a 403 but can still view.
- **Who gets the emails:** "awaiting you" and overdue emails go to the
  officer alone.
- **Absence cover:** a backup, or any CGS, may decide after escalation.
- **Dashboard:** "assigned to me" shown separately from "all".

### Key findings before writing any code
- **Which modules have a Non-Executive CGS stage:**
  - travel, publication, claims_student
  - ga_extension, supervision, ga_certification, attendance_appeal
  - hardbound_submission, hardbound_appeal, appointment_letter
  - candidacy_appeal
  - gra_application, ga_application

  Hani's modules have none; their CGS desks are Senior Executive CGS.
- **Decisions outside the engine:**
  - Nureen: Section J, and both letter revokes
  - Chloe: candidacy appeal, on its own table
  - Haziq: request-correction, edit-allowance, Stage Gate complete or not
    complete, and allowance payments
- **Agreed as decisions:** Section J and Haziq's actions. Stuck Reviews
  reassign stays open. Both Hardbound keys are `'*'`.
- **No shared reminder count:** each module keeps its own, and only Nureen's
  three modules and Norhanis' three supply a charter "overdue".
- **The accounts exist already:** `DatabaseSeeder::CGS_OFFICERS` creates
  them. But Akmar and Waheeda are **Senior Executive CGS** (`855e4c2`), so
  Akmar can't hold Haziq's Non-Executive stage.
- **Two controllers write before calling `decide()`:** Haziq's GRA allowance,
  and Jason's pack generation. So a 403 from the engine comes after those
  writes, which is listed for them.

### The design
- **The map:** one config map, `config/cgs_assignment.php`, read only through
  Core's `CgsAssignment`. An unlisted module, or an officer with no
  Non-Executive CGS account, falls back to any CGS.
- **The check:** in `WorkflowEngine::decide()` and `returnTo()`. It throws
  `NotAssignedOfficer`, a 403 that module controllers don't catch, so every
  engine decision in every module is covered.
- **Read-only rows:** the read-only line lives in Core's `decision-form`
  component, so Haziq's pages get it too, and in Nureen's decision bar.
- **Escalation:** overdue (the module's charter, else 14 days) plus reminders
  from the new optional contract `SuppliesReminderCount`; overdue alone when a
  module doesn't supply a count. Module-level actions (revoking a letter) are
  officer-only.

### What changed
- **Core:**
  - new: the config, `CgsAssignment`, `CgsVerdict`, `NotAssignedOfficer` and
    `SuppliesReminderCount`
  - the engine check, and return emails narrowed to the officer
  - bulk decide skips another officer's rows
  - the decision form goes read-only, and queue rows show "Assigned to: …"
    with "Overdue: backup may decide"
  - the dashboard shows "N assigned to you"
  - `layout.css` styles
  - `DemoDataSeeder` decides a CGS stage as the assigned officer
- **Nureen:**
  - Section J and both revokes are checked
  - the decision bar goes read-only
  - reminder counts are supplied
  - CGS emails go to the officer
  - the demo seeders decide as Zulkifly
- **Docs:** `accounts.md`, `test-accounts.md` (a table of who decides what), and
  SHARED_CHANGES §18.

### Bugs caught
- **DemoDataSeeder and Nureen's two demo seeders** decided every CGS stage as
  `cgs@`, which the new check refuses. `sync.sh --fresh` would have failed.
- **Bulk decide would have stopped halfway** through a batch on the first
  row owned by another officer. It now skips that row and names the officer.
- **Nureen's rows would have shown two read-only lines:** Core's form and the
  decision bar. Core's line is hidden there by the existing rule.

### Team decisions (later the same day, 2026-11-12)
- **Haziq's modules are open to every Non-Executive CGS officer.**
  `gra_application` and `ga_application` are `'*'`, and Akmar's role
  doesn't change. His SHARED_CHANGES to-do (check before saving the
  allowance) is now only an optional good-practice note.
- **The assignment doesn't cover Senior Executive CGS stages.** Hani's
  modules have no Non-Executive CGS stage, so they're out of the map.
- **No Akmar or Waheeda:** neither email appears in the map any more.
- **Tests:** `CgsAssignmentTest` replaces its Akmar-based fallback test with a
  generic one, and adds three tests:
  - Haziq's modules accept every officer, including module-level actions and
    emails to all.
  - Senior Executive CGS stages are never affected.
  - The map names neither Akmar nor Waheeda.

### Verification
- **`CgsAssignmentTest`:** 30 tests, 248 assertions, all passing, after the team decisions (27 and 193 before). They cover:
  - each module group (the officer may decide; any other CGS may not)
  - a 403 with the officer's name from the engine and over HTTP, while the
    queue and the application page stay viewable
  - `returnTo`, Section J and both revokes
  - both Hardbound keys open
  - an unmapped module, and an officer with no Non-Executive CGS account,
    falling back to any CGS
  - the backup only after escalation, and any CGS when no backup is set, with
    the row note
  - the 14-day and charter fallbacks
  - bulk decide
  - emails to the officer only, while an open module emails everyone
  - the dashboard "assigned to you" count
- **Full suite**, rerun after the team decisions: 2438 passed, 16 failed. The same 16 as before:
  - 15 of the failures also fail on a clean `origin/develop` (`fbf5a6c`),
    checked by stashing this change and rerunning those files:
    - QueueTest ×6, DepartmentScopingTest ×2, PageShellTest ×2,
      SidebarQueuesTest ×2
    - ProseTest (em dashes in Haziq's allowance view)
    - ApproverDashboardTest ×1, AttendanceActionCardTest ×1
  - The 16th is Norhanis' `CgsOfficerTest`, which asserts the old rule. The
    replacement is in SHARED_CHANGES §18.
- **Jobs:** `failed_jobs` and `jobs` were 0 before and after.
- **Dev app** (`sync.sh` applied first), GA Extension #202 at `cgs_verify`:
  - **zulkifly.cgs:** sees "Assigned to: Zulkifly", the Section J form, and
    Approve / Reject / Return to supervisor. The dashboard says "19 assigned
    to you".
  - **syahmi.cgs:** sees the same row with Section J as saved text and the
    line "This module is assigned to Zulkifly. You can view it but not decide
    on it." A real POST to `/ga-extension/202/decide` returns 403 with that
    message.
  - Both checked in light and dark mode in headless Chrome.

---

## 2026-11-08 — Approver rows: one decision bar, and a preview overlay for every document

**Branch:** `nureen/forms-and-drafts`, uncommitted. Nureen only: no Core edit.
SHARED_CHANGES §17 has the Core proposal with exact diffs.

### The ask

Two problems on the approver queues, first seen on Supervision as
Non-Executive CGS:
1. **The decision controls were split.** Preview and Endorse sat in their own
   box, "Remarks (optional)" came below it, and Reject was alone at the
   bottom. The fix: one reusable bar (System checks → Documents → Remarks →
   one action row), with a reason required for Reject and Return.
2. **Approvers had to download every file.** The fix: a preview overlay
   (PDF iframe, image, or "Preview not available" for Word), inline routes
   under the same authorisation as the downloads, and a Documents section
   with View and Download.

### Key findings before writing any code

- **Core's `partials/queue.blade.php` has no opt-out.** It always prints a
  download-only `doc-list` and `<x-core::decision-form>` after the module's
  detail view.
- **Only Supervision required a reason to reject;** Certification, GA
  Extension and the appeal accepted an empty one. Every existing test that
  rejects there already sends remarks.
- **Core's CSP needs no change for previews.** `ContentSecurityPolicy` only
  adds its header and `X-Frame-Options: DENY` to HTML responses, so inline
  PDFs and images aren't affected, and the page allows same-origin frames
  through `default-src 'self'`. HTML can never be framed
  (`frame-ancestors 'none'`), so the "not available" state is drawn by the
  overlay, not loaded in the iframe.
- **Both draft-letter previews were already inline;** the GA Extension Form
  and the certification attachment were download-only.

### The design

- **`components/decision-bar`:** one form, using `formaction` for Return
  (it posts the same `remarks` to `ga-extension.return`).
  - A hidden `_row` field brings a server-side refusal back to its own row,
    opened.
  - The script stops a reasonless Reject or Return, relabels the field
    "Reason (required)", fills a reserved one-line message and focuses the
    box.
- **`components/documents` and `components/file-preview`:**
  - View is a real link to the inline route; the overlay intercepts it, so
    without JavaScript the file opens in a new tab.
  - The `<dialog>` uses `showModal()`, plus a Tab trap, close on backdrop
    click, and focus returned to the View link.
- **`Support\ApproverPanel`:** per module, the documents, the primary label
  and hint, and the return action.
  - Supervision: "Endorse & issue letter" at `cgs_review` in `endorse` mode;
    "Approve & issue letter" in `manual` mode, or at the Chair or Dean in
    `automatic` mode.
  - Certification: "Approve & issue letter" for the Senior Director.
  - Appeal: "Approve appeal".
- **`Support\DocumentPreviews`:** file kind and inline MIME by extension
  (pdf, jpg/jpeg, png; everything else is not previewable).
- **Routes:**
  - `nureen.documents.view` (`DocumentPreviewController`): `visibleTo()`,
    Nureen's modules only, activity-logged "previewed";
  - `ga-extension.form-pdf.view` and `ga-certification.attachment.view`
    (same checks as their downloads);
  - `?download=1` on both draft-letter previews.
- **Core's duplicate blocks:** each Nureen `_detail` view is wrapped in
  `.nureen-row`, and one `:has()` rule hides Core's list and form in those
  rows only. §17 proposes `$ownsDecision` to replace it.

### What changed

- **New:**
  - `Http/Controllers/DocumentPreviewController.php`;
  - `Support/DocumentPreviews.php` and `Support/ApproverPanel.php`;
  - components `decision-bar`, `documents` and `file-preview`;
  - view `documents/preview_unavailable`;
  - `tests/Feature/Nureen/DocumentPreviewTest.php` (14 tests).
- **Detail views:** `supervision/_detail` (the "Preview letter" box is gone),
  `ga_certification/_detail` (the same), `ga_extension/_detail` (the
  download button is now a Documents row) and `attendance_appeal/_detail`
  (its own Approve form and CSS hack are gone; the sessions field moved into
  the bar).
- **Controllers:**
  - `GaExtensionController::formPdfView()`;
  - `CertificationController::attachmentView()` and `?download=1`;
  - `SupervisionController::previewLetter()` with `?download=1`;
  - remarks required on reject in `CertificationController`,
    `GaExtensionController` and `AttendanceAppealController`.
- **Student forms:** View links on the GA Extension form (own applications,
  draft as last saved) and the certification form (attachment as last saved).
- **Existing tests:** four assertions follow the new wording (`LettersTest`
  ×2, `GaExtensionFormTest`, `AttendanceDataRulesTest`).

### Bugs caught

- **The overlay opened invisibly** (a 0×0 box). `@once` had rendered it
  inside the first queue row's closed `<details>`, and a modal dialog can't
  show inside a hidden ancestor. The script now moves the dialog to `<body>`.
- **Esc stopped working once focus entered the PDF.** Chrome's viewer keeps
  key presses. The iframe is now out of the Tab order (`tabindex="-1"`) and
  the trap cycles only the overlay's own controls. One limit remains: after
  someone clicks into a PDF, they close with Close or a click outside.
- **Two new test assertions matched the component's own script text**
  (`data-decision-error`). They now count the rendered attribute.

### Verification

- **Full suite:** 652 passed. `failed_jobs` and `jobs` were 0 before and
  after.
- **Headless browser:** full Chromium (new headless). The default headless
  shell has no PDF viewer and turns a framed PDF into a download, which is a
  test-browser limitation, not the app.
  - **As `cgs@`, in light, dark and at 400px:**
    - Supervision #199, Certification #198 and GA Extension #197: the order
      was System checks → Documents → Remarks → actions;
    - Core's duplicate form and list are hidden;
    - Endorse/Approve and Reject (and Return on GA Extension) are 44px tall,
      side by side, and full width at 400px;
    - no horizontal scroll.
  - **The overlay:**
    - the draft appointment letter, a PDF attachment and the generated GA
      Extension Form each opened in it (1296×810 at 1440×900; full screen
      at 400px);
    - focus started on Close and Tab stayed inside;
    - Esc closed it and returned focus to the View link, and a click outside
      closed it at desktop width.
  - **Empty Reject:** stayed on the page, read "Reason (required)", showed
    "Give a reason before rejecting: the student is told why." and focused
    the box.
  - **Real rejections:**
    - #200 (GA Extension, past candidacy) rejected by `cgs@` with a reason;
    - #201, a fresh certification from `student2@` for this check, viewed by
      `supervisor@` in light and dark, then rejected with a reason.
  - No console errors.
- **Dev data:** #200 is now rejected and #201 is new and rejected. #197, #198
  and #199 stay pending.

---

## 2026-11-07 — Postgraduate Handbook 2025 rules across all four modules

**Branch:** `nureen/forms-and-drafts`, uncommitted. Nureen only: no Core edit.
SHARED_CHANGES §16 has the proposals.

### The ask

Cross-checked against the Postgraduate Handbook 2025, the GA Terms &
Conditions and the CGS onboarding briefing. New rules warn in the
approver's System checks; every number and switch goes in config.
- **Attendance:**
  - only full-time research students are monitored, behind a switch if no
    reliable source exists;
  - approved annual leave (14 days: Jan 5, May 4, Sept 5, no carry-over)
    isn't absence: CGS records it per student or through an optional sheet
    column, and is warned over the allowance;
  - the alert states the real consequences.
- **GA Extension:**
  - eligibility (full-time research);
  - `allowed_levels` (MSc + PhD by default; PhD-only blocks MSc);
  - the candidacy limit;
  - PhD Stage Gate Level 3's two options.
- **Supervision:**
  - the PGFAC memo for a GA changing supervisor;
  - a configurable Co-Supervisor approver (Chair by default, or Dean of PGR).
- **Certification:** a non-blocking full-time-research note and warning.

### Key findings before writing any code

- **Nothing reliable knows study mode or type.** `users` has no such column.
  `programme` is free text: "PhD Part-Time" on some students, "Master of
  Science (…)" on others (never research vs coursework), and NULL on 19.
  No teammate's module stores it. The only declarations are this module's
  own GA/GRA Certification (type, mode, level) and GA Extension (mode,
  level). So: `attendance.scope` defaults to `'all'`, plus SHARED §16a.
- **The GA Extension Form never asks research vs coursework,** so eligibility
  had nothing to check. A "Programme type" field was added (the PDF prints
  it beside the mode).
- **Section C couldn't tell an accepted article from a published one,**
  which PhD Level 3 option 2 needs. Each row now has a status.
- **Core already has a Dean of PGR role** (`Role::DEAN_PGR`, `dean@utp.edu.my`,
  used by Norhanis's Travel chain), so `'dean_pgr'` was built rather than
  only documented.
- **Core's dashboards read attendance only through Nureen's
  `AttendanceProvider`,** so applying the scope there keeps Core consistent
  without touching it.
- **Leave units:** one leave day is one UTrace session by default
  (`leave.sessions_per_day`). Leave can only cover sessions actually missed
  and not already excused.
- **Core's CGS sidebar lists attendance links by hand,** so a new page's link
  from `links()` doesn't show there (SHARED §16b).

### The design

- **`Support\StudyProfile`:** mode, type and level from the latest submitted
  Certification, then GA Extension, then the programme text. Drafts never
  count.
- **`Support\AttendanceScope::monitors()`:**
  - `'all'` → yes;
  - `'full_time_research'` → `StudyProfile`'s answer, with unknown students
    following `scope_unknown` (default `'include'`, so nobody drops out of
    the early warning just because nothing is on record).

  It is asked by `AttendanceRiskEvaluator::isAtRisk()`/`assess()`,
  `AttendanceEarlyWarning::check()` (which also clears a stale state and
  flags when someone falls out of scope) and `AttendanceProvider`.
- **Leave:**
  - `attendance_records.leave_days` is folded into
    `countedAttended()`/`countedTotal()`/`sessionsMissed()` beside
    `sessions_excused`, so both `excused_sessions` rules apply.
  - It is written by `recordPeriod(..., ?int $leaveDays)` (null keeps the
    value) and `recordLeave()`.
  - The sheet's fifth column `leave_days` is read only when the header says
    so, and the push API accepts it.
  - `Support\AttendanceLeave` works out usage per semester (by period-end
    month) and per year, plus the warnings.
- **GA Extension:** `GaExtensionRules` gains `eligibility()`, `candidacy()`
  and `phdLevelThree()`. `allowed_levels` is a submit-only validation rule,
  so drafts still save.
- **Supervision:**
  - Snapshotted at submission: `is_change_of_supervisor` (a Main request
    while another supervisor is on record), `is_ga` with `ga_status_source`
    (`Support\GaStatus` from an active `GaAppointmentRecord` of `ga_types`,
    else the student's declaration), and `co_supervisor_approver_snapshot`.
  - `SupervisionWorkflow::approverStage()` gives `chair` or `dean_pgr`.
  - The superset lists the Dean's stage only while the switch is on or
    something waits there.
- **Certification:** `GaCertificationDetail::eligibilityCheck()`, plus a
  form note shown by script and rendered visible for a draft that needs it.

### What changed

- **New:**
  - migrations `2026_11_07_000100` (`leave_days`) and `2026_11_07_000200`
    (`study_type` and the four supervision columns);
  - `Support/StudyProfile`, `AttendanceScope`, `AttendanceLeave`, `GaStatus`;
  - views `attendance/leave` and `attendance/_leave_warnings`;
  - routes `attendance.leave` and `attendance.leave.store`;
  - four test files (59 tests).
- **Config:**
  - `attendance.scope`, `scope_unknown`, `leave`;
  - `ga_extension.allowed_levels`, `eligible`, `applicant_fields.study_type`,
    `publications.statuses`, `rules.max_candidacy_years`, `rules.phd_level3`;
  - `ga_certification.eligible`;
  - `supervision.co_supervisor_approver`, `pgfac_memo`;
  - `charter_days.supervision.dean_pgr`.
- **Changed:**
  - **Attendance:** `AttendanceRecord`, `AttendanceIngestor`, `IngestSummary`,
    `AttendanceSheet`, `AttendanceIngestController`, `AttendanceController`
    (leave actions, upload warnings, at-risk banner),
    `AttendanceRiskEvaluator`, `AttendanceEarlyWarning`,
    `AttendanceProvider`, and the `AttendanceAtRisk` email;
  - **GA Extension:** `GaExtensionRules`, `GaExtensionController`,
    `GaExtensionDetail`, and the GA Extension form and PDF;
  - **Supervision:** `SupervisionWorkflow`, `SupervisionController`,
    `SupervisionDetail`, the Supervision form and `_detail` (a new System
    checks panel), `LetterRenderer` (the Dean in automatic-mode wording),
    `RemindStalledSupervisionRequests`, `ApplicationDetailsContent` (who it
    waits for), and the routes (DEAN_PGR on the Supervision queue);
  - **Certification:** `GaCertificationDetail`, the certification form and
    `_detail`;
  - the history, upload, at-risk and appeal-form attendance views.
- **Existing tests:**
  - the shared GA Extension payload gained `study_type`;
  - publication fixtures gained a status;
  - one `SupervisionTest` change-of-supervisor case now declares "not a GA".

### Bugs caught

- **A one-line `@php(...)` placed before a `@php … @endphp` block** in
  `ga_certification/_detail` (the same Blade trap as on 5 Nov). Changed to
  block form before it shipped.
- **Two test assertions were trivially true:** `assertSee('data-level-blocked')`
  matched the script's own query selector. They now assert on the element's
  markup.

### Verification

- **Full suite:** 638 passed. `failed_jobs` and `jobs` were 0 before and
  after.
- **Headless browser.** Applications went in through the real form
  endpoints (with their CSRF tokens) as the students, were approved by the
  real supervisors and Chair, then checked as `cgs@` in light and dark mode:
  - **#197** (`student2@`, PhD part-time): ✗ Full-time research student; ✓
    Stage gate "Level 3, option 2 … accepted and … published, with both
    proofs attached".
  - **#200** (`divya.sri.kumar@`, PhD admitted Feb 2019): ✗ "Candidacy ends
    31 Jan 2027 … No allowance is paid beyond candidacy" (and the existing
    42-month check also fails).
  - **#198** (`student2@`, certification, part-time): ✗ with the "former GA"
    note. The form note appeared on picking Part-time.
  - **#199** (`divya.sri.kumar@` changing to Dr Farid Kamal, no GA record,
    declared GA):
    - without the memo, refused with "As a GA changing supervisor, attach the
      PGFAC Approval Memo …";
    - with it, CGS sees "Change of Main Supervisor · Graduate Assistant
      (declared by the student) · ✓ PGFAC Approval Memo (1 file)".
  - **`student2@`** has an expired GRA on record, so she isn't asked and no
    memo is needed.
  - **Leave:** 4 + 3 days recorded for 22001011 in the September semester
    showed "7 days recorded, allowance 5" on the leave page, the warning and
    the At-Risk banner. Both were then reset to 0, and his at-risk flag
    came back.
  - No console errors.
- **Dev data left:** #197–#200 (pending at the CGS stage, reasons starting
  "Handbook check") as demo records of the warnings. #199 is NOT endorsed:
  endorsing would change Divya's main supervisor.
- **Not checked in the browser:** `scope = full_time_research` and
  `co_supervisor_approver = dean_pgr` (both config switches; the dev `.env`
  is on the defaults). Both are covered by tests.

---

## 2026-11-06 — GA Extension Form generated instead of uploaded (UTP/CGS/001-A2)

**Branch:** `nureen/forms-and-drafts`, uncommitted. Nureen only. There was no
Core edit; one Core proposal is in SHARED_CHANGES.md §15.

### The ask

Stop students uploading a filled-in GA Extension Form. Collect its data and
generate the official form as a PDF:
- **Section A:** checklist ticks computed from what is attached; the CGS
  column ticked once CGS passes the application.
- **Section B:** the profile fields read-only, plus fields the student
  enters. Account no. and IC/passport no. are encrypted and masked except at
  the CGS stage. Bank details sit behind a config flag.
- **Section C:** publications as add/remove rows, stored as JSON.
- **Section D:** the declaration, with a timestamp.
- **Section E:** names, roles and dates from `approval_history`, respecting
  the application's own chain.
- **Section J:** remarks filled by CGS staff.

Also:
- The PDF is downloadable by the owner at any time and by every approver.
- The Terms and Conditions become warning-level checks on the System
  checks panel, with all numbers in config.
- `ga_extension_form_documents` is removed from `required_documents`, while
  older applications keep their upload.

### Key findings before writing any code

- **The PDF was not on this branch.** It is only on
  `feature/track-applications-redesign`, so I read it from there (pages 1–4)
  with `git show`, without adding it here. That file was not modified,
  as asked earlier.
- **The form has a Section D that isn't labelled "D".** It is the
  certification sentence with Signature / Name / Date lines. After Section E
  comes J; there are no F–I.
- **Section A item 4 differs by stage gate level:** acceptance for Level 2,
  published article for Level 3, soft bound for PhD Level 4. The only upload
  was one combined "Acceptance Letter or Published Paper", which was
  required, so a Level 1 student couldn't submit, and a check couldn't tell
  which proof was given.
- **Item 2 (Admission & Offer letter) was never collected.**
- **`encrypted` works on a column, not on one key inside the
  `additional_info` JSON.** So Section B is real columns.
- **A boundary month in the stage gate tables sits in two rows.** MSc Level
  2 is "Month 12 to Month 18" and Level 3 is "Month 18 to Month 24".
- **Dompdf has no `counter(pages)`;** the first render said "Page 1 of 0".
- **Script order:** an inline script calling `location.assign()` before
  `form-stepper`'s pushed script left the page without its steps. The
  browser check caught it.

### The design

- **Migration `2026_11_06_000100`:** adds to `ga_extension_details`:
  - `mode_of_study`, `admission_date`, `candidature_period`,
    `level_of_study`, `contact_no`, `contact_email`;
  - `bank_name`, `bank_account_no` (encrypted), `identity_number`
    (encrypted);
  - `holds_visa`, `visa_expiry_date`;
  - `publications` (JSON), `declaration_accepted_at`;
  - `cgs_remarks` (JSON), `cgs_remarks_by_id`, `cgs_remarks_at`.

  All nullable, for drafts and older rows.
- **Config, `nureen.ga_extension`:**
  - `applicant_fields`: label, type, required, options, max, step, and the
    `bank`, `sensitive` and `required_if` flags. The form, validation and
    storage are all built from it.
  - `collect_bank_details`, `publications` (types, databases, quartiles,
    max rows), `declaration`, `cgs_remarks_fields`;
  - `rules`: max months per level, extension min/max, start and end days,
    notice months, and the stage gate tables;
  - `stage_gate_documents` and `legacy_documents`;
  - `additional_fields` stays for anything without a column.
- **Required documents:**
  - IC/passport/visa, **Admission & Offer letter (new; item 2 of the
    official checklist)**, and the supervisor's letter;
  - **the combined proof is replaced by three optional stage gate proofs.**
    The rule check says which one the level needs.
- **Masking:** `GaExtensionDetail::revealsSensitiveTo()` is true only for
  the role of the `cgs_verify` stage, read from the workflow. The student,
  the supervisor and the Senior Director all see `•••• 1234`, on screen and
  in the PDF.
- **`Support\GaExtensionRules`:** six checks, each pass, fail or unknown
  with a reason.
  - **The stage gate level** is the highest level whose `from` is at or
    below the whole months completed since admission at the extension's
    start. 18 months completed is month 19, so MSc Level 3.
  - **The old combined proof** passes Levels 2–3 with "check it is the
    right one".
- **Only one hard rule:** a backdated start is refused at submission,
  because the terms say "Backdated appointment … is not allowed".
- **Section E:** for each of `supervisor`, `cgs_verify` and
  `senior_director`, the latest decision after the latest `returned` row,
  and only for stages on `stagesFor($application)`. A Senior Director
  column on the `cgs` chain stays blank.
- **Downloads:**
  - the owner (drafts included);
  - the named supervisor (or whoever endorsed);
  - anyone holding another stage's role on that chain.

  For the student, the link is on the draft form ("Download the form
  (PDF)" saves first, then auto-downloads) and in a list of their
  applications on `/ga-extension/new`. Core's application page has no slot
  for it; SHARED §15 proposes one.

### What changed

- **New:**
  - the migration;
  - `Support/GaExtensionRules.php`;
  - views `letters/ga_extension_form`, `ga_extension/_field` and
    `ga_extension/_publication_row`;
  - tests `GaExtensionFormTest` (26) and `GaExtensionRulesTest` (13), and
    the `Concerns/FilesGaExtensions` payload.
- **`GaExtensionController`:**
  - `form()` passes the fields, options, rules and submitted list;
  - `saveDraft()` and `store()` are built from `applicantRules()`,
    `applicantValues()`, `normalisePublications()` and
    `publicationRules()`;
  - the backdated block;
  - new `formPdf()`, `canViewForm()` and `saveCgsRemarks()`;
  - the queue eager-loads `cgsRemarksBy`.
- **Routes:** `ga-extension.form-pdf` (auth, checked in the controller) and
  `ga-extension.cgs-remarks` (Non-Executive CGS).
- **`GaExtensionDetail`:** casts, `revealsSensitiveTo()`,
  `sensitiveFor()`, `mask()`, `extensionStart()`, `extensionMonths()`,
  `extensionSummary()`, `publicationRows()`, `citation()`,
  `databaseAndQuartile()`.
- **`LetterRenderer`:** `gaExtensionForm()`, which stamps "Page X of Y" on
  the canvas, and `gaExtensionFormData()`.
- **`ga_extension/form.blade.php`:** rewritten into Your details →
  Identity & bank → Extension details → Publications → Supporting documents
  → Declaration (then Review). It has the visa toggle, add/remove rows, a
  live stage gate hint, the download button and the auto-download.
- **`ga_extension/_detail.blade.php`:**
  - the PDF link and the Section B summary (masked);
  - publications;
  - System checks: documents, including legacy ones, plus the rules;
  - Section J: a form for CGS at their stage, read-only otherwise.
- **Existing tests:** `GaExtensionTest`, `DraftsTest` and
  `SnapshotChainReachabilityTest` use the shared payload and the new
  document types. Their fixed dates became relative where a fixed date
  would one day be backdated.
- **Docs:** README ("The GA Extension Form, generated" and test rows) and
  SHARED_CHANGES §15.

### Bugs caught

- **The empty stepper after the auto-download:** an inline script
  navigated before `form-stepper` had run. It is now in the `scripts`
  stack, after the stepper, and waits for `load`.
- **"Page 1 of 0":** fixed by `page_text()` on the Dompdf canvas.
- **A–E spilled onto a second page;** tightened to one, as on the paper
  form.
- **Fixed test dates** (`2026-12-31`) in `SnapshotChainReachabilityTest`
  would have become backdated, and so refused, after December; they are
  now relative.

### Verification

- **Full suite:** 527 passed (`QUEUE_CONNECTION=sync`, `MAIL_MAILER=array`).
  `failed_jobs` and `jobs` were 0 before and after.
- **Headless browser on the dev app** (`cgs` chain, the configured
  default): student 22001001 → `supervisor@` → `cgs@` (final).
  - **Student:** every step was filled.
    - The visa date shows only on "Yes".
    - Two publication rows were added and one removed.
    - The stage gate hint read "16 months … Level 2 … Attach Proof of
      Journal Article Acceptance".
    - "Download the form (PDF)" saved draft #194, returned to the
      Declaration step and downloaded a 2-page PDF: student ticks only,
      blank D/E, masked numbers.
    - The On file hints showed `•••• 5566` and `•••• 1234`.
    - Submitted from Review.
  - **Supervisor:** the queue showed the numbers masked and all six checks
    passing. The PDF had the endorsement blank, and the supervisor
    approved.
  - **CGS** (dark mode): filled and saved Section J ("Last saved … by M
    Syahmi Ifwat M Jafri") and saw the numbers in full. The PDF showed the
    supervisor's endorsement and full numbers, and CGS approved.
  - **Student again:** #194 listed as Approved. The final PDF has the CGS
    ticks, Section E with the supervisor and CGS ("Approved
    electronically"), the Senior Director column blank, and Section J
    filled on page 2. No console errors.
- **Dark mode:** checked on the student form (Your details,
  Identity & bank, Publications) and the CGS queue.
- **Not checked in the browser:** the `senior_director` chain (the dev
  `.env` is on `cgs`; the tests cover it, including Section E's three
  names) and the no-JavaScript fallbacks.
- **Dev data:**
  - #194 (approved) is left as the demo record;
  - drafts #191 and #192 and pending #193, all created by my two failed
    runs minutes earlier, were deleted with `Drafts::delete()`;
  - #184 and #187 (earlier sessions) were left alone.

---

## 2026-11-05 — GA/GRA Certification form aligned with CGS's online form

**Branch:** `nureen/forms-and-drafts`, uncommitted. Nureen only: no Core
edit was needed, so there is no new SHARED_CHANGES section.

### The ask

Make the GA/GRA Certification Letter form match CGS's official online form:
- **Student details:** profile fields shown read-only; Level, Level of Study
  and Mode of Study as selects.
- **Application type:** Reason/Purpose and GA/GRA.
- **Supporting details:** MyKad/Passport number, and PDF-only supporting
  documents.
- **Approval & notification:** personal email, and the supervisor's email
  from the profile.
- **Agreement:** a declaration.

Also:
- Keep the GA/GRA type fields, and put the period behind a config switch.
- Add a "Download pre-filled attachment" that recreates CGS's course-owner
  attachment and works from a draft.
- Mask the identity number for everyone but CGS staff, and keep drafts
  working.

### Key findings before writing any code

- **The stepper showed an empty step:** `form-stepper` shows every `.fstep`
  in turn and sets `hidden` itself. The committed form had one fieldset per
  type (`data-type-fields`), so a GA applicant was walked through an empty
  "GRA details" step of disabled fields. The browser check caught it, and it
  was already in HEAD.
- **The stepper's "first submit button" rule** (see the drafts entry) means
  the attachment download can't be a submit button inside a step. It is a
  `type="button"` that saves the draft with a flag, plus a `<noscript>`
  submit fallback.
- **Dompdf ignores `<colgroup>` widths,** so the attachment's columns are
  sized on the `th` cells.
- **`AppointmentVerifier::verify()` required both period dates.** With
  `ask_period` off and the (also optional) appointment check on, the queue
  would have thrown a TypeError.

### The design

- **Migration `2026_11_05_000100`:** six nullable columns on
  `ga_certification_details`: `study_level`, `level_of_study`,
  `mode_of_study`, `identity_number` (text, `encrypted` cast),
  `personal_email` and `declaration_accepted_at`. They are nullable because
  drafts must save half-filled. "Required" lives in `store()`'s validation,
  as with the drafts work.
- **Config, `nureen.ga_certification`:**
  - `options`: the three option lists, which feed both the `in:` rules and
    the selects;
  - `ask_period` (env `NUREEN_GA_CERTIFICATION_ASK_PERIOD`, default true);
  - `declaration`: its text;
  - `supporting_document_types`: `['pdf']`.
- **Profile fields** are rendered from `auth()->user()` and never read from
  the request, so there is nothing to override.
- **Identity number:**
  - `GaCertificationDetail::identityFor($viewer)` gives the full number for
    `isCgs()` and `•••• 1234` otherwise;
  - the form never redisplays it ("On file: … Leave blank to keep it."), and
    a blank submit keeps the stored value.
- **Attachment:**
  - `LetterRenderer::certificationAttachment()` renders the new
    `letters/certification_attachment` view: name and matric, programme,
    today, and the first of five course rows (for a GRA, grant code /
    project / leader). Signature and stamp are blank.
  - Route `ga-certification.attachment`: owner only.
  - The button sets `download_attachment=1` and clicks Save draft. The draft
    page it returns to carries a `data-auto-download` link, which the
    script follows.
- **Period:** when `ask_period` is false it is hidden, has no rules, isn't
  stored, and the letter drops the Period row. The letter's first paragraph
  then says "during the {semester} semester". When no period is given, the
  appointment check uses today.
- **Letter:** adds a "Level of Study" row. The identity number and personal
  email are not printed.

### What changed

- **New:**
  - the migration above;
  - `letters/certification_attachment.blade.php`;
  - `tests/Feature/Nureen/CertificationOnlineFormTest.php` (17 tests).
- **`CertificationController`:**
  - `form()` passes the profile, options, `askPeriod`, the declaration text,
    the allowed types and the pending download;
  - `saveDraft()` and `store()` take the new fields, through
    `onlineFormRules()`, `periodRules()`, a PDF-only
    `supportingDocumentRules()` and a declaration `accepted` rule (submit
    only);
  - new `attachment()`;
  - the queue eager-loads the supervisor.
- **`ga_certification/form.blade.php`:**
  - laid out as Student details → Application type → GA/GRA details →
    Supporting details → Approval & notification → Agreement (then the
    stepper's Review);
  - the GA and GRA fields are now one step;
  - the attachment block and the auto-download.
- **`ga_certification/_detail.blade.php`:**
  - adds department/programme, the study summary, the masked identity, the
    personal email, the supervisor's email and the declaration time;
  - the period is now null-safe.
- **`components/multi-file-upload`:** a new `allowed` prop, so the help
  text and client-side check say "PDF only".
- **`GaCertificationDetail`:** fillable, casts, `identityFor()`, `mask()`,
  `studySummary()`.
- **`LetterRenderer`:** the attachment, plus the period and Level of Study
  in the letter. **`AppointmentVerifier`:** nullable period.
- **Existing tests:** the `CertificationTest`, `DraftsTest` and
  `LetterWorstCaseTest` payloads gained the new required fields.
- **README:** a new "GA/GRA Certification form" section and a test-table
  row.

### Bugs caught

- **The empty "GRA details" step** for GA applicants. It was already in
  HEAD; the fix merges both types into one step.
- **A Blade parse error:** `@php($x = …)` in `_detail` broke compilation,
  because the same file has a `@php … @endphp` block. It is now one block.
- **The queue crash,** found while reading the code: `AppointmentVerifier`
  would have thrown on a null period with `ask_period` off.

### Verification

- **Full suite:** 488 passed (run with `QUEUE_CONNECTION=sync` and
  `MAIL_MAILER=array`). `failed_jobs` and `jobs` were 0 before and after;
  nothing was deleted.
- **Headless browser,** as `student@utp.edu.my` (22001001):
  - filled every step and went to "Download pre-filled attachment" on
    Supporting details;
  - the draft was saved (#190), the page returned to the same step, and the
    PDF downloaded as `GA_GRA_Certification_Attachment_22001001.pdf`;
  - the PDF is one page, with the name, matric, programme, date and
    TDB2073 / Database Systems / Dr. Aisyah Rahman in row 1;
  - the identity number showed as "On file: •••• 5678";
  - Continue on Agreement was blocked until the declaration was ticked, and
    the Review step listed everything;
  - submitted.
- **Approvals:**
  - `supervisor@` saw the identity masked;
  - `cgs@` (in dark mode) and `director@` saw it in full;
  - each approved, and #190 ended `approved`.
- **Final letter,** downloaded as the student through `documents/61`: one
  page, period printed, a "Level of Study: PhD, Research, Full-time ODL"
  row, signed by the Senior Director.
- **The attachment route after submit:** 200 for the student, 403 for the
  supervisor.
- **Dark mode:** the form's Student details and Supporting details steps
  checked. No console errors.
- **Not done here:** an `ask_period=false` browser run (it is covered by
  tests), and a no-JavaScript run.

---

## 2026-11-04 — Save draft for GA Extension and GA/GRA Certification

**Branch:** `nureen/forms-and-drafts`, uncommitted. Nureen only: no Core edit;
Core follow-ups are in SHARED_CHANGES.md §14.

### The ask

Some documents take days to collect, so a student must be able to save a
half-finished GA Extension or Certification request and come back later.
- **Storage:** use Core's existing draft support (status `draft`,
  `current_stage` NULL), with no new table.
- **Saving:** light validation; files attach immediately.
- **Submitting:** the full validation, reusing the draft.
- **Access:** only the owner can see or touch a draft.
- **My Drafts:** a page listing them.
- **Continuing:** pre-fill, list attached files, reopen on the last step.
- **Never part of the workflow:** no queue, reminder or analytics.

### Key findings before writing any code

- **Drafts never persisted before:** both controllers created a draft and
  submitted it within one transaction. Making drafts persist exposes them
  to Core: Track My Applications lists every application (a draft would
  show the first stage as "current"), and the student dashboard panel
  labels it "Pending Approval". Core is out of bounds, so SHARED §14 a–c.
- **Approver queues already exclude drafts:** they use
  `WorkflowEngine::queue()`, which reads pending rows only.
  `StalledApplicationReminder`, the supervision reminder, the Client
  Charter, the overdue list and the calendar all read pending (or approved)
  only. `ProcessingTimeAnalytics::complianceByStageAndModule()` loaded
  every application; it now excludes drafts by name.
- **`summary()` would crash on a draft:** both workflows assumed the dates
  were set, and Core's tracking page calls it.
- **`DocumentStore` can't remove a file** (SHARED §14e).
- **`form-stepper` can't open on a given step** (SHARED §14d). It also
  treats the FIRST submit button in the form as "Submit" and moves it, so
  Save draft must come after Submit, and per-file "Remove" has to be a
  checkbox, not a submit button inside a step.
- **This branch starts at `c18a3e4`,** before the `phpunit.xml` fix on
  `feature/track-applications-redesign`. So the full suite was run with
  `QUEUE_CONNECTION=sync` and `MAIL_MAILER=array` set for the test process,
  and `failed_jobs` stayed at 0.

### The design

- **Migration `2026_11_04_000100`:** makes the fields a half-finished form
  can't fill nullable:
  - GA Extension: `current_end_date`, `requested_new_end_date`,
    `reason_for_extension`;
  - Certification: `appointment_type`, `period_start`, `period_end`.

  It also adds `draft_step` to both detail tables. `down()` deletes drafts
  first, then restores NOT NULL.
- **`Support\Drafts`:**
  - `ownedBy()`: 404 if not this module or no longer a draft, 403 if not
    the student's own;
  - `of()`, `lastSaved()` (the touched `updated_at`), `attached()`;
  - `removeDocuments()` (stored file and row);
  - `delete()` (files, documents, detail row, application).
- **`saveDraft()`** (create, then update):
  - nullable rules only;
  - documents through `DocumentStore::manyRules($field, false)` plus a
    limit counting already-attached files;
  - ticked `remove_documents[]` applied;
  - redirects to the draft's Continue URL.
- **`store()`:**
  - optional `draft_id`, owner-checked first;
  - the full existing validation, except that a document type the draft
    already has (after removals) is no longer required;
  - in the transaction the draft row is locked and re-checked, so it can't
    be submitted twice;
  - the supervisor is snapshotted at submission, and `draft_step` cleared;
  - a Certification's files are relabelled to the submitted type;
  - then `$engine->submit()`.
- **Forms:**
  - **Pre-fill:** every field via `old(..., $detail->…)`.
  - **Attached files:** the upload component's new `existing` prop lists
    them with a link and a **Remove** checkbox. They count towards the
    limit, the label reads "(1 attached, up to 4 more files)", and the input
    is no longer required.
  - **Save draft:** `formnovalidate` + `formaction`, placed after Submit.
  - **Shared script:** moves Save draft beside Submit on every step,
    records the current step on save, and on Continue presses the stepper's
    own Continue up to the saved step (stopping early if a step is
    invalid; the server's "open on the error" wins). In the Certification
    form it runs after the GA/GRA script.
- **My Drafts** (`/my-drafts`, a student sidebar link from
  `GaExtensionWorkflow::links()`): module, summary, last saved, file count,
  Continue, and Delete with a confirmation prompt. A fresh form also offers
  the latest draft of that form.

### What changed

- **New:**
  - migration `2026_11_04_000100_allow_drafts_in_ga_extension_and_certification_details`;
  - `Support/Drafts.php`, `Http/Controllers/DraftsController.php`;
  - `Resources/views/drafts/index.blade.php`,
    `Resources/views/partials/draft-form-script.blade.php`;
  - `tests/Feature/Nureen/DraftsTest.php` (12).
- **Changed:**
  - `GaExtensionController` and `CertificationController`: `editDraft`,
    `saveDraft`, `destroyDraft`, draft-aware `store`;
  - both form views and `components/multi-file-upload`;
  - `routes.php`: 8 draft routes and `/my-drafts`;
  - `GaExtensionDetail` and `GaCertificationDetail` fillable;
  - both workflows' `summary()`; `GaExtensionWorkflow::links()`;
  - `ProcessingTimeAnalytics`;
  - README and SHARED_CHANGES §14.

### Bugs caught

- **Additional fields:** marking them nullable for drafts first dropped
  `nullable` along with `required`; now `required` is swapped for
  `nullable`.
- **Certification draft fields:** the first version of the type-field
  handling was wrong for the shared `semester` key; rewritten as "clear all,
  then fill the chosen type".
- **Script order:** the draft script ran before the Certification form's
  GA/GRA script, so resuming would have judged steps on the wrong
  required/disabled state. It is now included last.
- **Two visual issues from the live check:** an attached type was labelled
  "(optional, …)", and Save draft sat 8px higher than Continue. Both fixed.

### Verification

- **Tests:** full suite, 471 passed. `failed_jobs` was 0 before and after.
- **Browser, as `student@utp.edu.my`:**
  - Save draft showed beside Continue on step 1. On the documents step,
    with `ga-form.pdf` attached, Save draft created #187 ("Draft saved at
    3:13am…").
  - After signing out and back in, My Drafts listed it (last saved, 1
    file, Continue, Delete).
  - Continue reopened on **Supporting documents**, with all three fields
    pre-filled and the file listed with Remove.
  - The other three files were added and the request submitted as the
    same #187. My Drafts was then empty.
  - `supervisor@`'s GA Extension queue shows "#187 Ahmad Danial … 4 files,
    today". No console errors.
- **Certification draft:** saved and pre-filled correctly, then deleted.
  Draft #186 (an earlier interrupted run) was also deleted through the
  Delete button.

---

## 2026-11-03 — Every email link opens for its recipient; a recommended demo student

**Branch:** `feature/track-applications-redesign`, uncommitted. Nureen only
(no Core change).

### The ask

1. Delete the 6 kept failed letter emails; don't resend them.
2. Make every email link to a page its recipient can open, with a test per
   email.
3. Recommend one Computing demo student for testing and the viva, and
   confirm a full Supervision chain for them.
4. Leave `app/Modules/Nureen/docs/forms/GA_Extension_Form.pdf` alone.

### Findings

- **Six emails linked staff to pages they can't open:**
  - the supervisor's GA Extension and Supervision outcome copies →
    `applications.index` (students-only, 403);
  - the appointment-letter revocation, sent to student AND supervisor →
    `applications.index` (403 for the supervisor);
  - the Senior Director's GA Extension information email → the GA
    Extension queue, which in `cgs` mode has nothing for them;
  - the certification revocation's supervisor copy, which had no button;
  - the appointment letter itself, which CC'd the supervisor on the
    student's message, so the supervisor's click went to the student's page
    (found during the live check, not in the audit).
- **Core's emails are fine:** ApplicationDecided goes to students only,
  and ApplicationReturned links staff to the dashboard.
- **There was no staff page for one application:** `applications.show` is
  students-only, and queues hold pending rows only.

### What changed

- **A new read-only staff view, `nureen.application`**
  (`/nureen/applications/{id}`, `StaffApplicationController`): progress,
  activity, details and documents, built from Core's ApplicationDetails.
  - **Who may open it** (`Support\StaffApplicationAccess`): the named or
    requested supervisor; anyone who decided on it; a role owning a stage
    of its chain (Chair: own department only; a supervisor only as the
    named one); and for GA Extension the Senior Director.
  - **Scope:** only Nureen's four modules. A document is a link only when
    Core's document rule lets that viewer open it.
- **Email links fixed:**
  - `GaExtensionFinalDecisionCc`, `GaExtensionFinalDecisionInformational`
    and `SupervisionFinalDecisionCc` now link to the staff view.
  - `AppointmentLetterRevoked` and `CertificationRevoked` link the student
    to their details page and the supervisor to the staff view.
  - `AppointmentLetterIssued` is now sent to the supervisor as their own
    message (letter attached, staff-view link) instead of a CC on the
    student's.
- **Tests:**
  - New: `EmailLinksTest` (12): every email this module sends, for each
    recipient, followed as that person and expected to return 200; plus the
    staff view's access limits.
  - Updated: `LettersTest`'s letter-email assertion (no CC; the supervisor's
    own copy).
- **Docs:** `docs/test-accounts.md` gains "Recommended student for testing
  and the viva".

### Recommended demo student

`student@utp.edu.my` (Ahmad Danial, 22001001, Computing). Supervisor
`supervisor@utp.edu.my`, Chair `chair@utp.edu.my` (Computing), CGS
`cgs@utp.edu.my`.

### Verification

- **Tests:** full suite, 511 passed.
- **failed_jobs:** the 6 approved rows deleted (0 left).
- **Supervision #185, live:**
  - submitted as `student@` (Co-Supervisor: Prof. Dr. Kumaran Vellu);
  - endorsed by `kumaran.vellu@`, `chair@` and `cgs@`;
  - letter CGS/SVAPPT/2026/0009 issued;
  - the student's details page shows all three people and the letter.
- **Staff view, live:**
  - `supervisor@` and `director@` open #183 and are refused #185;
  - `kumaran.vellu@` opens #185 only;
  - `farid.kamal@` is refused both.
- **A failure I caused:** the supervisor's outcome copy for #185 failed
  once, because the long-running queue worker had loaded the routes before
  `nureen.application` existed. After `queue:restart` it was retried (by
  UUID; `queue:retry` takes UUIDs, not row ids) and arrived linking to
  `/nureen/applications/185`. `failed_jobs` is empty.

---

## 2026-11-03 — Supervisor safeguards, submission emails, certification revocation emails, failed_jobs clean-up

**Branch:** `feature/track-applications-redesign`, uncommitted.

### The ask

1. **Lee's #183:** option A, with no data change (Lee stays with Dr. Aisyah
   Rahman). Then build the four safeguards:
   - a. block submission without a supervisor who can act;
   - b. name who the request is waiting for;
   - c. a CGS list of stuck requests, with reassignment;
   - d. demo supervisors documented, with test-accounts.md listing who each
     supervisor and Chair covers. Check every student with a Supervision
     request has a Chair for their department.
2. **Submission emails:** email whoever acts first, with the charter due
   date and a queue link (named supervisor; Non-Executive CGS for appeals).
   Keep it in Nureen's code.
3. **Certification revocation:** email the supervisor too.
4. **failed_jobs:** list the old rows, delete the leaked test jobs, and keep
   and show any real ones.

### Findings

- **Chair coverage:** a seeded database has Chairs for Computing and
  Chemical Engineering only. All five Electrical & Electronics students
  (including Lee and Tan Wei Jian) have none; SHARED §13.
  `chair-electrical-electronics-engineering@utp.edu.my` and six `2200331x`
  students in test-accounts.md are hand-made accounts no seeder creates;
  they are now marked as such.
- **Nureen's demo seeders** only assign `supervisor@utp.edu.my`. The
  `letters-*`, `upload-*` and `hafiz-*` students came from verification runs.
  `upload-22092202` has no supervisor; left alone and documented.
- **Certification revocation** emailed nobody, not even the student.
- **failed_jobs:**
  - 8,098 rows, of which 8,092 were leaked test jobs (11 Oct – 3 Nov, all
    failing on the tests' SQLite database), deleted.
  - 6 are real: 4 `AppointmentLetterIssued` and 2 `CertificationIssued`,
    29 Oct – 1 Nov. They failed on the since-fixed `attachFromStorageDisk`
    bug, including the letter email for Lee's #26. Kept, not retried.

### What changed

- **a.** `Support\SupervisorCheck`: a missing supervisor, or one whose
  account lacks the Supervisor role, blocks GA Extension and Certification,
  as a warning on the form and again on submit.
- **b.** `DescribesApplicationDetails::waitingFor()`, in Core with a null
  default. It names the supervisor on the request, or the one Chair of the
  student's department. The details page shows "Waiting for Dr. Aisyah
  Rahman, your supervisor."
- **c.** `Support\StuckSupervisorStages` and `/reviews/stuck` (Non-Executive
  CGS, a "Stuck Reviews" sidebar link) list:
  - requests at a supervisor stage whose named supervisor is missing or not
    a Supervisor;
  - Supervision requests at a Chair stage with no Chair for the
    department.

  Reassigning a GA Extension or Certification changes the detail row's
  supervisor, writes an approval_history `reassigned` row with CGS's reason
  (status and current_stage untouched), and emails the new supervisor.
  `ProcessingTimeAnalytics` treats `reassigned` as a clock restart, not a
  decision. Supervision requests are listed but not reassignable: the named
  supervisor is the appointment being requested.
- **d.** `docs/test-accounts.md` gains "Who covers whom" (every supervisor's
  students, every Chair's department, the E&E gap), Lee's corrected row
  with the reason, and markers on the hand-made accounts. A test proves
  every student Nureen's seeders create has a documented supervisor.
- **Submission emails:** `Support\SubmissionNotifier` and
  `Notifications\ApplicationAwaitingYou`, called from the four controllers
  after submit. They are skipped for a recipient whose role can't act at
  that stage.
- **Revocation emails:** `Notifications\CertificationRevoked` goes to the
  student and the request's supervisor. The hint is now true for both
  letters.
- **Tests:** new `SupervisorSafeguardsTest` (11).

### Verification

- **Tests:** full suite, 499 passed.
- **Browser walkthrough:**
  - `student@` submitted GA Extension #184, and `supervisor@` received "GA
    Extension GAEX-2026-00184 is waiting for your endorsement … please
    decide by Friday, 6 November 2026 (3 working days)" with a queue link.
  - `supervisor@` endorsed #183, and `cgs@` approved it.
  - Mailpit received exactly 5 emails: the submission email; Lee's
    endorsement progress; Lee's final approval; the supervisor's copy of
    the outcome; and the Senior Director's information email.
- **Pages:** #184's details page reads "Waiting for Dr. Aisyah Rahman, your
  supervisor." Stuck Reviews loads for CGS and reports nothing stuck.
- **failed_jobs:** unchanged at the 6 kept rows.

---

## 2026-11-03 — Lee's GA Extension "missing" from the supervisor queue, revocation hint, and tests leaking onto the real queue

**Branch:** `feature/track-applications-redesign`, uncommitted.

### The ask

1. **Lee's missing GA Extension:** Lee Xin Yi submitted a GA Extension at
   about 00:01, and "supervisor@utp.edu.my's queue is empty". Diagnose it,
   propose a data fix and wait; then add safeguards (block submission
   without a valid supervisor, name who it is waiting for, a CGS warning
   list with reassignment, and demo supervisors documented).
2. **Revocation hint:** reword it.
3. **Leaking test jobs:** stop test runs reaching the real queue.
4. **A new rule:** never delete failed jobs without listing them and
   asking.

### Findings (#1, awaiting Nureen's OK before any data change)

- **Lee:** id 23, matric 22001015, Electrical & Electronics Engineering,
  `supervisor_id` 1, who is Dr. Aisyah Rahman (`supervisor@utp.edu.my`,
  role supervisor, Computing).
- **#183 (GAEX-2026-00183):**
  - pending at `supervisor`, submitted 3 Nov 00:01:50;
  - mode `cgs`;
  - the detail row's `supervisor_id` is 1;
  - no approval_history yet.
- **The queue rule:** `GaExtensionController::queue()` shows a
  `supervisor`-stage row only to the user whose id equals
  `ga_extension_details.supervisor_id`. Supervisors are not
  department-scoped (`Role::isDepartmentScoped()`: Chair and Academic
  Executive only). **`supervisor@utp.edu.my` passes it: #183 is in that
  queue** (checked in a browser).
- **Why it looked empty:** `docs/test-accounts.md` lists Lee's supervisor
  as **Dr. Farid Kamal** (`farid.kamal@utp.edu.my`), and his queue is
  empty. Lee's supervisor changed on **1 Nov 05:41**, when Supervision #26
  (Main Supervisor: Dr. Aisyah Rahman) was approved by `cgs@`; #58 was
  approved again at 07:01. Those approvals fall in the letter-verification
  sessions of that day. The doc was never updated.
- **The email:** no submission email exists. `WorkflowEngine::submit()`
  notifies nobody, so a supervisor whose stage comes first is never emailed
  about a new GA Extension or Certification. Nothing for #183 was lost
  among the deleted failed jobs: all of those were leaked test jobs
  failing on the tests' SQLite database. There is nothing to re-send.

### What changed (#2–#4)

- **Revocation hint:** both revoke forms now show "This reason is shown to
  the student and their supervisor. It is not shown on the public
  verification page." under the reason box (`aria-describedby`), and the
  validation messages say the same. Caveat for certifications: SHARED §12.
- **`phpunit.xml`:** every setting is forced as both `<env>` and `<server>`.
  Forced `<env>` alone was tried and still leaked: 121 failed jobs from one
  run, because Laravel reads `$_SERVER`, where PHP copies the container's
  `QUEUE_CONNECTION=redis`. A probe test proved `config('queue.default')`
  was `redis` inside tests. Now a clean full run sends **0** jobs to the
  worker, and `failed_jobs` stays unchanged. SHARED §11.
- **The new rule** is saved as a standing instruction (Claude memory). The
  8,098 old `failed_jobs` rows were left untouched.

### Verification

- **Tests:** full suite, 488 passed. The queue worker received 0 jobs during
  a clean run.

---

## 2026-11-03 — Application details page redesign, and a "done but never decided" stepper bug

**Branch:** `feature/track-applications-redesign`, uncommitted. **Changes Core**
(SHARED_CHANGES.md §10b).

### The ask

First fix: certification #144 showed its Supervisor stage as done while
saying "No decisions recorded yet". Then redesign the student's "View
details" page to `docs/design/application-details.png`, module-neutral:
- breadcrumb back to the list's tab and filter;
- a header with the current holder and the expected date (or the student's
  action);
- progress with who acted and working days against each stage's charter
  target, plus "What happens next";
- an activity timeline;
- "Your application", the documents with the issued letter, and help.

### Key findings before writing any code

- **The bug's source:** `WorkflowEngine::progress()` marked every stage
  before the current position as completed by position alone. #144 (Lee
  Xin Yi, `ga_certification`, at `cgs_verify`, submitted 11 Oct 2026) has
  no history at all: it was submitted on the old chain, before the
  Supervisor stage existed.
- **No design image:** `docs/design/application-details.png` was not in the
  repo, and no image came with the request; the page follows the written
  spec and the Track My Applications visual language.
- **Reminders store only the latest:** one row per application holds the
  latest reminder (time, count, stage) — `application_reminders` for GA
  Extension and Certification, columns on `supervision_details` for
  Supervision. So the timeline shows one reminder event with the count.
- **Two revocation fields:** a revoked letter has a `revoked_reason`, which
  CGS is told "is shown nowhere public", and a separate internal
  `letter_revoke_note`. The page shows the reason to the student whose
  letter it is, and never the note.
- **Page shell rule:** `PageShellTest` requires every signed-in screen to
  open with `<x-core::page-header>`; the header uses it.

### The design

- **The fix:** in `progress()`, a stage before the current position with
  no `approval_history` row is `'skipped'`. It reads loaded history, so no
  extra query on a page of cards. Card and page show "Not required for
  this application" (muted, dashed ring, words).
- **`Core\Services\ApplicationDetails`:** reuses
  `ApplicationTracker::summarise()` (now public), so "needs you" is one
  rule for list and page.
  - **Steps:** who (approver name), when ("Endorsed 7 Oct 2026"), and
    working days on the shared calendar. Reading: "2 of 3 working days",
    "18 working days · target 2" in the overdue colour (with "(over
    target)" for screen readers), "Waiting since …" for the current stage,
    "Next · target 2 working days" for later ones.
  - **"What happens next":** a module's `next`, or a generic line ending
    with whether the student must act.
  - **Activity:** submission, every decision (remarks as a quote), and the
    module's events. Newest first, with the later-recorded event first on
    equal timestamps.
- **A new optional `DescribesApplicationDetails` interface** with a
  defaults trait: `details()`, `timelineEvents()`, `issuedDocument()`,
  `stageTargetDays()`. It is a separate interface, not a method added to
  `DescribesApplicationProgress`, so no implementer can break.
- **Nureen (`Support\ApplicationDetailsContent`):** details per module.
  - **Certification:** the GA course or GRA project fields from the same
    config list the form uses; never the appointment check.
  - **Reminders:** "CGS Staff reminded" (the role, never a person).
  - **Letters:** issued and revoked events; the issued letter with its real
    reference and verification URL (the same route the QR code uses).
  - **Charter targets:** from `ClientCharter::charterDays()`.
- **Layout:**
  - header and progress across the page;
  - two columns below (Activity and Your application; Documents and Need
    help), stacked under 1000px;
  - the stepper turns vertical under 720px.

### What changed

- **Core:**
  - `WorkflowEngine::progress()`;
  - new `ApplicationDetails` service and new contract plus defaults trait;
  - the tracker (public `summarise()`, back-link params, skipped steps);
  - `show()` and its view;
  - the track card;
  - `config/tracking.php` `help` (placeholders);
  - `tracking.css`.
- **Nureen:** `Support/ApplicationDetailsContent.php` (new); the four
  workflows implement `DescribesApplicationDetails`; `TrackingDescriptions`
  adds `next` for letters.
- **Tests:** new `tests/Feature/Core/ApplicationDetailsTest.php` (15).

### Bugs caught

- **The #144 stepper bug,** above.
- **Module events losing their text:** a module's timeline events were
  merged with the defaults on the wrong side of PHP's `+`, so their detail
  and quote were dropped. The module's keys now win.
- **Timeline order on equal timestamps:** "Submitted" listed above
  decisions made in the same second (seen on #154's demo data). Fixed,
  with a test.

### Verification

- **Tests:** full suite, 488 passed; the details page uses 12 queries.
- **Browser**, as the owning student, for:
  - #144 (pending, overdue certification);
  - #154 (approved certification with an unopened letter);
  - #78 (rejected Examiner Nomination, a teammate's module);
  - #9 (pending Travel, a teammate's module).

  Each at 1440 and 390px, light and dark:
  - all returned 200, with no horizontal scroll and no console errors;
  - lowest contrast 4.76:1 (light) and 5.74:1 (dark);
  - `aria-current="step"` on the current stage;
  - two columns on desktop, one on a phone, and a vertical stepper at
    390px.
- **No document URL was requested,** so no letter was marked as opened.

---

## 2026-11-02 — Track My Applications redesign (module-neutral tabs, filters and cards)

**Branch:** `feature/track-applications-redesign`, uncommitted. **Changes Core**
(every file is listed in `SHARED_CHANGES.md` §10).

### The ask

Redesign the student's Track My Applications page to the design in
`docs/design/track-my-applications.png`. Requirements:
- **Tabs:** grouped by a category each module declares about itself.
- **Status filters:** All, Needs you, In progress, Approved, Rejected.
- **Cards:** each with a stepper built from the application's own chain,
  a plain footer sentence and the right buttons.
- **State:** tab and filter kept in the URL; 20 cards a page.
- **Module-neutral:** Core names no module.
- **Shared calendar:** move the working-day calendar into Core with no
  date changes.

### Key findings before writing any code

- **The old page** listed applications with no grouping and no paging; the
  shared `<x-core::stepper>` is also used by `applications.show` and
  Chloe's appeal page, so it is left alone.
- **No calendar in Core:** the only working-day calendar was Nureen's.
- **Download tracking exists:** Core's document download logs every
  "viewed", so "letter not yet opened" needs no new table.
- **No resubmission flag:** only Chloe's Study Candidacy Appeal can be
  returned to the student, and no module says a rejection expects
  resubmission.
- **Tones by hash collide:** no hash of the five category names gives five
  different tones (both crc32 and md5 collided), so the tones live in a
  small config map instead.

### The design

- **Tabs:**
  - **Declaration:** `public const CATEGORY = '<key>';` on the workflow
    class, read by `ModuleRegistry::categoryFor()`.
  - **Labels, tones and icons:** `config/tracking.php` (key → label, fixed
    tone, default icon). Renaming a tab is a config edit; a key not listed
    still gets a tab (headline label, neutral tone); no CATEGORY means
    "Other".
  - **Display:** only tabs with applications are shown; they wrap, with no
    scrollbar.
- **Two passes** (`Core\Services\ApplicationTracker`):
  - **Light pass, every application:** category, status, needs-you and
    last activity, for the counts and filters.
  - **Full build, the page's 20 cards only:** stepper, expected date,
    overdue flag, sentence and buttons.
- **The stepper:** "Submitted", then `WorkflowEngine::progress()`, which
  reads the module's `stages($application)` and therefore its stored
  snapshot.
  - **Done steps:** the decision and date from `approval_history`
    ("Endorsed 13 Oct").
  - **Current step:** "Waiting N working days" (or "Since <date>" in its
    first two days) on the shared calendar.
  - **Rejected step:** a cross, and the chain stops there.
  - **Approved cards:** the stepper is left off, as in the design.
  - **Accessibility:** an `<ol>` with screen-reader text such as "Step 3 of
    4, current: …"; vertical below 720px.
- **Optional contracts:**
  - `DescribesApplicationProgress` (sentence, actions, reference,
    needs_student, resubmission_expected);
  - `SuppliesOverdueStatus` (kept separate from `SuppliesDecisionDates`);
  - the existing `SuppliesDecisionDates` (expected date).

  Core adds the calm overdue note itself, so a module's own sentence cannot
  drop it.
- **"Needs you":**
  - status `returned`;
  - `needs_student` from the module (Nureen: an issued, unrevoked letter the
    student has not opened);
  - a rejection only with `resubmission_expected`.

  The card gets a warm border and a "NEEDS YOU" badge in words, and the
  module's action is the primary button.
- **Calendar:** `Core\Support\WorkingDayCalendar` with `config/calendar.php`
  (`holidays => []`). Nureen's class extends it and adds
  `nureen.charter.holidays`.

### What changed

- **Core and shared** (see SHARED_CHANGES §10):
  - controller, new service, `ModuleRegistry::categoryFor()`;
  - 2 new contracts and the shared calendar;
  - `config/calendar.php` and `config/tracking.php`;
  - the rewritten index view, the new `track-card` component, the
    stylesheets list, new `tracking.css`, and 4 new tokens in
    `tokens.css`.
- **Nureen:**
  - `CATEGORY` and `ICON` on all four workflows;
  - `DescribesApplicationProgress` on Supervision, Certification and GA
    Extension (new `Support/TrackingDescriptions.php`);
  - `SuppliesOverdueStatus` through the charter trait;
  - `WorkingDayCalendar` now extends Core's;
  - GA Extension and Supervision read their detail row once per
    application object (`detailFor()`).
- **Tests:**
  - New: `tests/Feature/Core/TrackApplicationsTest.php` (12).
  - Added: 2 tests in `WorkingDayCalendarTest`.
  - `docs/design/track-my-applications.png` is the design image, copied
    from your message because it wasn't in the repo.

### Bugs caught

- **Queries:** 131 for 20 cards at first. My GA Extension workflow
  re-read its snapshot row every time anything asked for the chain. With
  the detail row cached per application object (never cached before it
  exists), it is now 31.
- **Contradictory sentence:** "0 of 4 required documents received… No
  action needed from you." on older demo rows. The documents sentence now
  appears only when all of them arrived.
- **Shared `.btn` offsets:** a 16px top margin, and a taller
  `.btn-secondary`, misaligned the action row; both are normalised inside
  the card.
- **`layout.css` phone gap:** `.page-header-text` (flex-basis 24rem)
  becomes a 24rem height when the header stacks below 720px, leaving a
  blank gap on every page with header actions. Fixed for this page only,
  and reported in SHARED_CHANGES §10.
- **Verification side effect:** my first browser run fetched Lee's two
  letters, which logged them as "opened". Those two activity rows were
  deleted, and the check now never requests `/documents/`.

### Verification

- **Tests:** full suite, 473 passed.
- **Browser** (Tan Wei Jian: Travel, Examiner Nomination and three of
  Nureen's modules; Lee Xin Yi: two unopened appointment letters), at
  1440/820/390px, light and dark, with the All, Needs you and Rejected
  filters:
  - no horizontal scroll, and tabs never scroll;
  - the stepper is vertical and buttons are full width at 390px;
  - no console errors;
  - lowest text contrast 4.76:1 in light and 5.74:1 in dark;
  - a 2px focus outline on the card buttons;
  - every View details link returns 200.
- **Teammates' data:** no rows were created; their existing dev rows
  rendered without errors.

---

## 2026-11-01 — Attendance backtest: repeated runs, and a tuned projection window

**Branch:** `develop`, uncommitted. Nureen only.

### The ask

(1) Robustness: `--runs=N` repeats the synthetic backtest over seeds 2026,
2027, … and reports every metric as mean ± standard deviation. (2) Tuning:
two settings limit when the projection may flag. They apply in the live
check and the backtest alike, and the original and tuned windows are
compared side by side over ten runs.

### The design

- **One gate:** `AttendanceRiskEvaluator::projectionMayFlag(periodsSoFar,
  semesterPeriods, ?min, ?until)`, true from `projection_min_periods_to_flag`
  (default 4) periods up to `projection_active_until` (default 0.6) of the
  semester, inclusive (0.6 × 14 = period 8). `assess()` reads the window from
  config. The backtester takes both values explicitly (its defaults are the
  original behaviour) so two windows can be compared on one dataset.
  Outside the window the projection is still computed and stored for CGS;
  only the rule flags. The dashboard's Attendance Action card and the
  emails follow automatically, since both go through `assess()`.
- **`--runs=N`** (synthetic only): seeds `--seed` … `--seed+N-1`, each metric
  as mean ± SAMPLE standard deviation. A run where a metric is undefined
  (precision when nothing was flagged) is left out and marked `(n=k)`.
- **`--compare`:** runs "Original" (flag from the first projection, all
  semester) and "Tuned" (the configured window, or `--min-periods-to-flag`
  / `--active-until`) on the very same datasets, with a comparison table for
  rule OR projection: recall, precision, F1 and periods earlier, per
  cut-off. Periods earlier is now also given per cut-off, over truly
  at-risk students flagged by both approaches by then.
- The report header records the exact command, so a reader can reproduce
  it.

### What changed

- `config/nureen.php`: the two settings (env-overridable).
- `Support/AttendanceRiskEvaluator.php`: `projectionMayFlag()`; `assess()`
  applies it.
- `Support/Backtest/AttendanceBacktester.php`: window parameters,
  per-cut-off `mean_lead`, `timeline()` made public as `replay()`.
- `Console/Commands/BacktestAttendance.php`: `--runs`, `--compare`,
  `--min-periods-to-flag`, `--active-until`; summary tables and report.
- Tests: `AttendanceProjectionWindowTest` (new, 5 tests, including the live
  check vs the backtest replay, period by period, for 21 synthetic students
  under both windows) and 3 new `AttendanceBacktestTest` tests.
  `AttendanceEarlyWarningTest`'s three-period rule case now expects reason
  `current`, not `both` (by design: the projection may not flag at three
  periods); the tiny-dataset command test pins the original window.

### Bugs caught

- None. The new consistency test passed first time, over more than 1,000
  period-by-period comparisons.

### Verification

- Full suite: 459 passed.
- `attendance:backtest --synthetic --runs=10 --compare` (seeds 2026–2035,
  200 students each, 38.1 ± 3.4 truly at risk per run), rule OR
  projection, Original / Tuned:

  | Cut-off | Recall | Precision | F1 | Periods earlier |
  |---|---|---|---|---|
  | 4 of 14 (29%) | 48.5 ± 6.7% / 44.8 ± 6.4% | 24.9 ± 5.7% / 28.3 ± 6.4% | 32.8 ± 6.4% / 34.6 ± 6.5% | 0.00 (n=6) / 0.00 (n=6) |
  | 7 of 14 (50%) | 80.8 ± 4.9% / 80.8 ± 4.9% | 32.5 ± 4.5% / 36.5 ± 4.7% | 46.3 ± 5.1% / 50.2 ± 5.1% | 1.54 ± 0.26 / 1.15 ± 0.21 |
  | 11 of 14 (79%) | 100.0 ± 0.0% / 100.0 ± 0.0% | 33.3 ± 3.6% / 35.9 ± 3.5% | 49.8 ± 4.1% / 52.8 ± 3.8% | 2.17 ± 0.19 / 1.81 ± 0.17 |

- Dev data needs no re-check: the demo students have four weekly periods,
  which is inside the window, so their stored outcomes are unchanged.
- 93 failed jobs that teammates' tests leaked onto the dev queue during the
  suite run were deleted (the known §7 issue).

---

## 2026-11-01 — Attendance: a backtest of the projection's predictive accuracy

**Branch:** `develop`, uncommitted. Nureen only.

### The ask

Evidence, not a claim, for the dissertation: how accurately does the
projection predict a student's final attendance, and does rule OR
projection catch at-risk students earlier than the rule alone? A console
command over a full-semester dataset (the upload format, or a reproducible
synthetic one until CGS provides anonymised data), at several cut-offs,
entirely in memory, with a Markdown report.

### Key findings before writing any code

- `isAtRisk()` queried the database for the two prior periods, so the rule
  could not be replayed in memory without copying it. Copying would let the
  backtest drift from the live rule, so the rule's logic was extracted into
  a pure `ruleFlags(current, previous, beforeThat)`, which `isAtRisk()` now
  calls (same behaviour; all existing rule tests pass unchanged).
- The per-period percentage (including the zero-session case) comes from
  `AttendanceRecord::countedPercentage()` on an unsaved model, so it is
  exactly what the live check stores, with nothing written.
- The alerts table is `attendance_alerts` (the ask said
  `attendance_risk_alerts`); the test checks it, `attendance_records`,
  `attendance_risk_states`, `notifications` and `jobs`.

### The design

- **`Support\Backtest\AttendanceBacktester`** (pure): for each student,
  replays the checks after every period (the rule on that period; the
  projection, from `projection_min_periods`, on the periods so far) and
  compares them with the actual final % (all attended ÷ all scheduled).
  "Truly at risk" = actual final below 80%. A method has flagged a student
  by cut-off k if it flagged them at any check up to k, because the live
  system emails on the first flag. MAE/RMSE compare the projection made at
  k with the actual final %, both at one decimal; a baseline MAE carries the
  attendance so far forward, so the projection can be judged against doing
  nothing clever. Earliness covers the whole semester: the first combined
  flag against the first rule flag, for truly at-risk students.
- **`Support\Backtest\SyntheticAttendance`**: seven patterns in documented
  proportions (steady high 30%, steady borderline 15%, gradual decline 15%,
  sudden drop 10%, recovery after a bad period 10%, one isolated bad period
  10%, noisy but fine 10%). It adds ±4-point uniform noise (±12 for noisy but
  fine) at 10 sessions a week, with its own seeded Mt19937 engine (default
  seed 2026, 200 students, the configured 14 periods). Matric numbers are
  SYN0001 onwards.
- **`attendance:backtest`**: `{file?}` or `--synthetic`, `--cutoff` (headline,
  default 50%), always also 25/50/75%, `--periods`, `--students`, `--seed`,
  `--output`. Files go through `AttendanceSheet::readFile()` and the same
  row checks as the upload. Students without exactly the semester's periods
  are left out and counted. Prints two tables plus earliness, and writes
  the Markdown report to `storage/app/backtest/`, which gets its own
  `.gitignore` (`*`) so reports never show in Source Control. The report
  carries a prominent synthetic-data notice when applicable, and only
  aggregate figures (no per-student rows, so a real export stays
  anonymous in the report).

### What changed

- New: `Console/Commands/BacktestAttendance.php` (registered in
  `ModuleProvider`), `Support/Backtest/AttendanceBacktester.php`,
  `Support/Backtest/SyntheticAttendance.php`,
  `tests/Feature/Nureen/AttendanceBacktestTest.php` (8 tests).
- `Support/AttendanceRiskEvaluator.php`: `ruleFlags()` extracted from
  `isAtRisk()`.

### Bugs caught

- None in the code. The hand-worked 5-student dataset was checked against a
  real run before it became a test: MAE 6.98; rule precision 1/2, recall
  1/3; projection and combined 2/3 and 2/3; mean lead 1/3 period.

### Verification

- Full suite: 451 passed.
- Synthetic run (seed 2026, 200 students, 14 weeks, 35 truly at risk):
  - **Projection error** (MAE): 12.10 / 4.00 / 1.09 points at periods 4, 7
    and 11, against a baseline of 5.92 / 4.08 / 1.53.
  - **Midpoint precision / recall:** rule alone 41.2% / 60.0%; rule OR
    projection 29.4% / 85.7%.
  - **Earliness:** the combined approach flagged truly at-risk students
    2.06 periods earlier on average, earlier for 18 of the 35.
- The "25%" cut-off of a 14-period semester is period 4 (3.5 rounded); the
  table shows the real share (29%).

---

## 2026-11-01 — Attendance: viva demo cases, and the student dashboard's Attendance Action card

**Branch:** `develop`, uncommitted. **Includes a Core change the team agreed.**

### The ask

Add a re-runnable seeder for three viva demo students (projection only,
current rule, steady) that prints their figures. Replace Core's misleading
"Predicted Attendance" student card with an "Attendance Action" card that
tells the student what to do next, never showing the projection. Add the
nearest Client Charter decision date to the Active Applications card. Keep
the Core change minimal and leave the other dashboards untouched.

### Key findings before writing any code

- The old card was fed by `SuppliesAttendance::projectionFor()` →
  `AttendanceRiskEvaluator::project()` (an older mean-change rule). Nothing
  else used either, so both could go.
- The Client Charter lives in Nureen, and Core may not name it. The calendar
  already uses an "implement a Core contract on your workflow" seam
  (`SuppliesCalendarEvents`), so the decision date follows that pattern.
- At 70% after 4 of 14 weeks (the old "current rule" case), 80% is still
  reachable (84 of the next 100 sessions), so that student would never show
  "Justification needed". The case was lowered to 3, 3, 2, 3 (27.5%): 101
  of the 100 sessions left would be needed.
- Only the student dashboard includes `stat-cards.blade.php`.

### The design

- **`Support\AttendanceTarget`** (Nureen): `sessionsNeeded(attended, total,
  remaining)` = the fewest N with (attended + N) ÷ (total + M) ≥ 80%, or null
  when N > M; `remainingSessions()` = semester periods left × average
  sessions per period so far. Both use the semester's figures, with
  excusals counted per the config. `for($student)` picks a state:
  `too_early` (fewer than `projection_min_periods` periods), `on_track` (not
  flagged by `assess()`, or N = 0), `attend`, `justification` (plus the
  latest appeal this semester, if any).
- **The wording lives in Nureen** (`AttendanceProvider::actionFor()`); Core
  only renders label, value, note, pill and link. The tone is supportive:
  "Justification needed" uses the amber pill, never red.
- **Core seams:** `SuppliesAttendance::projectionFor()` →
  `actionFor()`, and a new `SuppliesDecisionDates` contract that the three
  charter workflows implement via `Workflows\Concerns\ExpectsDecisionByCharter`
  (`ClientCharter::expectedDecision()['expected_date']`).
  `StudentDashboard::nextDecisionDate()` takes the earliest across the
  student's own pending applications. Untimed stages and teammates' modules
  give no date, so the card says just "In progress".
- **`AttendanceRiskDemoSeeder`** owns the three cases (and
  `NureenAttendanceDemoSeeder` now reads them from it). It replaces just
  those three students' histories and runs the early warning for them only,
  so a re-run neither duplicates rows nor re-sends email. It prints login,
  current %, projected %, reason and the card text.

### What changed

- Nureen: `Support/AttendanceTarget.php` (new),
  `Support/AttendanceProvider.php` (`actionFor()`; `projectionFor()` gone),
  `Support/AttendanceRiskEvaluator.php` (legacy `project()` gone),
  `Workflows/Concerns/ExpectsDecisionByCharter.php` (new) on
  `GaExtensionWorkflow`, `SupervisionWorkflow` and
  `CertificationLetterWorkflow`, `Database/Seeders/AttendanceRiskDemoSeeder.php`
  (new), `NureenAttendanceDemoSeeder.php` (cases from the new seeder).
- **Core** (every file, with the reason, is in `SHARED_CHANGES.md` §9a):
  `Contracts/SuppliesAttendance.php`, `Contracts/SuppliesDecisionDates.php`
  (new), `Services/StudentDashboard.php`,
  `Http/Controllers/DashboardController.php`,
  `Resources/views/dashboard/partials/stat-cards.blade.php`.
- Tests: `AttendanceActionCardTest` (new, 16 tests);
  `AttendanceEarlyWarningTest`'s "no student sees the projection" test now
  also covers the two cards; `AttendanceDataRulesTest` (the current-rule case
  is now `both`); `tests/Feature/Core/ModuleContractsTest.php` (method
  rename).

### Bugs caught

- In the real app, the card's sentence value rendered at the big-number size
  (four lines) and stretched the whole row. It now has a `textValue` flag
  that sets the words smaller, in the same partial.
- A test fixture of my own asked for 23 sessions with 10 left (an unreachable
  case, not a rounding one); it was corrected to 30 of 31, which needs 3.

### Verification

- Full suite: 443 passed.
- Seeder on dev, run twice: 22093001 95% / 75% / projected / "Attend 74 of
  the next 100 sessions"; 22001011 27.5% / 22.5% / both / "Justification
  needed"; 22001012 90% / 90% / not flagged / "On track". Four rows each;
  no new email on the second run.
- Browser, light and dark: Hafiz's card reads "Attend 74 of the next 100
  sessions" and no projected figure or wording appears on his dashboard,
  overview or history. Mailpit holds exactly one early-warning email for
  him. Haziq's card reads "Justification needed" and links to the appeal
  form (three upload fields). Tan Wei Jian's card reads "On track", and his
  Active Applications card says "In progress · next decision by 5 Nov" (his
  pending Supervision; his GA Extensions are on the untimed Senior Director
  route). The CGS At-Risk list shows Hafiz as Projected and Haziq as Both;
  Tan is correctly absent. The CGS, admin, supervisor, chair and dean
  dashboards return 200 without either new card.
- Dev side effects: Haziq received a second early-warning email, because
  his projection fell well past the re-alert margin when his case was
  lowered. 242 failed jobs that teammates' tests leaked onto the dev queue
  over two suite runs (Core, Chloe and Jason notifications; the §7 phpunit
  issue) were deleted.

---

## 2026-11-01 — Attendance: end-of-semester projection and automatic early warnings

**Branch:** `develop`, uncommitted.

### The ask

Make at-risk detection genuinely predictive (the FYP title promises
"Predictive Analytics") without a trained model: CGS has no labelled
history. Project each student's end-of-semester attendance by least-squares
regression on their own per-period rates. Flag students early, email them
automatically with supportive wording, and never show students the
projection. Approved appeals must count, CGS must see who was emailed and
when, and there must be no repeated alerts.

### Key findings before writing any code

- **One `attendance_records` row = one student × one period**, with that
  period's own counts (not cumulative). What a period *is* was never fixed:
  all dev data is **monthly** (40 sessions ≈ check-in + check-out × 20
  working days, from the shared DemoDataSeeder) and the upload template's
  samples are monthly, but the automated sync's **simulated source writes
  weekly** rows (period ending Friday).
- **The original rule is per period, not cumulative**: the latest period
  below 80%, or three declining periods ending within 5 points of 80%.
- **Appeals recorded at most one period and no session count**, and approval
  changed no figures at all.
- **Core's student dashboard already shows a "Predicted Attendance" card**,
  fed by an older mean-change rule (`AttendanceRiskEvaluator::project()`).
  Reported, not removed (SHARED_CHANGES.md §9).
- **Monthly periods make the projection nearly inert**: with a 4-month
  semester it starts at month 3 of 4, and any decline steep enough to project
  below 80% trips the original rule first. Hence `semester_periods` defaults
  to 14 (weekly), with 4 documented for monthly uploads.

### The design

- **`Support\AttendanceProjector`**: pure, no database. Least-squares line
  through per-period rates against period number; zero-session periods skipped
  (they still count as elapsed); extended over the remaining periods, each
  clamped to 0–100%, at the average sessions per period so far. Returns
  current cumulative %, projected %, slope; null below
  `projection_min_periods`.
- **`AttendanceRiskEvaluator::assess()`**: the original rule (`isAtRisk()`,
  unchanged) plus the projection, reason `current` / `projected` / `both`,
  over the current semester's periods (`semester_start`), with excused
  sessions counted.
- **Who sees what**: the outcome is stored in a new Nureen table,
  `attendance_risk_states`, shown only on CGS's At-Risk list.
  `attendance_records.at_risk` keeps its old meaning, so nothing a student
  sees changes because of the projection.
- **`Support\AttendanceEarlyWarning`**: runs after every sync and every
  manual upload, for every student. It emails (student plus supervisor copy)
  on newly at risk, or when the projection has dropped by more than
  `realert_margin` since the last alert. Every alert goes in
  `attendance_alerts`.
- **Appeals**: name a period and the missed sessions covered. Approval adds
  them to `attendance_records.sessions_excused` (raw UTrace figures
  untouched) and re-runs the check.

### What changed

- Config `nureen.attendance.semester_periods` (14), `semester_start` (null),
  `projection_min_periods` (3), `realert_margin` (5). Threshold reused from
  `AttendanceRiskEvaluator::THRESHOLD`.
- Migrations, all on Nureen tables:
  - `attendance_records.sessions_excused`
  - `attendance_appeal_details.sessions_covered`
  - new `attendance_risk_states`
  - new `attendance_alerts`
- `AttendanceAtRisk` email rewritten: current semester %, 80%, reason-specific
  wording, what to do, appeal link. Never the projection or slope. The
  supervisor copy follows the same rule.
- The ingestor no longer emails; the sync runner and the upload run the early
  warning. New command `attendance:check-risk`.
- CGS At-Risk list rebuilt: current, projected, trend, reason, last emailed;
  sortable (projected % by default); searchable by matric number or name.
- Student overview: latest period **and** "This semester so far" (the figure
  the email quotes). History marks excused sessions.
- Appeal form: choose the period and the missed sessions covered. The CGS
  detail shows what approval will excuse. `AttendanceAppealWorkflow` is now
  `DecidesOneAtATime`.

### Bugs caught

- **The long-running queue worker kept the old notification class**: all 28
  early-warning jobs from the first sync failed ("typed property $record
  must not be accessed before initialization"). Fixed with
  `queue:restart` and a retry of exactly those jobs. Any deploy that changes a
  queued class needs a worker restart.
- **Email vs dashboard mismatch**: the email quotes the semester figure
  (96.3%), the dashboard the latest period (90%). Fixed on Nureen's overview;
  Core's card proposed in SHARED_CHANGES.md §9b.
- **History read "18 / 20 sessions, 100%"** after an excusal; now says
  "+ 2 excused by an approved appeal".
- **A false positive in my own check**: the verification student's email
  address contained "projection" and showed in the sidebar; renamed.

### Verification

- Tests: 18 new (projector unit tests incl. the worked 87.5 → 62.5 example;
  early-warning feature tests). Two appeal tests updated for the new
  `sessions_covered` field. Full suite 413 passed.
- Real app, student 22093001 (weekly periods 20, 20, 19, 18 of 20):
  - `attendance:sync --source=simulated` flagged them "projected" only:
    96.3% now, projected 78.8%, −3.5 pts/period.
  - Mailpit: exactly one email, supportive wording, 96.3% and 80% shown, no
    projected figure or trend, appeal link opens the form. Supervisor copy
    received.
  - A second sync sent nothing.
  - As CGS (light and dark mode): found by matric with every figure, reason
    "Projected", last emailed 1 Nov 2026 7:10 am.
  - As the student: dashboard, overview and history show no new projection
    (the legacy Core card still shows 76.7%, reported).
  - Appeal #182 (2 sessions, period ending 6 Nov), approved by CGS from the
    queue found by matric: excused 2, latest period 100%, semester 98.8%,
    projection 96.3%, no longer at risk, off CGS's list.
- Dev data side effects: the simulated syncs added one random weekly row
  (period ending 6 Nov) for every other student, and the first full risk
  check emailed the 14 students it newly flagged, plus supervisors
  (Mailpit only).

### Follow-up (same session, before commit): attendance data fixes

- **No future periods.** The simulated source used this week's Friday even on
  a Sunday, writing periods ending 6 Nov 2026. It now writes the last
  completed week (the Friday on or before today), and `AttendanceIngestor`
  rejects any `period_end` after today from every source and the manual
  upload. The 49 future rows (one per demo student) were deleted from the dev
  database.
- **No projection across mixed period lengths.**
  `AttendanceProjector::periodLength()` classifies the gaps between end
  dates (weekly 6–8 days, monthly 28–31). Mixed or irregular gaps mean
  `unavailableReason()` = `inconsistent_periods`: the rule-based flag still
  applies, the projection does not, and CGS sees "Projection unavailable:
  inconsistent periods". Stored as `attendance_risk_states.projection_status`.
- **Weekly demo semester.** `nureen.attendance.semester_start` defaults to
  2026-10-05, so four weeks (ending 9–30 Oct) are complete by 1 Nov.
  `NureenAttendanceDemoSeeder` replaces the whole attendance history of the
  49 listed demo students (DemoDataSeeder's 18 and Nureen's 31) with clean
  weekly rows. Cases: 22093001 projection only (95% → projected 75%),
  22001011 current rule (70%, improving), 22001012 steady (90%). Every other
  demo student is healthy. Risk states and alerts were reset; the 28
  early-warning emails and the appeal #182 email from the earlier
  verification were deleted from Mailpit.
- **Appeals.** They did not require CGS's documents. They now require a
  Justification Letter and a Monthly Activity Breakdown (multi-file, via
  `DocumentStore::manyRules()`). The student records `sessions_requested`;
  CGS approves `sessions_approved` (default = requested, never more, may be
  fewer), with both kept.
- **Excusal rule is configurable:** `nureen.attendance.excused_sessions` =
  `count_as_attended` (default) or `exclude_from_total`, applied in one place
  (`AttendanceRecord::countedAttended()` / `countedTotal()`). Stored
  percentages are re-derived at the next check if the setting changes.
- **Bug caught in the real app, not by the tests:** the appeal form still
  called a renamed method and returned a 500 for any student with a missed
  session. The existing test only loaded the form for a student with no
  attendance. Fixed, with a regression test using real periods.
- The four uncommitted attendance migrations were rolled back and re-run on
  the dev database (the appeal migration now adds `sessions_requested` and
  `sessions_approved`).
- Verified: full suite 427 passed. Real app: a simulated sync added nothing
  (no future week) and flagged exactly 22093001 (projected) and 22001011
  (current). 22093001 received one email (95%, no projection), then nothing
  on a second sync. CGS's list and the student's pages are correct in both
  themes, and the appeal form shows both required uploads.

---

## 2026-11-01 — Official letters: CGS endorses the appointment letter; one formal layout for both letters

**Branch:** `develop`, uncommitted.

### The ask

An official letter must never name an endorser who did not endorse it. Add a
Supervision mode where Non-Executive CGS previews and endorses the
appointment letter before it is issued (the new default); give the Senior
Director the same preview for GA/GRA certification; and replace both letters'
plain templates with one formal, shared, smaller Dompdf layout.

### Key findings before writing any code

- In `'automatic'` mode the letter printed **the Chair**, not a CGS user, under
  "Endorsed by:" followed by "Centre for Graduate Studies": a Chair's
  approval presented as a CGS endorsement. The controller and the demo seeder
  both passed the approving Chair as `$endorsedBy`.
- `users` has no title column (and is Core's), so salutations are
  "Dear <name>,".
- Core's decision form has no label option for its Approve button.
- Each letter was ~879 KB: the whole DejaVu Sans font embedded, unsubset.
- **A pre-existing bug, found by the new tests:** `AppointmentLetterIssued`
  and `CertificationIssued` called `MailMessage::attachFromStorageDisk()`,
  which does not exist. Every letter email had been failing in the queue
  worker (visible in `failed_jobs`); students never received their letters
  by email.

### What changed

- **`'endorse'` mode** (`nureen.supervision.cgs_stage`, now the default):
  Supervisor → Chair (`endorsed`) → Non-Executive CGS at `cgs_review`
  (`endorsed`, "Pending Letter Endorsement"). The CGS row has **Preview
  letter** (DRAFT, "To be assigned on issue", the viewer as signatory; writes
  and sends nothing) and **Endorse & issue letter**, which assigns the
  reference, issues the PDF in the endorser's name, emails it to the student
  with the supervisor in CC, and approves. Reject keeps its mandatory reason.
  `'automatic'`/`'manual'` unchanged; every snapshot is honoured, and
  `stages(null)` covers all three modes. Client Charter: `cgs_review` keeps
  its 0 days.
- **`Support\LetterRenderer`** builds both letters and reads the signatory
  from `approval_history`: the endorsing CGS user, the approving Senior
  Director, or (in `'automatic'`) no endorser, only "issued by CGS following
  approval by <Chair>". The Senior Director has the same **Preview letter**.
- **One layout** (`letters/layout.blade.php`, extended by `letters/appointment`
  and `letters/certification`): letterhead with a config logo
  (`public/images/UTP_logo.png` by default, downscaled in memory, text-only
  if missing) and config contact details (placeholders), Our Ref and date,
  addressee, salutation, bold uppercase subject, numbered paragraphs and
  details table, closing and signature, cc, and a footer on every page
  (verification URL, computer-generated notice, reference). DejaVu Serif,
  A4, 2.5 cm margins, one accent colour, one page.
- **Revocation** re-renders the stored PDF with a diagonal REVOKED watermark
  (same document row; see `SHARED_CHANGES.md` §7a).
- **Verification:** new public `/verify/appointment/{ref}`, sharing one view
  with `/verify/certificate/{ref}`, both built from the renderer's data.
- **Email attachments fixed** (`Attachment::fromStorageDisk()`).
- **Client Charter fix:** the clock now starts on the first working day on or
  after arrival, so a 0-day stage reached on a Sunday is due Monday, not
  Sunday. Applied to due dates, overdue, reminders and the compliance
  figures alike.
- **File size:** ~879 KB → ~96–102 KB per letter (subset fonts ~34 KB, the
  downscaled logo ~63 KB).

### Verification

- Full suite: 385 passed (17 new in `LettersTest`, 1 new in
  `ClientCharterTest`; four `SupervisionTest` cases now pin `'automatic'`
  explicitly).
- Real app, headless Chromium, records found by matric number: Main
  (22092101, #174) and Co-Supervisor (22092102, #175) requests submitted
  through the student form, then supervisor → Chair → CGS preview → endorse;
  GA (22092103, #176) and GRA (22092104, #177) certifications through to
  Senior Director preview → approve. Every PDF opened and rendered to an
  image; all one page. #175 revoked: the student's own download shows
  REVOKED and the verification page says Revoked.
- Mailpit: certification letters arrive with the PDF attached. Appointment
  letter emails kept failing until the long-running queue worker was
  restarted (`queue:restart`; it had loaded the old class), after which
  `CGS/SVAPPT/2026/0006` arrived with the PDF attached and the supervisor in
  CC.

### Follow-up (same session, before commit): valid demo documents, one-page letters

- **"Failed to load PDF document" on the Documents page.** The cause was
  confirmed: exactly 8 documents, all 45-byte `doc.pdf` stand-ins uploaded by
  this session's own browser verification runs (applications #170–#177).
  No seeder or teammate data was affected. The Nureen demo seeders attached
  no supporting documents at all. Now:
  - `Database\Seeders\Support\DemoDocuments` attaches a valid one-page
    Dompdf placeholder ("DEMO DATA: NOT A REAL DOCUMENT", document type,
    student, application), under the same doc_type the real form uses and
    named like "Evidence of Work Done - <student>.pdf". Both Nureen seeders
    use it.
  - The 8 dev rows (documents 21, 23, 25, 27, 29, 31, 33, 35) were replaced
    in place with such placeholders and renamed; nothing else was touched.
  - Real PDFs uploaded through the GA Extension, Supervision and
    Certification forms in the browser download byte-identical and open.
  - `SeededDocumentsTest` runs both seeders and parses every stored PDF
    (structural parser in `tests/Feature/Nureen/Concerns/ParsesPdf.php`).
- **Tests were writing to the dev database.** The container's
  `QUEUE_CONNECTION=redis` beats `phpunit.xml`, so queued notifications from
  tests reached the dev worker, which wrote `failed_jobs` rows (37 per Nureen
  run). Every Nureen test class now uses `KeepsJobsOffTheDevQueue` (sync
  queue): 0 rows. The proper fix (`force="true"` in `phpunit.xml`) is in
  `SHARED_CHANGES.md` §7c.
- **Certification form bug found:** the GA and GRA blocks both have a
  `semester` input; the hidden block's was still submitted and overwrote the
  student's value, so a correctly filled GA request failed with "The
  semester field is required". Hidden blocks' fields are now disabled.
  Confirmed both ways in the browser.
- **Every letter fits one A4 page.** Maximum lengths for every printed field
  (course code 20, course name 120, course owner 80, project title 150,
  project leader 80, grant code 40, semester 30, purpose 120), enforced in
  validation, with `maxlength` and a live counter on the form. Layout: fixed
  letterhead band at the top, fixed footer band at the bottom, one 10 pt body
  size, a fixed-width details table that wraps long values, and the ref
  column sized to its content. Paragraph 1 no longer repeats the supervisor's
  name (it is in the details table and the cc). `LetterWorstCaseTest` fills
  every field at its maximum, with long realistic names, programme and
  department, for the main, Co, 'automatic', DRAFT and REVOKED appointment
  letters and the GA, GRA, DRAFT and REVOKED certification letters: all one
  page, with at least 0.8 cm to spare.
- Full suite: 395 passed.

---

## 2026-11-01 — Client Charter drives due dates, expected decision dates, reminders and compliance

**Branch:** `develop`, uncommitted.

### The ask

CGS's Client Charter (working days per approval stage, already recorded in
`nureen.charter_days` by the previous task) should drive deadlines, the
student's expected decision date, reminders and a compliance report for GA
Extension, Supervision and Certification. Students could not tell when to
expect an outcome, and nothing measured whether CGS meets its charter.

### Key findings before writing any code

- **Reminders counted calendar days**, from `applications.updated_at`
  against a flat `stalled_days` (3) per module. The analytics page did the
  same.
- **Students never see a module's `_detail` partial.** Those only render
  inside the approver queue (`core::partials.queue`); the student's
  `applications.show` and dashboard panel are Core's with no module hook.
  So the expected date got its own Nureen page, and a Core seam is
  proposed in `SHARED_CHANGES.md` §5.
- The collapsed queue row is also Core markup, so the overdue marker there
  is a scoped CSS `:has()` rule from inside Nureen's own partial.

### The design

One calculator and one rule, used everywhere:

- `Support\WorkingDayCalendar`: Monday–Friday minus
  `nureen.charter.holidays` (new, empty by default).
- `Support\ClientCharter`: the stage was reached at the newest
  `approval_history` row (else `submitted_at`); due date = that date + the
  stage's charter in working days; overdue once more working days than the
  charter have passed (= first working day after the due date). Expected
  decision date = max(due date, today) + the charter days of every stage
  still ahead on that application's own chain. A `null` charter stage gets
  no due/expected date, and nothing is invented.

### What changed

- **Student:** new **Expected Decision Dates** page
  (`nureen.expected-dates`), linked from the student sidebar via
  `GaExtensionWorkflow::links()`. "Expected decision by …", the note "Based
  on the CGS Client Charter of N working days … a service target, not a
  guaranteed date", and only when overdue "This stage is taking longer than
  the Client Charter. You don't need to do anything; the reviewer has been
  reminded."
- **Approver queues (all three):** `charter/_queue_clock.blade.php` at the
  top of each detail panel shows the stage's due date, and overdue rows are
  marked on the collapsed line. Rows are ordered by when they reached the
  stage (`ClientCharter::orderByStageEntry()` via `queueFor()`'s `$scope`),
  which puts every overdue row first in the default order.
- **Reminders:** all three `*:remind-stalled` commands fire on each
  stage's charter (first reminder the day it goes overdue, repeat once per
  further charter period, never on a weekend alone). `stalled_days` is now
  only the fallback, in working days, for a `null`-charter stage. A reminder
  count also resets after a return-to-supervisor. `--days` still overrides,
  now meaning working days for every stage. Emails say "N working days" and
  quote the stage's charter.
- **Compliance report** (`/reports/processing-time`): a table per module and
  stage (decisions, % within charter, average working days, charter); the
  chart now shows average working days next to the charter for each stage
  (same vertical bar structure, second dataset coloured by token); the
  overdue table is built from `ClientCharter` with stage-reached and due
  dates. Also fixed: the chart's colour map keyed Supervision as
  `'Supervision'`, but the module's label is `'Supervision Request'`, so its
  bars had always fallen back to grey.
- **Demo data:** `NureenClientCharterDemoSeeder`, on-time and overdue
  applications at every stage, matric numbers 22091001–22091024.

### Verification

- Full suite: 359 passed (was 338; 21 new across `WorkingDayCalendarTest`,
  `ClientCharterTest`, `ClientCharterDisplayTest`, plus new cases in
  `StalledReminderTest` and `AnalyticsTest`). Existing reminder/analytics
  tests were pinned to a fixed Friday, since "N days ago" is no longer N
  working days.
- Headless Chromium against the dev app on Sunday 1 Nov 2026 (rows found
  by matric number): every student's expected date matched a hand
  calculation; the overdue message only on overdue rows; each approver's
  queue marked exactly the overdue rows and listed them first; the CGS
  page's table, chart and overdue table correct in light and dark, with
  every bar still drawn after a live theme toggle.
- Ran the three reminder commands: Mailpit received reminders only for
  applications `ClientCharter` reports overdue, none for the on-time ones;
  overdue rows reminded on Thu 29 Oct were correctly held back by the repeat
  rule.

### Follow-up (same session, before commit): stuck snapshots and GA Extension's supervisor stage

- **Older applications were unreachable.** Core builds queues and the
  sidebar from `stages(null)`, which Core's contract defines as *every* stage
  a module can use (`ModuleRegistry::queuesForRole()`, as `TravelWorkflow`
  does). `GaExtensionWorkflow` and `SupervisionWorkflow` returned only the
  live config's chain, so in the default modes the Senior Director had no
  GA Extension queue (3 real applications stuck at `senior_director`, 5 more
  heading there) and Non-Executive CGS had no Supervision queue (3 real
  `'manual'` applications stuck at `cgs_review`). Fixed in Nureen alone:
  `stages(null)` now returns the live chain plus any stage only the other
  chain has; `stages($application)` still uses the snapshot. GA Extension's
  shared `cgs_verify` queue is titled "Pending My Review", and each row says
  whether CGS's decision on it is final. Everything else (stepper, decision
  emails, `decide()`, `returnTo()`, `ClientCharter`, reminders, analytics)
  already went through the per-application chain.
- **Any supervisor could see and decide any student's GA Extension.** The
  queue had no named-person scope and `decide()` only checked the role.
  GA Extension now snapshots the student's supervisor at submission
  (`ga_extension_details.supervisor_id`, new migration, existing rows
  backfilled from the student's current supervisor: 35 of 35 on the dev
  database) and, like Certification, scopes the queue, refuses anyone else's
  decision (403), reminds only that supervisor, and refuses submission
  without a supervisor.
- **Left for Core** (`SHARED_CHANGES.md` §6): a return to the supervisor
  still emails every Supervisor-role user (`WorkflowEngine::notifyReturn()`),
  and a supervisor's dashboard tile still counts every row at a
  named-supervisor stage.
- **Verified:** 8 new tests in `SnapshotChainReachabilityTest`, 7 of which
  failed before the fix; full suite 367 passed. In the real app the Senior
  Director found matric 22001012 by search and approved GA Extension #18, and
  Non-Executive CGS found 22001015 and approved Supervision #26 (letter
  `CGS/SVAPPT/2026/0003` issued). `supervisor@utp.edu.my` no longer sees
  22001012's GA Extensions; their own supervisor does.

---

## 2026-10-31 — GA Extension, Supervision and Certification realigned to CGS's confirmed UResearch process

**Branch:** `develop`, uncommitted.

### The ask

Nureen validated her three approval modules' workflows with CGS through
written answers from CGS staff (the current process) and CGS's official
UResearch process workflow diagrams with Client Charter days (the new,
confirmed process). Where they agreed, the chain changed outright; where
they differed, the new process became the default behind a config switch so
it can be reverted without a code change. Attendance was explicitly out of
scope for this task.

### The design

Three config-driven decisions, each resolved **per application at
submission** and snapshotted onto that application's own detail row, so a
later change to the config can never alter the chain an in-flight
application is already running:

- **GA Extension** (`GaExtensionDetail::final_approver_snapshot`,
  `nureen.ga_extension.final_approver`): `'cgs'` (default) ends the chain at
  Non-Executive CGS, with the Senior Director emailed every outcome
  informationally; `'senior_director'` keeps the original three-stage chain.
- **Supervision** (`SupervisionDetail::cgs_stage_snapshot`,
  `nureen.supervision.cgs_stage`): `'automatic'` (default) makes the
  Chair of Department's approval final — the appointment letter issues
  immediately; `'manual'` keeps an explicit CGS eligibility-review stage
  after the Chair, with the letter generated only on CGS's approval.
- **Certification**: no mode switch (CGS's process did not differ here),
  but GA and GRA now show and validate only their own type-specific fields,
  defined in `nureen.ga_certification.fields`.

All three chains keep every Stage key that existed before this change
(`supervisor`, `cgs_verify`, `senior_director`, `cgs_review`) — a new stage
is *inserted*, never renamed, which is what lets an application already
sitting at any of these keys stay decidable exactly where it is regardless
of which config mode is live when it is next acted on
(`WorkflowEngine::currentStage()` looks a stage up by key, not position).

Existing in-flight applications, confirmed against the actual dev database
before writing any code:
- GA Extension: all backfilled to `final_approver_snapshot = 'senior_director'`
  (the only chain that has ever existed), so nothing already submitted
  changes behaviour.
- Supervision: backfilled to `cgs_stage_snapshot = 'manual'` so a row that
  reaches the end of its chain still gets a real CGS decision rather than
  silently skipping to an automatic letter it was never promised. Rows
  already sitting at `supervisor` do pick up the new Chair stage next —
  that is the point of the change. `appointment_role` backfilled to
  `'main_supervisor'` (accurate: the old code always wrote
  `users.supervisor_id` on approval); `appointment_type` left `null`,
  genuinely unknown, shown as "Not recorded".
- Certification: no snapshot needed — inserting the new `supervisor` stage
  before `cgs_verify` does not retroactively require an application that
  has already passed that point to go through it.

### What changed

- **Migrations** (3): `final_approver_snapshot` on `ga_extension_details`;
  `appointment_role`/`appointment_type`/`cgs_stage_snapshot`/
  `previous_supervisor_id`/`letter_reference`/`letter_issued_at`/
  `letter_revoked_at`/`letter_revoked_reason`/`letter_revoke_note` on
  `supervision_details`; `supervisor_id`/type-specific fields/`purpose`
  made nullable on `ga_certification_details`. All on Nureen's own tables.
- **`config/nureen.php`** — `ga_extension.final_approver`,
  `supervision.cgs_stage`/`reference_prefix`, `ga_certification.fields`/
  `supporting_document_label`/`appointment_check_enabled` (now off by
  default — not part of CGS's confirmed process), and `charter_days` (the
  official Client Charter working days per module/stage, recorded for a
  later task, not yet consumed by reminders or analytics).
- **Workflows** — `GaExtensionWorkflow` now implements `DecidesOneAtATime`
  (its `decide()` now does more than call the engine); `SupervisionWorkflow`
  gained the Chair stage; `CertificationLetterWorkflow` gained the
  supervisor stage.
- **Controllers** — `GaExtensionController::decide()` now copies the
  endorsing supervisor and, in `'cgs'` mode, informs the Senior Director.
  `SupervisionController` gained the Main/Co-Supervisor split (only Main
  writes `users.supervisor_id`, capturing the previous value first),
  appointment-letter generation (`Support\AppointmentReferenceGenerator`,
  same race-safe pattern as `CertificateReferenceGenerator`), the "Issued
  Appointment Letters" list, and revocation — which restores the previous
  supervisor **only if** `users.supervisor_id` still equals the supervisor
  this specific letter appointed; if a later appointment has since
  superseded it, the restore is skipped and CGS is told why, both in the
  redirect message and on the issued-list page. `CertificationController`
  gained the no-supervisor submission guard, type-specific field validation,
  and named-supervisor addressing at its new first stage.
- **New Nureen-owned classes**: `Support\AppointmentReferenceGenerator`,
  `Notifications\GaExtensionFinalDecisionCc`/`GaExtensionFinalDecisionInformational`,
  `Notifications\SupervisionFinalDecisionCc`/`AppointmentLetterIssued`/`AppointmentLetterRevoked`.
- **Views** — Supervision gained `appointment_role`/`appointment_type`
  fields on the form, a letter-status partial (mirrors Certification's
  certificate-status partial), an issued-letters list, and a CGS-only tools
  link. Certification's form now shows only the fields for the chosen type
  (vanilla JS toggle, degrades to both-hidden without JS, same pattern as
  Chloe's candidacy-appeal form); the detail view and the PDF letter follow
  the same type split.
- **Reminders** — `RemindStalledSupervisionRequests` extended for the Chair
  stage (department-scoped recipients, same escalation-to-CGS rule as the
  Supervisor stage). `RemindStalledCertification` now addresses its new
  `supervisor` stage to the one named person on the row, not every
  Supervisor-role user, the same reasoning as Supervision's own command.
- **`database/seeders/DemoDataSeeder.php`** — left untouched (shared,
  outside Core but affects all six people's demo scenarios); its
  `CHAINS`/`$outcomes` for these three modules are now stale for a **fresh**
  `demo:seed` run (existing seeded rows are unaffected — see
  `SHARED_CHANGES.md`). Added `NureenRealignedWorkflowDemoSeeder`
  (Nureen-owned, follows the `GaAppointmentRecordSeeder` convention) to seed
  fresh demo data under the new chains specifically.

### Bugs caught

- Certification's "Non-Executive CGS can always see an issued document"
  assumption (via `ApplicationDocument::visibleTo()`'s `approval_history`
  check) silently breaks in Supervision's `'automatic'` mode, where CGS
  never decides on the application at all. Worked around with a dedicated,
  role-gated download route rather than widening Core's shared rule — see
  `SHARED_CHANGES.md`.
- The Certification detail/PDF templates and `DemoDataSeeder`'s `CHAINS`
  array both needed updating for the new stage counts; caught by the full
  test suite failing on both before being fixed.
- An em dash crept into two new Supervision views
  (`Tests\Feature\Core\ProseTest`, this repo's own house-style check) —
  caught immediately by the full suite, not a real bug but worth noting
  since it would have blocked anyone relying on that test.

### Follow-up fix (same session, before commit)

A pre-commit check against the original ask caught one gap: Non-Executive
CGS could find an issued appointment letter on the "Issued Appointment
Letters" list, but was never actually *notified* the moment one issued —
only the student was. Added `Notifications\AppointmentLetterIssuedToCgs`,
sent to every Non-Executive CGS user from `SupervisionController::issueLetter()`
regardless of `cgs_stage` mode (the rule is "every issued letter", not
"every letter CGS did not already know about" — in `'manual'` mode CGS is
also the one who just approved it, and still gets the notification). Wired
into the demo seeder too, for parity. Covered by new assertions in all
three of `SupervisionTest`'s letter-issuing tests (automatic Main,
automatic Co-Supervisor, manual mode) plus a negative assertion that
nothing is sent before the Chair has actually approved. Full suite re-run
green afterwards: 338 passed, 1776 assertions (+4 from this fix).

### Verification

Full test suite: 338 passed (was 314 before this task; +24 net from new and
updated coverage across `GaExtensionTest`, `SupervisionTest`,
`CertificationTest`, `StalledReminderTest`, `CertificateVerificationTest`).
Live-verified in a headless browser against the running dev stack: the
Certification form's GA/GRA field toggle (JS-only behaviour feature tests
cannot exercise) in both light and dark mode; the Supervision "Issued
Appointment Letters" list showing both a Main and a Co-Supervisor letter as
Valid with working Revoke forms; an authenticated PDF download of a
generated appointment letter (caught and fixed a real-disk file-permission
issue along the way — `storage/app/private/applications/<id>` was
root-owned because it was created via `docker exec` as root rather than the
container's `sail` user that actually serves requests; chown'd the
affected directories rather than deleting any data); and the GA Extension
queue correctly labelling its CGS stage "Final Approval" in `'cgs'` mode.
`core-guard` reviewed the full diff against all eight project hard rules
(Core untouched, no other folder touched, status/current_stage only via
`WorkflowEngine`, no new columns on shared tables, no raw `$_POST`, uploads
through `DocumentStore`, no unescaped `{!! !!}`, no hardcoded secrets) —
pass on all eight.

---

## 2026-10-11 — Student dashboard: fixed nested scrolling and clipped cards

**Branch/commit:** `develop`, commit `a5da01b`
("fix: stop the student dashboard from clipping cards and scrolling inside
itself")

### The problem

On the student dashboard, the four panel cards ("My Application Status",
"Attendance Overview", "Recent Notifications", "Upcoming Tasks") had fixed
heights with their own internal scrollbars. Content got clipped — the
"Attendance Appeal" row in My Application Status was cut off, and the
attendance gauge chart in Attendance Overview was only half visible.

### Root cause

`public/css/dashboard-student.css` carried a media query
(`min-width: 1201px and min-height: 700px`) that pinned `.sdash` to a fixed
viewport height on desktop and made every `.sdash-card-body` scroll
internally — the classic "one-screen dashboard" pattern. That selector
applied to **all three** `.sdash` dashboards (student, CGS, admin), but
only CGS and Admin actually want it (their own `.blade.php` files say
"single screen, by requirement"). The student dashboard's own file-header
comment already said "natural-height layout, the page scrolls" — the media
query at the bottom of the file silently contradicted it.

Separately, `.sdash-panels` wrapped two independent per-column grids
(`.sdash-col-left`/`.sdash-col-right`), so a row's two cards never shared a
height — nested grids don't align row tracks with their siblings.

### What changed

- **`public/css/dashboard-student.css`** — rescoped the one-screen media
  query to `.sdash.sdash-cgs`/`.sdash.sdash-admin` only. The student grid
  is natural-height at every size now — the whole page scrolls, not
  individual cards. Flattened `.sdash-panels` into one grid holding all
  four cards directly (removed the `.sdash-col` wrapper), so a row's two
  cards share a grid row track and stretch to equal height automatically.
- **`app/Modules/Core/Resources/views/dashboard/student.blade.php`** —
  removed the `.sdash-col-left`/`.sdash-col-right` wrapper divs; the four
  `@include`s sit directly inside `.sdash-panels` in pairing order
  (application-status, attendance-overview, notifications, upcoming-tasks).
- **`public/css/dashboard-gauge.css`** — updated the animation-delay
  selectors to match the flattened structure (cosmetic stagger only).
- **`app/Modules/Core/Http/Controllers/DashboardController.php`** — capped
  "My Application Status" to 3 items and "Recent Notifications" to 5, each
  keeping its existing "View All" link.

Nothing in `Core/` outside these four files was touched. The CGS and Admin
dashboards' one-screen behavior is unaffected — confirmed by the scoped
selector change and by `tests/Feature/Core/PageShellTest.php` staying
green (that test's panel-scroll-declaration check only globs
`{approver,supervisor,chair}-*.blade.php`, so it never covered the student
dashboard's panels in the first place).

### Verification

- Full `tests/Feature/Core/` suite (108 tests) passed.
- Drove the actual app in headless Chromium, logged in as
  `student@utp.edu.my`, at 1600×1000 (well above the old 1201×700
  breakpoint): confirmed `overflow-y: visible` on all four card bodies with
  `scrollHeight === clientHeight` (nothing clipped), row-paired cards at
  equal heights (290px/290px and 374px/374px), the full gauge/legend/footer
  note visible inside Attendance Overview, correct 3/5 item caps with
  "View All", single-column stacking at 400px width, and dark mode intact.

---

## 2026-10-11 — Multi-file supporting documents: GA Extension & Supervision

**Branch/commit:** `develop`, commit `0456dcf`
("feat: allow multiple supporting documents on GA Extension and
Supervision")

### The ask

Both forms' "Supporting Documents" upload accepted exactly one file.
Requirement: 1–5 files, PDF/JPG/PNG/DOCX only, 5 MB per file / 20 MB
combined, selectable across multiple picks (not just one dialog), a staged
list before submitting (name, size, remove button), clear client-side *and*
server-side validation messages, and the approval side (Supervisor, Chair
of Department, etc.) showing every attached file.

### Key finding before writing any code

`application_documents` was **already a one-to-many table**
(`Application::documents(): HasMany`) — the Core model's own comment says
"Every upload, for every module, lands here." `DocumentStore::attach()`
already inserted one row per call; nothing stopped calling it more than
once per application. **No migration was needed.**

Better still: both `applications/show.blade.php` (the student's own
tracking page) and `partials/queue.blade.php` (every approver's queue,
Supervisor and Chair of Department included) **already** looped
`@foreach ($application->documents as $doc)` and already showed a file-count
badge plus a download link per document. The approval-side requirement was
already satisfied by existing Core code — confirmed live, no changes
needed there.

So the actual work was: the HTML input, the validation rule, and the one
`attach()` call per controller.

### What changed

- **`app/Modules/Core/Services/DocumentStore.php`** (shared Core file —
  flagged to the user before editing, since `DocumentStore` is used by
  every other module's single-file uploads too) — added, purely
  additively:
  - Constants `MULTI_ALLOWED`, `MULTI_MAX_FILES` (5), `MULTI_MAX_FILE_KB`
    (5120), `MULTI_MAX_TOTAL_KB` (20480).
  - `manyRules(string $field, ...)` — returns Laravel validation rules for
    an array file field: required/array/min:1/max:N on the field itself,
    `file`/`mimes`/`max` on `{field}.*`, and a closure checking the
    combined size (Laravel has no built-in rule string for a cross-item
    sum).
  - `attachMany(Application $application, array $files, string $docType)`
    — loops the existing `attach()` per file, reusing the same
    store-then-log path rather than duplicating it.
  - **Nothing existing was changed or renamed** — `ALLOWED`, `MAX_KB`,
    `rules()`, `attach()` are untouched, so every other module's
    single-file upload (Jason's memo, Hani's thesis, Chloe's appeal form,
    Norhanis's various Publication documents) is unaffected. Confirmed by
    the full 262-test suite staying green afterward.
- **`app/Modules/Nureen/Resources/views/components/multi-file-upload.blade.php`**
  (new) — the reusable picker widget, entirely inside Nureen's own folder.
  One real `<input type="file" name="field[]" multiple>`. Inline JS keeps
  its own running array of staged files so a second selection *adds* to
  the first instead of replacing it (the browser replaces a file input's
  FileList on every selection by default — the script rebuilds it via
  `DataTransfer` on every add/remove), renders a list with name + formatted
  size + a ✕ remove button, and shows inline error messages for: too many
  files, a file too large, wrong type, and combined total too large. "No
  file selected" is caught by the input's native `required`. Styling reuses
  existing CSS custom properties (tokens), so dark mode and the existing
  look fall out for free — no Core CSS file was touched.
- **`ga_extension/form.blade.php`** / **`supervision/form.blade.php`** —
  swapped the single `<input type="file">` for
  `<x-nureen::multi-file-upload>`.
- **`GaExtensionController::store()`** / **`SupervisionController::store()`**
  — validate via `DocumentStore::manyRules('supporting_documents')`;
  replaced the single `attach()` call with `attachMany($application,
  $request->file('supporting_documents'), 'Supporting Document')`.
- **`tests/Feature/Nureen/GaExtensionTest.php`** /
  **`SupervisionTest.php`** — updated for the new array field name, and
  added cases for: more than 5 files, a file over 5 MB, combined total over
  20 MB, a disallowed extension, zero files, and several valid files all
  attaching correctly.

### A real bug caught during verification (not by the test suite)

The empty error-message placeholder in the new widget carried the CSS
class `field-error` even while hidden and empty. Core's
`form-stepper.blade.php` — the JS that turns a long form into a wizard —
scans every step for `.is-invalid`/`.field-error` to decide which step to
open first after a failed submission. Because the placeholder always
carried that class, **the wizard always opened on the Documents step**,
even on a completely blank form with no errors at all. This only showed up
when actually driving the form in a browser; the PHPUnit suite never
rendered the page with JavaScript, so it never caught it. Fixed by only
adding the `field-error` class via JS when a real message is being shown.

### Verification

- Full test suite: 262 tests passed, including the new multi-file cases.
- Drove the actual app in headless Chromium as `student@utp.edu.my`:
  selected 3 files at once → added a 4th in a second selection (confirmed
  it added rather than replaced) → attempted a `.txt` file (rejected with
  an inline message, count stayed at 4) → removed one via ✕ → submitted →
  confirmed the Supervisor's queue showed "3 files" with all three as
  working download links.
- Backward compatibility: simulated a pre-existing single-file application
  (the exact shape the old code produced — one `application_documents` row,
  created via the unmodified `attach()` method) and confirmed it still
  displays and downloads correctly through the unmodified display code.
- Confirmed dark mode renders correctly, including the error-message
  coloring.

### What this means going forward

Any other module that later wants multi-file upload can reuse
`DocumentStore::manyRules()`/`attachMany()` directly — they're generic, not
specific to GA Extension/Supervision. The Blade component currently lives
in Nureen's folder (`nureen::components.multi-file-upload`); if a second
module wants it, the natural move is promoting it into `Core/` as a shared
component — a team conversation, not a unilateral change, per the Core
ownership rule.

---

## 2026-10-18 — Attendance: automated ingestion, manual upload kept as fallback

**Branch/commit:** `develop`, commit `e06d5b2`
("feat: automate attendance ingestion, keep manual upload as a fallback")

### The ask

CGS staff were manually downloading an attendance spreadsheet and
uploading it every week. Automate it from whatever source UTP can actually
provide, without losing the ability to upload by hand if a source is down
or wrong — and without the two paths being able to drift apart from each
other.

### The design

Three sources behind one `Support\Contracts\AttendanceSource` interface,
picked by `config('nureen.attendance.source')` (`.env`-overridable, no
code change to switch): an **inbox folder** a scheduled export drops files
into, a **push endpoint** (`POST /attendance/ingest`, authenticated by an
`X-Api-Key` header, throttled, outside the session/CSRF middleware since
nothing calling it has a browser session) that stages records for the next
sync, and a **simulated** source for demoing against seeded students with
no real feed to point at yet.

`Support\AttendanceIngestor` is the single routine both the manual upload
and every automated source funnel through — extracted from the manual
upload's own logic rather than written fresh, so the two paths can never
validate or record a row differently. Idempotent for free, since both
paths already called `AttendanceRecord::recordPeriod()`, which upserts on
`(student_id, period_end)`.

`Support\AttendanceSyncRunner` holds the actual sync logic, called
directly by both `php artisan attendance:sync` and a "Sync Now" button on
the upload page — **not** via `Artisan::call()` from the controller, which
does not work for commands registered only when
`$this->app->runningInConsole()` is true (see "A bug caught" below).

### What changed

New, entirely inside `app/Modules/Nureen/`:
- `Support/Contracts/AttendanceSource.php` — the interface.
- `Support/AttendanceSources/{InboxFolderSource,PushEndpointSource,SimulatedSource}.php`
- `Support/AttendanceIngestor.php`, `Support/AttendanceSyncRunner.php`,
  `Support/AttendanceSourceFactory.php`, `Support/IngestSummary.php`
- `Console/Commands/SyncAttendance.php`
- `Http/Controllers/AttendanceIngestController.php` — the push endpoint.
- `Models/AttendanceSyncRun.php`, `Models/AttendanceIngestQueueEntry.php`
  + their migrations.
- `config/nureen.php` — `attendance.source` / `inbox_path` / `ingest_key`.
- `tests/Feature/Nureen/AttendanceSyncTest.php` (9 tests),
  `AttendanceIngestEndpointTest.php` (5 tests).

Changed:
- `AttendanceController.php` — `upload()` now delegates to
  `AttendanceIngestor`; new `syncNow()` for the button.
- `AttendanceSheet.php` — new `readFile()`, shared by the manual upload
  and `InboxFolderSource` so both parse a spreadsheet identically.
- `ModuleProvider.php` — binds `AttendanceSource`, registers
  `SyncAttendance`, self-schedules `attendance:sync` daily at **02:00**
  via `Schedule::command(...)` called directly from `boot()` — registers
  onto the one scheduler `artisan schedule:run` reads regardless of which
  file calls it, so no edit to any shared `routes/console.php` was needed.
- `routes.php` — `POST /attendance/sync-now` (authenticated, the button);
  `POST /attendance/ingest` (outside the normal auth group, API-key
  checked inside the controller).
- `attendance/upload.blade.php` — a new "Automated Sync" status card
  (active source, last run, read/added/updated/rejected counts) above the
  existing form, relabelled "Manual Fallback."

Flagged in `app/Modules/Nureen/SHARED_CHANGES.md`, not applied: a CSRF
exemption for `/attendance/ingest` in `bootstrap/app.php`; a
`NUREEN_ATTENDANCE_INGEST_KEY=` line needed in `.env.example`.

### A bug caught before it shipped

The first version called `Artisan::call('attendance:sync')` from the "Sync
Now" controller action. It failed silently — Laravel only registers
console commands when `$this->app->runningInConsole()` is true, which a
web request never is. Fixed by extracting the sync logic into
`AttendanceSyncRunner` and having the command and the controller both call
it directly, with no `Artisan` involvement at all.

### Verification

- Full test suite passed, including the 14 new Attendance tests covering
  all three sources, the manual-fallback path staying intact, and the
  push endpoint's API-key authentication and throttling.
- Confirmed the manual upload and an automated inbox-folder sync produce
  identical `attendance_records` rows for the same input file.

---

## 2026-10-24 — GA Extension completeness checks, SLA reminders, analytics, and verifiable certification letters

**Branch/commit:** `develop`, commit `2163a09` (bundles three separate
tasks — see "A note on how this landed" below)

### Task 1 — GA Extension: reduce manual checking by CGS staff

**The ask:** CGS staff were manually cross-checking an applicant's own
details and eyeballing whatever got attached for completeness.

**What changed:**
- The form now opens with a **read-only applicant block** (name, matric
  no., programme, supervisor) pulled straight from the logged-in
  student's profile — nothing re-entered, nothing that can drift from the
  real record.
- **Three separately-required document types** (GA extension form,
  supervisor's supporting letter, acceptance letter *or* published paper)
  instead of one generic upload, each its own
  `<x-nureen::multi-file-upload>` field, validated and labelled
  separately via `DocumentStore::manyRules()`/`manyMessages()` called once
  per type.
- A **"System checks" panel** on the approver's detail view: a live ✓/✗
  per required type, computed from the application's actual attached
  documents against the same config list that enforced it at submission.
- **CGS Staff can send an application back to the supervisor**
  (`GaExtensionController::returnToSupervisor()`, mandatory reason) via
  `WorkflowEngine::returnTo()`, instead of only being able to approve or
  reject — copied from
  `Hani\Http\Controllers\ExaminerNominationController::returnToDepartment()`,
  the existing pattern for this exact need.
- **Config-driven extensibility**: `config('nureen.ga_extension')` now
  holds both the required-document list and an `additional_fields` array
  (key → label/type/required) for whatever the real CGS form turns out to
  need later. Empty today; a new field is one config entry away from
  appearing in the form, its validation, and the approver's panel — no
  migration, no Blade edit. Storage already exists
  (`ga_extension_details.additional_info`, nullable JSON) and sits unused
  until the array is.

Files: `config/nureen.php`, a new migration for `additional_info`,
`Models/GaExtensionDetail.php`, `Http/Controllers/GaExtensionController.php`,
`ga_extension/{form,_detail,queue}.blade.php`, `routes.php` (new
`POST /ga-extension/{application}/return`),
`tests/Feature/Nureen/GaExtensionTest.php` (rewritten, 16 tests).

### Task 2 — Stop applications getting stuck unnoticed (GA Extension, Supervision, Certification)

**The ask:** nothing told anyone when an application had been sitting,
untouched, at one stage for days.

**What changed:**
- **`Support\StalledApplicationReminder`** (new, generic): finds pending
  applications stalled past a per-module threshold (3 days by default,
  `config('nureen.reminders.*')`), resolves recipients as everyone
  holding the current stage's role (department-scoped the same way the
  engine scopes `decide()`), and records that a reminder went out so it
  won't repeat inside the same window. GA Extension's and
  Certification's reminder commands
  (`Console/Commands/RemindStalledGaExtension.php`,
  `RemindStalledCertification.php`) are thin wrappers over this, both
  notifying via a new generic `Notifications\ApplicationStalled`.
- **Supervision kept its own command** rather than switching to the
  generic service, because its `supervisor` stage is addressed to one
  **named** person, not "anyone holding the Supervisor role" — the one
  case the generic resolver can't handle. It gained **escalation**: after
  a configurable number of reminders (2 by default) to the named
  supervisor with still no decision, every Non-Exec CGS account is also
  notified, informationally, via the new `Notifications\SupervisionEscalatedToCgs`.
  The reminder count is tracked per-current-stage
  (`reminder_count`/`reminded_stage_key` on `supervision_details`), so
  moving to the next stage resets it rather than carrying a stale count
  forward.
- **`Support\ProcessingTimeAnalytics`** (new): average days per
  `(module, stage)`, computed in PHP from consecutive `approval_history`
  rows per application rather than a database window function — portable
  across the MySQL dev database and the SQLite in-memory test database.
  Backs a new **CGS-only `/reports/processing-time` page**
  (`Http/Controllers/AnalyticsController.php`): a bar chart plus an
  overdue-applications table, the overdue threshold reusing the exact
  same `stalled_days` config the reminders fire on, so the two numbers
  can never disagree.

Files: `config/nureen.php` (`reminders.*`), two migrations
(`reminder_count`/`reminded_stage_key` on `supervision_details`; new
`application_reminders` table shared by GA Extension/Certification),
`Support/StalledApplicationReminder.php`,
`Notifications/{ApplicationStalled,SupervisionEscalatedToCgs}.php`,
`Console/Commands/{RemindStalledGaExtension,RemindStalledCertification}.php`,
`Console/Commands/RemindStalledSupervisionRequests.php` (escalation added),
`Models/{SupervisionDetail,ApplicationReminder}.php`,
`Support/ProcessingTimeAnalytics.php`,
`Http/Controllers/AnalyticsController.php`,
`analytics/index.blade.php`, both self-scheduled daily at **08:00** from
`ModuleProvider::boot()`, `tests/Feature/Nureen/StalledReminderTest.php`
(7 tests), `AnalyticsTest.php` (4 tests).

**A bug caught only by driving the real page, not by PHPUnit:** the
analytics chart used a horizontal bar (`indexAxis: 'y'`) with two-line
array-style category labels. It rendered correctly on first paint, then
the bars became invisible the moment dark mode toggled — the shared
`retheme()` helper's `Chart.instances[key].update('none')` call interacted
badly with that configuration specifically. PHPUnit never renders
Chart.js at all, so nothing caught it until a live screenshot was taken
after a theme toggle. Fixed by rebuilding it as a plain vertical bar chart
with flat string labels, copying `admin-status-chart.blade.php`'s
already-proven structure (same scale shape, same datalabel anchor)
instead of inventing a new chart shape. Confirmed correct in both themes
afterward via live screenshots.

### Task 3 — Make GA/GRA Certification letters verifiable

**The ask:** letters had no way for anyone outside CGS to check they were
real, and CGS was manually cross-referencing the applicant's appointment
by eye.

**What changed:**
- **Automatic appointment verification** shown to CGS Staff and the
  Senior Director before they decide: Verified / Not Found / Mismatch,
  informational only, never blocking either way
  (`Support/AppointmentVerifier.php`). It reads from a new
  `ga_appointment_records` table that is explicitly a **provisional
  stand-in** — Haziq's GA/GRA module, which should really own this data,
  has no models or migrations at all yet (confirmed empty via `find
  app/Modules/Haziq`; his own module README already flags the overlap
  with Nureen's modules as something the team needs to agree on). Noted
  in `SHARED_CHANGES.md` as a `Core\Contracts\SuppliesAppointmentStatus`
  seam to swap onto his real data once ownership is settled.
- **A unique, sequential reference number** (`CGS/GACERT/<year>/<seq>`,
  via `Support/CertificateReferenceGenerator.php`, race-safe with
  `lockForUpdate()`) assigned the moment the Senior Director's approval
  generates the PDF.
- **A public, no-login verification page** —
  `GET /verify/certificate/{reference}` — showing exactly four facts
  (student name, letter type, issue date, the approver who endorsed it,
  the last read from `approval_history` rather than re-stored) plus a
  Valid/Revoked badge, or a clear "not found" message for an unknown
  reference. Throttled, since it's reachable with no session.
- **A QR code on the PDF** linking to that page, behind a
  `Contracts\GeneratesQrCode` seam. No QR-generating package exists in
  `composer.json` yet, so the default binding
  (`UnavailableQrCodeGenerator`) returns `null` and the PDF template
  falls back to printing the plain URL as text — flagged in
  `SHARED_CHANGES.md` with the exact `require` line needed
  (`endroid/qr-code`) for whenever that's added.
- **Revocation**: Non-Exec CGS or the Senior Director can revoke an
  issued certificate with a mandatory reason. This surfaced a real gap —
  the existing Revoke control would only have been reachable from the
  *pending* queue, but an approved application drops out of that queue
  entirely — so a new **"Issued Certificates" list**
  (`GET /ga-certification/issued`, linked from the queue via a new
  `$tools` slot) was added as the only place Revoke is actually reachable
  once a certificate exists. Revoking never touches
  `applications.status` — the application stays approved; only the
  certificate's own validity changes.

Files: `config/nureen.php` (`ga_certification.reference_prefix`), three
migrations (`reference_number`/`revoked_at`/`revoked_reason` on
`ga_certification_details`; new `ga_appointment_records` table),
`Models/{GaAppointmentRecord,GaCertificationDetail}.php`,
`Support/{AppointmentVerifier,CertificateReferenceGenerator}.php`,
`Support/Contracts/GeneratesQrCode.php`,
`Support/QrCode/UnavailableQrCodeGenerator.php`,
`Http/Controllers/{CertificateVerificationController,CertificationController}.php`
(new `revoke()`, new `issued()`), `certificate/verify.blade.php`,
`ga_certification/{_detail,_certificate_status,_tools,issued,certificate_pdf}.blade.php`,
`Database/Seeders/GaAppointmentRecordSeeder.php` (2 demo records against
real seeded students — one Verified, one Mismatch — run with
`php artisan db:seed --class=...`, following Jason's
`ExaminerPoolSeeder.php` convention rather than being wired into Core's
`DatabaseSeeder`), `routes.php` (`POST
/ga-certification/{application}/revoke`, `GET /ga-certification/issued`,
`GET /verify/certificate/{reference}`),
`tests/Feature/Nureen/CertificateVerificationTest.php` (13 tests).

### Verification (all three tasks)

Full suite passed (314 tests) after all three tasks landed. Live
Playwright verification covered: the public verification page in both
light and dark mode for a valid certificate; an unknown reference showing
"not found"; the Issued Certificates list; revoking a certificate from it
and confirming the public page immediately showed "Revoked" and no
longer showed "Valid."

### A note on how this landed

All three tasks were built and left uncommitted per their own
instructions ("leave all changes uncommitted so I can review them in
Source Control"). Before that review happened, the user committed
externally (outside this session) on top of the Attendance automation
commit, and separately pulled in a teammate's independent commit
(`DocumentStore::manyMessages()`, used identically by both
`GaExtensionController` and `SupervisionController`) via `git pull
--tags origin develop` — also outside this session. The next pull/rebase
replaying the session's local commits onto that new base produced a real
conflict in `GaExtensionController.php` (the session's three-document
validation loop vs. the teammate's `manyMessages()` refactor, both
touching the same lines). This surfaced as PHPUnit intermittently failing
on a literal `<<<<<<<` token, misleadingly attributed by the stack trace
to an unrelated file.

Resolved by keeping the session's three-document-type loop structure but
switching its hand-written validation messages to
`DocumentStore::manyMessages($field)` per field — exactly the call
`SupervisionController.php` already used for the same refactor, confirmed
via `git diff` against both sides before touching anything. Conflict
markers removed, `php -l` clean, GA Extension's 16 tests then the full
314-test suite passed, `git rebase --continue` completed cleanly. Net
effect: because the commit had already happened externally before the
conflict was discovered, all three tasks' work ended up inside the
resulting commit (`2163a09`) rather than staying uncommitted the way each
task's own instructions asked for — worth knowing if you're trying to
find a clean "before this task" point in history.
