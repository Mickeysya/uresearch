# Nureen Nellysha Binti Norazizi (22006973)

**Scope:** Attendance Record · GA Extension · Supervision · GA/GRA Certification Letter. Proactive intervention and document workflow automation.

## Built

- **GA Extension** — complete. Supervisor → Non-Executive CGS (default), or
  Supervisor → CGS Staff → Senior Director CGS (`final_approver`). Required
  documents are enforced at validation, so incomplete applications never
  reach CGS. The official GA Extension Form is generated from the student's
  answers rather than uploaded; see "The GA Extension Form, generated" below.

- **Attendance Record** — complete. UTrace data comes in as a CGS-uploaded
  CSV (`/attendance/upload`), not a live API — there's no UTP system access
  for this FYP; see `Support\AttendanceRiskEvaluator` for the rule-based
  early-warning logic (threshold + declining-trend) and
  `Notifications\AttendanceAtRisk` for the proactive alert. The appeal chain
  (`attendance_appeal`) is a single stage straight to Non-Executive CGS.
  A student with no rows yet reads **100%** on the dashboard gauge rather
  than 0% or a dash — that default is Core's
  (`StudentDashboard::STARTING_PERCENTAGE`), and `latestFor()` still returns
  `null`, so nothing is invented in these tables.
  **End-of-semester projection (CGS only):** after every sync and upload,
  `Support\AttendanceEarlyWarning` runs the original rule plus a
  least-squares projection of each student's semester attendance
  (`Support\AttendanceProjector`), stores the outcome in
  `attendance_risk_states`, and emails newly at-risk students (with a
  supervisor copy) without ever showing them the projection. CGS's At-Risk
  list shows current %, projected %, trend, reason and last emailed. An
  approved appeal excuses the missed sessions it covers. See the Attendance
  section of `docs/claude-context/architecture-and-modules.md`.
  **Student dashboard "Attendance Action" card** (`Support\AttendanceTarget`,
  worded by `AttendanceProvider::actionFor()`): "On track"; "Attend N of the
  next M sessions" (plain arithmetic on the student's own sessions, never
  the projection); "Justification needed" (or the appeal's status) once 80%
  is out of reach; "Keep checking in" before three weeks of data.
  Viva demo: `php artisan db:seed --class="App\Modules\Nureen\Database\Seeders\AttendanceRiskDemoSeeder"`
  sets the three cases (22093001 projection only, 22001011 current rule,
  22001012 steady) and prints each one's figures.
  **Backtest** (`php artisan attendance:backtest --synthetic`, or a
  full-semester export in the upload format): hides the periods after each
  cut-off (25/50/75% plus `--cutoff`), replays the live rule and projection
  in memory, and reports MAE/RMSE against the actual final %, precision /
  recall / F1 for rule, projection and both, and how much earlier the
  combined approach flags. Markdown report in `storage/app/backtest/`
  (git-ignored). `--help` documents the synthetic patterns. `--runs=10`
  repeats over ten seeds (mean ± SD); `--compare` puts the original
  projection window beside the configured one.
  **Projection window** (`attendance.projection_min_periods_to_flag` = 4,
  `projection_active_until` = 0.6): the projection may flag only from the
  4th period up to 60% of the semester; outside that only the rule flags.
  One gate, `AttendanceRiskEvaluator::projectionMayFlag()`, for the live
  check and the backtest.

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

## Postgraduate Handbook 2025 rules

These come from cross-checking each module against UTP's Postgraduate
Handbook 2025, the GA Terms & Conditions and the CGS onboarding briefing.
Every number and switch is in `config('nureen.*')`. New rules **warn** in the
approver's System checks; the one exception, which blocks, is
`ga_extension.allowed_levels` when CGS narrows it.

**Who is full-time research.** Core's `users` table doesn't say
(SHARED_CHANGES §16 proposes the fields). `Support\StudyProfile` takes it
from, in order:
1. the student's latest submitted GA/GRA Certification;
2. the student's latest submitted GA Extension;
3. `users.programme` text such as "PhD Part-Time" (mode and level only,
   never research/coursework).

Drafts never count.

**Attendance.**
- **Scope:** `attendance.scope`, `'all'` (default, as before) or
  `'full_time_research'`. Under the second, only full-time research students
  are risk-evaluated, flagged or alerted (`Support\AttendanceScope`, asked
  by the evaluator, the early warning and `AttendanceProvider`, so Core's
  dashboards agree). Students nothing is known about follow
  `attendance.scope_unknown`, default `'include'`. Attendance is still
  recorded and shown for everyone; an unmonitored student's card says "Not
  monitored".
- **Approved annual leave:** `attendance.leave` holds 14 days a year, split
  January 5, May 4, September 5, with no carry-over.
  - It is stored as `attendance_records.leave_days` and counted exactly like
    appeal-excused sessions, under `excused_sessions`.
  - It arrives two ways, both through the same column and recount:
    - an optional fifth sheet column, `leave_days`. A blank keeps what is
      recorded. It goes through `AttendanceIngestor`, so the manual upload,
      the inbox and the push API behave identically;
    - CGS's **Approved Leave** page (`/attendance/leave`), for a period
      already on file.
  - Going over a semester's or the year's allowance warns CGS: after an
    upload, on the leave page and on the At-Risk page. The days still count.
- **Alert email:** states the consequences. The GA/GRA allowance may be
  suspended, registration may be barred, and an international student's
  visa renewal may not be supported. This is wording only.

**GA Extension** (added to System checks):
- **Eligibility:** full-time by research (`ga_extension.eligible`). The form
  gained a **Programme type** (Research/Coursework) field for it.
- **Candidacy limit:** the requested end date must not pass admission +
  `rules.max_candidacy_years` (MSc 4, PhD 8). No allowance is paid beyond
  candidacy.
- **PhD Stage Gate Level 3:** either option passes. (1) A PUBLISHED Q1/Q2
  WoS article, or (2) one ACCEPTED and one PUBLISHED WoS/ERA/Scopus article.
  Both read Section C (each journal row now has an Accepted/Published
  status) plus the attached proofs, and the check says which option was met
  or what each is missing (`rules.phd_level3`).
- **`allowed_levels`:** default `['msc', 'phd']`, as on the July 2026 form.
  Set it to `['phd']` (`NUREEN_GA_EXTENSION_ALLOWED_LEVELS=phd`) and an MSc
  student sees why on the form and can't submit. A draft still saves.

**Supervision:**
- **A GA changing Main Supervisor** needs PGFAC approval first, so the
  request needs a **PGFAC Approval Memo** upload (`supervision.pgfac_memo`).
  - "Changing" means a Main Supervisor request while another is on record.
  - "GA" means an active `GaAppointmentRecord` of a `ga_types` type. With no
    record at all, the form asks the student.
  - What was known at submission is stored on `supervision_details`, and
    the approver's new System checks panel shows it with the memo.
- **Co-Supervisor approver:** `supervision.co_supervisor_approver`, `'chair'`
  (default) or `'dean_pgr'` (Core's existing Dean of PGR role). It is
  snapshotted per request like `cgs_stage`. Main Supervisor requests always
  go to the Chair.

**GA/GRA Certification:** choosing Coursework, Part-time or Full-time ODL
shows a note on the form and a warning in the System checks. It never
blocks, because a former GA may still need a letter
(`ga_certification.eligible`, `GaCertificationDetail::eligibilityCheck()`).

## The GA Extension Form, generated (UTP/CGS/001-A2)

Students no longer fill in and upload the GA Extension Form. They enter the
information and the system generates the official form as a PDF
(`LetterRenderer::gaExtensionForm()`, view `letters/ga_extension_form`,
route `ga-extension.form-pdf`). It is rendered fresh on each download, so it
shows the progress so far.

**Section A, the checklist.**
- **Items 1–3** are the required documents: IC/passport/visa, Admission &
  Offer letter, and the supervisor's letter.
- **Item 4** is the stage gate proof: an acceptance, a published article, or
  a soft bound submission. It is optional at submission, because a Level 1
  student has none yet.
- **Ticks:** the student column is ticked from the documents actually
  attached. The CGS column is ticked once CGS has passed the application.

**Section B.**
- **From the profile, read-only:** Name, Student ID, Programme and
  Supervisor. They are never read from the request.
- **Entered by the student:** the rest, defined in
  `config('nureen.ga_extension.applicant_fields')`. Each field is a column
  on `ga_extension_details`.
- **Account no. and IC/passport no.:** stored with the `encrypted` cast.
  They are masked (`•••• 1234`) everywhere except for the CGS stage's role
  (`GaExtensionDetail::revealsSensitiveTo()`), and that applies to the PDF
  too. The form never shows them back; leaving one blank keeps what is on
  file.
- **Bank details:** sit behind `collect_bank_details`
  (`NUREEN_GA_EXTENSION_COLLECT_BANK_DETAILS`, default true). When off,
  they are not asked, not validated, not changed, and left blank on the PDF.
- **Visa expiry date:** asked only when the student says they hold a visa.

**Section C, publications.** An add/remove-row list stored as JSON in
`publications`: type, authors, year, title, venue, volume, pages, DOI, ISSN,
database, quartile and impact factor. Rows left empty are dropped. On submit,
each remaining row needs its type, authors, year, title and venue.

**Section D, the declaration.** The form's wording, timestamped in
`declaration_accepted_at`. Required to submit, not to save a draft.

**Section E, endorsements.** The name, role and date of whoever acted at the
supervisor, CGS and Senior Director stages, read from `approval_history`.
- A stage not reached yet, or not on this application's chain
  (`final_approver_snapshot`), is left blank.
- Decisions before the latest return don't count, so a returned application
  shows only the endorsements it has now.

**Section J, CGS remarks.** Filled by CGS staff on the queue at
`cgs_verify` (route `ga-extension.cgs-remarks`); the fields are in
`cgs_remarks_fields`.

**Who can download it.**
- The owning student, a draft included: from the draft form, and from the
  list of their applications on the GA Extension page.
- The supervisor it is addressed to, and anyone holding another stage's role
  on that application's own chain.
- Everyone else gets 403.

**Rule checks** (`Support\GaExtensionRules`, numbers in
`config('nureen.ga_extension.rules')`). These come from the form's Terms and
Conditions and are shown on the approver's System checks panel as pass, fail
or unknown with the reason. They warn and don't block:
- total duration from admission within 24 months (MSc) or 42 months (PhD);
- extension length 3–12 months;
- start on the 1st or 16th;
- end on the 15th or the last day of the month;
- submitted at least one month before the start;
- the stage gate proof for the level.

The stage gate level is worked out from whole months completed since
admission at the extension's start. It is the highest level whose `from` is
at or below that number, so a boundary month belongs to the later level
(MSc month 18 → Level 3).

**The one hard rule** is that a backdated start is refused at submission.
The terms say backdated appointments are "not allowed".

**Older applications.** Applications submitted before this keep their
uploaded "GA Extension Form" and combined "Acceptance Letter or Published
Paper" (`legacy_documents`). Both still show; the combined proof still ticks
item 4 and counts for Levels 2–3. The rules they lack data for show as
unknown, not failed.

## The GA/GRA Certification form (CGS's online form)

The form follows CGS's official online form, section by section:

1. **Student details:** Matric ID, Name, Department and Programme come from
   the profile and are shown read-only. They are never read from the
   request, so they can't be overridden. Level (Coursework/Research), Level
   of Study (Master/PhD) and Mode of Study (Full-time/Full-time ODL/
   Part-time) are selects; the lists live in
   `config('nureen.ga_certification.options')`.
2. **Application type:** Reason/Purpose (optional) and GA/GRA. The
   appointment period is asked only while
   `config('nureen.ga_certification.ask_period')` is true
   (`NUREEN_GA_CERTIFICATION_ASK_PERIOD`, default true). When false, the
   dates are hidden, not validated, not stored, and not printed on the
   letter, which says "during the {semester} semester" instead.
3. **GA/GRA details:** one step holding both types' fields, showing the
   chosen type's. (One fieldset per type gave a GA applicant an empty "GRA
   details" step, because `form-stepper` shows every `.fstep` in turn.)
4. **Supporting details:**
   - **MyKad/Passport number:** stored with Laravel's `encrypted` cast.
     `GaCertificationDetail::identityFor($viewer)` shows it in full to CGS
     staff (`User::isCgs()`) and as `•••• 1234` to everyone else. The form
     never shows it back: "On file: •••• 1234. Leave blank to keep it."
   - **Supporting documents:** PDF only
     (`ga_certification.supporting_document_types`).
   - **Download pre-filled attachment:** recreates CGS's course-owner
     attachment (`letters/certification_attachment`, rendered by
     `LetterRenderer::certificationAttachment()`). It is pre-filled with the
     name, matric, programme, today's date and the first course row (for a
     GRA, the grant code, project and leader); signature and stamp stay
     blank. The button saves the draft first, so the PDF carries what is on
     screen, and the page it returns to starts the download. The route
     `ga-certification.attachment` serves the owning student only (403
     otherwise), from a draft or a submitted request.
5. **Approval & notification:** personal email, and the supervisor's email
   from `users.supervisor_id`, read-only.
6. **Agreement:** the declaration (`ga_certification.declaration`). It is
   required to submit but not to save a draft; `declaration_accepted_at`
   records when it was ticked.

Approvers' queue rows show the department and programme, the study
summary, the identity number (masked unless CGS), the personal email, the
supervisor's email and when the declaration was accepted. The letter adds a
"Level of Study" row ("PhD, Research, Full-time ODL"). The identity number
and personal email are for CGS's records and are not printed.

## Saved drafts (GA Extension, GA/GRA Certification)

**Save draft** sits beside Submit on both forms. Some documents take days to
collect, so a student can leave and resume.

- **Storage:** Core's own draft support. An `applications` row with status
  `draft` and `current_stage` NULL, plus the detail row (whose half-finished
  columns are nullable, migration `2026_11_04_000100`). There is no new table.
- **Saving:** `saveDraft()` creates the draft on the first save and updates
  it after that. It checks types, lengths, file types and sizes only, never
  "required". Files attach through `DocumentStore` immediately; a ticked
  **Remove** box deletes a file (from the disk too). The 5-file limit counts
  files already attached. `draft_step` records the form step.
- **Submitting:** `store()` runs the full validation. A document type the
  draft already has is satisfied by those files. It reuses the draft (row
  locked, so a double-click can't submit twice), snapshots the supervisor at
  submission, and calls `$engine->submit()`. On a Certification, files saved
  under the other type's label take the submitted type's label.
- **Who can act on it:** only the owning student can open, continue, submit
  or delete it (`Support\Drafts::ownedBy()`: 403 for anyone else, 404 once
  submitted). Delete removes the detail row, the documents and their stored
  files.
- **Continuing:** **My Drafts** (`/my-drafts`, sidebar link) lists drafts
  with the last saved time, Continue and Delete. Continue pre-fills every
  field, lists attached files, and reopens on the step last saved
  (`partials/draft-form-script`).
- **Never part of the workflow:** drafts are in no queue, reminder or
  analytics.
- **Core caveats:** Core's Track My Applications and dashboard panel still
  list drafts on this branch; see `SHARED_CHANGES.md` §11.

## The Client Charter

CGS commits to a number of working days per approval stage
(`config('nureen.charter_days')`: GA Extension 3 + 2, Supervision 3 + 3 (+ 0
for `cgs_review` in `'manual'` mode), Certification 3 + 2 + 2). Everything
the charter drives goes through two classes, so nothing can disagree:

- **`Support\WorkingDayCalendar`** counts Monday–Friday, minus
  `config('nureen.charter.holidays')` (empty by default; CGS can add
  Malaysian/Perak public holidays there).
- **`Support\ClientCharter`** works out, for one pending application, when
  it reached its current stage (newest `approval_history` row, else
  `submitted_at`), that stage's due date, whether it is overdue, and the
  student's expected decision date: the stage's due date (or today, if
  past) plus the charter days of every stage still ahead on *that
  application's* chain (mode snapshot respected; a return-to-supervisor
  just puts it back at an earlier position). A stage with a `null` charter
  gets no due date and no expected date, so no date is invented for it.

One rule ties them together: a stage is **overdue** once more working days
have passed than its charter allows, i.e. from the first working day after
its due date. That is the day its reviewer is first reminded.

| Where | What |
|---|---|
| Student sidebar → **Expected Decision Dates** (`nureen.expected-dates`) | "Expected decision by …", the charter note, and, only when overdue, "This stage is taking longer than the Client Charter…" |
| Approver queues (all three modules) | the stage's due date at the top of each row's detail; overdue rows sorted first (default order) and marked *Overdue* on the collapsed row |
| `*:remind-stalled` commands | remind on the charter, then repeat once per further charter period; Supervision's escalation unchanged. `stalled_days` is now only the fallback (in working days) for a `null`-charter stage |
| `/reports/processing-time` | per module and stage: decisions, % within charter, average working days vs the charter; the overdue table uses charter due dates |

The student's own status screens (`applications.show`, the dashboard panel)
are Core's, so the expected date is on a page of its own for now. See
`SHARED_CHANGES.md` §5 for the seam that would put it there instead. The
student dashboard's Active Applications card does show the nearest one
("In progress · next decision by 5 Nov"): the three charter workflows
implement Core's `SuppliesDecisionDates` (`Workflows\Concerns\ExpectsDecisionByCharter`).

Demo data: `NureenClientCharterDemoSeeder` (matric numbers 22091001–22091024,
on-time and overdue at every stage).

## Track My Applications (Core page, this module's part)

Each workflow declares its tab and icon (`public const CATEGORY` / `ICON`):
GA Extension and Certification under `assistantships`, Supervision under
`supervision`, Attendance Appeal under `candidacy`. Supervision and
Certification implement Core's `DescribesApplicationProgress`
(`Support\TrackingDescriptions`): an issued letter's real reference,
Download letter (PDF) as the primary button, and "Needs you" until the
student first opens it. Revoked letters say so. GA Extension says "All 4
required documents received." when they all arrived. The three charter
workflows implement `SuppliesOverdueStatus` through the charter trait.
`Support\WorkingDayCalendar` now extends Core's shared calendar and adds
`nureen.charter.holidays`. See `SHARED_CHANGES.md` §10.
The details page ("View details"): all four workflows implement
`DescribesApplicationDetails` (`Support\ApplicationDetailsContent`) for
"Your application" (GA Extension: dates and reason; Certification: the GA
course or GRA project fields, never the appointment check; Supervision:
supervisor, role, type and justification; Attendance Appeal: period and
sessions), reminders ("CGS Staff reminded", never a name), letter issued and
revoked events, the issued letter with its verification link, and each
stage's charter target. See `SHARED_CHANGES.md` §10b.

**Supervisor safeguards (3 Nov 2026):**
- **Blocked submission:** GA Extension and Certification can't be submitted
  without a supervisor who can act (`Support\SupervisorCheck`, on the form
  and on submit).
- **Submission email:** whoever acts first is emailed when a request is
  submitted, with the charter due date and a queue link
  (`Support\SubmissionNotifier`, `Notifications\ApplicationAwaitingYou`):
  the named supervisor for GA Extension, Certification and Supervision, and
  every Non-Executive CGS for an Attendance Appeal.
- **Waiting-for:** the details page names who it is waiting for
  (`waitingFor()`).
- **Stuck Reviews:** CGS's page at `/reviews/stuck`
  (`Support\StuckSupervisorStages`) lists requests nobody can act on, and
  reassigns a GA Extension or Certification to another supervisor. The
  reassignment is recorded in approval_history as `reassigned`, with the
  reason; it restarts the charter clock and is not counted as a decision.
- **Revocation email:** a revoked certification emails the student and the
  supervisor (`Notifications\CertificationRevoked`).

**Every email link opens for its recipient** (`EmailLinksTest` follows each
button as that person and expects 200):
- **Staff:** they land on `nureen.application`
  (`StaffApplicationController`), a read-only view of one application. It is
  open only to staff with a part in it (`Support\StaffApplicationAccess`):
  the named or requested supervisor, anyone who decided on it, a role on its
  chain (Chair: own department only), and for GA Extension the Senior
  Director.
- **Students:** they land on their own details page.
- **The appointment letter:** now emailed to the supervisor as their own
  message, not a CC on the student's.

## Official letters

Both letters, the Supervision appointment letter and the GA/GRA
certification letter, are built by **`Support\LetterRenderer`** on one
shared Dompdf layout, `Resources/views/letters/layout.blade.php`
(`letters/appointment` and `letters/certification` extend it). It contains:
the letterhead (logo and contact details from `config('nureen.letters')`;
text-only if the logo file is missing), Our Ref and date, addressee,
salutation, an uppercase subject, numbered paragraphs with a details table,
the closing and signature block, cc, and a footer on every page (verification
URL or QR, computer-generated notice, reference). Font subsetting is on: about
97 KB a letter, down from about 879 KB.

- **Who signs is read from `approval_history`, never passed in.** The
  appointment letter names the Non-Executive CGS user who endorsed it
  (`'endorse'`/`'manual'`). In `'automatic'` mode it names no endorser and
  says it was issued by CGS following approval by the named Chair. The
  certification letter names the Senior Director who approved.
- **Preview letter** (CGS at Supervision's `cgs_review`; the Senior Director
  at Certification's `senior_director`) renders the same letter with the
  viewer as signatory, "To be assigned on issue" and a DRAFT watermark. It
  writes and sends nothing.
- **Revocation** re-renders the stored PDF with a REVOKED watermark, keeping
  the same document row (see `SHARED_CHANGES.md` §7a).
- **One page, always.** The letterhead and footer are fixed bands in the
  top and bottom margins; the body (one 10 pt size) sits between them, and
  long values wrap inside the details table. Every form field printed on a
  letter has a maximum length in `config('nureen.ga_certification.fields.*.max')`
  / `purpose_max`, enforced by validation and shown on the form with a
  counter: course code 20, course name 120, course owner 80, project title
  150, project leader 80, grant/project code 40, semester 30, purpose 120.
  (The appointment letter prints no free-text form input; only names and
  records.) `LetterWorstCaseTest` renders every letter type with all of these
  at their maximum, and long realistic names, programme and department, and
  asserts exactly one page. Raise a limit only together with that test.
- **Demo documents:** the Nureen demo seeders attach valid one-page
  placeholder PDFs marked as demo data (`Database\Seeders\Support\DemoDocuments`),
  named like "Evidence of Work Done - <student>.pdf". `SeededDocumentsTest`
  parses every one.
- **Verification:** `/verify/certificate/{ref}` and
  `/verify/appointment/{ref}`, public, the same four facts, built from the
  same renderer data as the PDF.

Supervision's `cgs_stage` modes are `'endorse'` (default: Supervisor → Chair
→ CGS endorses the letter), `'automatic'` and `'manual'`; each application
keeps its snapshot.

## Two chains per module, one superset for queues

GA Extension (`final_approver`) has two chains and Supervision
(`cgs_stage`: `'endorse'`, `'automatic'`, `'manual'`) three, each
snapshotted per application at submission. `stages($application)`
returns that application's own chain; `stages(null)` returns **every stage
either chain uses**, because Core builds the sidebar and gates the queue
pages from it (`ModuleRegistry::queuesForRole()`). That is what keeps an
older application reachable after the default changes: e.g. a
`'senior_director'` GA Extension at the Senior Director, or a `'manual'`
Supervision request at `cgs_review`. `SnapshotChainReachabilityTest`
guards it.

## The one queue that narrows itself

The engine's queue is scoped by **role**, not by person: every
supervisor-role account is handed every request sitting at the `supervisor`
stage, because the student has no `supervisor_id` yet for the engine to filter
on — this request is what sets it. So `SupervisionController::queue()` passes
a `$scope` closure to `queueFor()`:

```php
$queue = $this->queueFor($request, $engine, ['documents'],
    function ($query, Stage $stage) use ($request) {
        if ($stage->key !== 'supervisor') {
            return;
        }

        $query->whereIn('id', SupervisionDetail::query()
            ->where('requested_supervisor_id', $request->user()->id)
            ->select('application_id'));
    });
```

**Applied to the query, never to the rows that come back.** It was briefly a
`->filter()` on the result, which reported the unfiltered total, paged over
rows it then discarded, and — because a paginator forwards unknown calls to
its collection — handed the view a plain `Collection`, which 500s the moment
it is asked for a total. `docs/conventions.md` has the rule; `SupervisionTest`
guards the behaviour.

GA Extension and Certification do the same for their own `supervisor`
stage (`GaExtensionDetail::supervisor_id` / `GaCertificationDetail::supervisor_id`,
snapshotted at submission), and each module's `decide()` and reminder
command use that same named person.

This closure is per-call and is **not** the general answer to "scope approver
queues to the right people" in `TODO.md`; the other queues are still
role-scoped pending that decision.

## Tests

`tests/Feature/Nureen/` — 23 cases, all four chains covered.

| File | Guards |
|---|---|
| `AttendanceProjectorTest` | the least-squares projection: the worked 87.5 → 62.5 example, steady attendance, too few periods, zero-session periods, clamping at 0 and 100% |
| `AttendanceEarlyWarningTest` | who is flagged and why; the email wording, which never contains the projection; no repeated alerts (margin, recovery); alerts recorded and shown as "last emailed"; sorting; students never seeing the projection; approved appeals excusing absences |
| `AttendanceTest` | the template round-trip (download it, feed it straight back), the header check, the date formats Excel writes, a bad row being skipped with a reason while the good rows save, re-uploading a period replacing rather than duplicating, and the at-risk alert firing on the transition |
| `GaExtensionTest` | document completeness — the module's point per `nureen.md` Module 2 — and the three-stage chain, asserting the position after *every* decision, because the middle stage is what the legacy app got wrong |
| `AttendanceBacktestTest` | mean ± sample SD; `--runs` over consecutive seeds (the reported mean equals the seeds' own runs); `--compare` side by side on the same data; a hand-worked 5-student dataset giving exactly MAE 6.98 and the expected precision/recall/earliness (directly and through the CSV parser); every metric at every cut-off on synthetic data; same seed = identical report; the documented pattern mix; no database writes or notifications at all; bad input; incomplete students left out |
| `AttendanceProjectionWindowTest` | the window's boundaries; no projection flag before period 4 (figure still stored) or after the active share; the rule still applies; the backtest replay matching the live `assess()` period by period for 21 synthetic students under both windows |
| `AttendanceActionCardTest` | the N-of-M arithmetic (rounding up, exactly 80%, already secured, unreachable, remaining sessions); the card's four states and the appeal statuses; the Active Applications decision date (nearest, none without a charter, own applications only); staff dashboards unchanged; the viva demo seeder's three outcomes, re-run safe, one email each |
| `AttendanceAppealTest` | the single stage being final, and that a student cannot attach another student's flagged record to their own appeal (403) |
| `SupervisionTest` | the required document, that it lands on the private disk under a random name, and that a supervisor sees only the requests naming them |
| `CertificationTest` | the letter being generated, attached as an `ApplicationDocument`, and actually dispatched |
| `CertificationOnlineFormTest` | GA and GRA submissions storing every online-form field; an option outside config's list rejected; profile fields (name, matric, department, programme, supervisor) not overridable through the request; the profile shown read-only; non-PDF rejected and several PDFs accepted; the declaration required to submit but not to save a draft; the identity number encrypted at rest, masked for the supervisor, full for CGS, never echoed on the form and kept when left blank; `ask_period` on (required, printed) and off (hidden, ignored, not printed, queue still renders); the attachment as a PDF for the owner only, and from a draft with the auto-download |
| `WorkingDayCalendarTest` | weekends and configured holidays skipped; a due date landing on (or rolling past) a Friday; the charter calendar agreeing with Core's shared one for every day of 2026; both holiday lists applied |
| `GaExtensionFormTest` | every Section B field stored; profile fields not overridable; visa date required only with a visa; declaration on submit not draft; backdated refused; account/IC encrypted at rest, masked for supervisor, student and Senior Director, full at the CGS stage, never echoed, blank keeps; bank flag both ways; publications JSON round-trip, half-filled rows on drafts but not submit, ISSN format; Section A ticks and CGS ticks after CGS passes; Section E on both chains, a rejection, and after a return; Section J at the CGS stage only; who may download (owner incl. draft, chain approvers, not other students/supervisors/Chair, not the Director on the `cgs` chain); an old application with an uploaded form still renders |
| `GaExtensionRulesTest` | each Terms and Conditions check passing and failing for MSc and PhD; the stage gate level at every boundary month; whole months since admission; the right proof per level and the old combined proof; unknown without data; every number read from config |
| `AttendanceHandbookTest` | `StudyProfile` from the programme text, the latest declaration and never a draft; the scope switch both ways (a part-time student flagged and alerted under `'all'`, not under `'full_time_research'`, while a full-time research student still is), `scope_unknown` both ways, and a stale flag cleared; leave counted like excused sessions under both `excused_sessions` rules and taking a student out of risk; the `leave_days` column through the ingestor, the manual upload (with and without it) and the push API/sync, a blank keeping recorded leave, and bad values rejected; CGS recording leave (and the refusals); the allowance within, over a semester and over the year, warned after recording, after an upload and on the At-Risk page; the alert's handbook consequences for student and supervisor |
| `GaExtensionHandbookTest` | eligibility pass/fail/unknown for MSc and PhD, with an ineligible student still submitting and the warning shown; the candidacy limit inside and past it for MSc and PhD, and from config; PhD Level 3 option 1, option 2, what each is missing, proofs needed beyond the listing, conference papers and status-less rows not counting, and MSc Level 3 unchanged; `allowed_levels` both ways (the message, the form note, a draft still saving, PhD accepted); a publication's status required on submit |
| `SupervisionHandbookTest` | GA status from records (expired, GRA, active GA); the PGFAC memo required for a GA on record changing supervisor, and for a declared GA, but not for a declared or recorded non-GA, a first Main Supervisor or a Co-Supervisor; the question shown only with no record; System checks; older requests; the Co-Supervisor approver as Chair by default and Dean of PGR when switched (the Chair refused, the Dean deciding), Main requests still to the Chair, the snapshot surviving a config change, and the automatic-mode letter naming the Dean |
| `CertificationHandbookTest` | the eligibility check pass/fail/unknown; a part-time student still submitting with CGS warned; an eligible request passing; the form note hidden until needed and shown for a coursework draft |
| `DraftsTest` | empty draft saves (both modules); saving again updates the same draft and keeps files; light validation only; Remove deletes the file; the 5-file limit counts attached files; an incomplete draft fails submit with the normal messages; a complete one goes to pending at the first stage as the same application, files intact; a Certification switching GA→GRA relabels its files; another student (or staff) can't open, change, submit or delete it; delete removes everything; no queue, reminder or analytics sees a draft |
| `ClientCharterTest` | each chain's expected date (GA Extension both modes, Supervision `'automatic'`, Certification), a `null` stage never inventing a date, overdue never giving a past date, a returned application timed from its actual stage, decided/draft returning nothing |
| `ClientCharterDisplayTest` | the student page (pending shown, decided not, overdue message only when overdue, own rows only) and the queue's overdue marker and order |
| `StalledReminderTest` | reminders on charter breach and not before, no reminder on a weekend alone, the `stalled_days` fallback, and Supervision's escalation |
| `LettersTest` | the `'endorse'` chain (no letter before CGS endorses; endorsing names the endorser; rejecting issues nothing; the preview writes and sends nothing); `'automatic'` naming no CGS endorser; the Senior Director who approved; every layout section, with and without the logo; one page; REVOKED after revocation; public verification; older snapshots completing; the Client Charter on the `'endorse'` chain |
| `SnapshotChainReachabilityTest` | `stages(null)` covering both chains; the Senior Director deciding an old GA Extension and CGS an old Supervision request in the default modes; GA Extension's supervisor stage visible, decidable and reminded only for the named supervisor |
| `AnalyticsTest` | exact % within charter from a hand-built set of decisions, and the overdue list matching charter due dates |

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
