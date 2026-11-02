# Recent Updates

Changelog of changes made to this repo in recent Claude Code sessions, kept
for syncing a Claude Project's context. Each entry lists the problem, the
decision, what actually changed, and how it was verified, so a future
session (or a teammate) doesn't have to re-derive the reasoning from the
diff alone.

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
