# Nureen Nellysha Binti Norazizi (22006973)

**Scope:** Attendance Record · GA Extension · Supervision · GA/GRA Certification Letter. Proactive intervention and document workflow automation.

## Built

- **GA Extension** — complete. Supervisor → CGS Staff → Senior Director CGS,
  with a required supporting document enforced at validation so incomplete
  applications never reach CGS.

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
| `WorkingDayCalendarTest` | weekends and configured holidays skipped; a due date landing on (or rolling past) a Friday; the charter calendar agreeing with Core's shared one for every day of 2026; both holiday lists applied |
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
