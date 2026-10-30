# Workstation module inventory

Verified against the code in `app/Modules/Chloe/` as of this writing. Facts
only; where something could not be confirmed directly from the code, that
is stated rather than guessed. No secrets, no personal data.

## (a) Screens and routes, by role

All routes are registered in `app/Modules/Chloe/routes.php`, auto-loaded by
`ModuleServiceProvider` (no central route file to edit). Not an approval
chain — no `WorkflowEngine`/`Stage` gating, unlike every other module.

### Student

| Route | Controller action |
|---|---|
| `GET /workstation` | `WorkstationController::index` — block picker |
| `GET /workstation/blocks/{block}` | `WorkstationController::rooms` |
| `GET /workstation/rooms/{workstationLocation}` | `WorkstationController::seats` |
| `POST /workstation/{workstation}` | `WorkstationController::store` — select a seat |
| `GET /workstation/confirm/{token}` | `WorkstationController::confirm` — email-link confirmation; outside `auth`, token is the authorisation |
| `POST /workstation/return` | `WorkstationController::returnWorkstation` |
| `POST /workstation/locker-key` | `LockerKeyController::store` |
| `POST /workstation/locker-key/cancel` | `LockerKeyController::cancel` |
| `GET /workstation/terms`, `/workstation/terms/pdf` | `WorkstationTermsController::show`, `downloadPdf` — outside `auth`, every outbound email links here |
| `GET /candidacy` | `CandidacyController::status` |
| `GET /candidacy-appeal/new`, `POST /candidacy-appeal`, `GET .../{application}/edit`, `POST .../{application}/resubmit` | `CandidacyAppealController` |

### CGS (middleware `role:` the CGS team roles)

| Route | Controller action |
|---|---|
| `GET /cgs/workstation` | `WorkstationManagementController::index` |
| `GET /cgs/workstation/insights` | `WorkstationInsightsController::index` |
| `GET /cgs/workstation/blocks/{block}` | `WorkstationManagementController::block` |
| `GET /cgs/workstation/rooms/{workstationLocation}` | `WorkstationManagementController::room` |
| `GET /cgs/workstation/export/seats`, `/export/history` | `WorkstationManagementController::exportSeats`, `exportHistory` |
| `GET /cgs/workstation/suggestions` | `WorkstationManagementController::suggestions` (throttled `30,1`) |
| `GET /cgs/workstation/seats/{workstation}/students/search` | `WorkstationManagementController::searchStudents` |
| `GET /cgs/workstation/seats/{workstation}` | `WorkstationManagementController::show` |
| `POST .../reassign`, `/release`, `/release-now`, `/withdraw-termination`, `/maintenance`, `/release-hold`, `/restore`, `/assign`, `/key/collected`, `/key/returned` | `WorkstationManagementController` (one action each) |
| `POST /cgs/workstation/{workstation}/force-assign`, `/force-release` | `WorkstationOperationsController` — superseded, kept working |
| `POST /cgs/workstation/locker-keys/{lockerKey}/collected`, `/returned` | `WorkstationOperationsController` — superseded, kept working |
| `GET /cgs/workstation/catalog`, `POST .../locations`, `POST .../seats`, `PUT .../seats/{workstation}`, `DELETE .../seats/{workstation}` | `WorkstationCatalogController` |
| `GET /cgs/candidacy` | `CandidacyOperationsController::index` |
| `GET /cgs/candidacy/dismissals`, `POST .../refresh`, `POST .../{dismissal}/confirm` | `CandidacyDismissalController` |

### Shared (any authenticated role, not role-gated)

| Route | Purpose |
|---|---|
| `GET /workstation/assistant/suggestions`, `POST /suggestion`, `POST /ask` | Assistant backend, throttled `assistant-user`. A role the assistant does not support gets an in-app message, not a 403. |

### Appeal approval chain (uses `WorkflowEngine`, unlike the rest of the module)

`GET /candidacy-appeal/queue`, `POST /candidacy-appeal/{application}/decide` — roles Supervisor, Chair, Non-Exec CGS, Dean PGR.

## (b) Seat and locker key statuses

### `Workstation.status` (`app/Modules/Chloe/Models/Workstation.php`)

| Status | Meaning | Set by |
|---|---|---|
| `available` | Open for selection | Seed, `restoreSeat()`, `markReturned()` (when the seat was Awaiting Key Return), auto-release |
| `pending` | A student has selected it, confirmation window open | `WorkstationAllocator` on selection |
| `occupied` | Confirmed holder | Confirmation (student clicks email link), `assign()`, `reassign()` |
| `reserved` | Not used in the current flow (no code path sets it) | — |
| `disabled` | Under maintenance | `setMaintenance()` |
| `awaiting_key_return` | Seat released/returned but the locker key is still out | `releaseSeat()`/auto-release, when the outgoing key was Collected |

### `LockerKey.status` (`app/Modules/Chloe/Models/LockerKey.php`)

| Status | Meaning | Set by |
|---|---|---|
| `requested` | Awaiting collection | `LockerKeyController::store` |
| `collected` | With the student | `LockerKey::collect()` — CGS only, "Mark as Collected" |
| `returned` | Back with CGS | `LockerKey::markReturned()` — CGS "Mark as Returned" |
| `cancelled` | Student cancelled, or auto-cancelled after 7 days uncollected | `LockerKeyController::cancel`, `LockerKey::processIfOverdue()` |
| `return_pending` | Was Collected, now owed back (seat released/reassigned) | `WorkstationAllocator` release/reassign path |
| `lost` | Written off, never expected back | `WorkstationAllocator::releaseHold()` |

## (c) Time parameters and limits

| Parameter | Value | Config key / constant | File |
|---|---|---|---|
| Seat-selection confirmation window | 5 minutes | `WorkstationAllocator::PENDING_SELECTION_MINUTES` | `Services/WorkstationAllocator.php` |
| Locker key collect-by window | 7 days | `LockerKey::COLLECTION_PERIOD_DAYS` | `Models/LockerKey.php` |
| Locker key collection reminder | day 3 (uncollected) | `LockerKey::GRACE_DAYS` | `Models/LockerKey.php` |
| Locker key return period | 7 days from release (or vacate-by date for automatic release) | `WorkstationAllocator::RETURN_PERIOD_DAYS` | `Services/WorkstationAllocator.php` |
| Locker key return reminder | 3 days before `return_due_at` | `LockerKey::RETURN_REMINDER_DAYS_BEFORE` | `Models/LockerKey.php` |
| Termination notice vacate period | 5 working days | `WorkstationAllocator::TERMINATION_NOTICE_WORKING_DAYS` | `Services/WorkstationAllocator.php` |
| Candidacy appeal extension cap | not re-verified this pass — see `StudyCandidacy::remainingAppealMonths()`/`canAppeal()` | `Models/StudyCandidacy.php` | — |
| Almost-full room threshold | 90% (default) | `ASSISTANT_ALMOST_FULL_PERCENT` env, `config('assistant.almost_full_percent')` | `config/assistant.php` |
| Assistant: primary model requests/minute | 12 | `config('assistant.rate_limits')['gemini-3.5-flash-lite']['requests_per_minute']` (keyed by model name; read the whole map -- model names contain dots) | `config/assistant.php` |
| Assistant: primary model requests/day | 400 | same map, `requests_per_day` | `config/assistant.php` |
| Assistant: fallback model requests/minute | 4 | same map, `gemini-3.8-flash` entry | `config/assistant.php` |
| Assistant: router "Did you mean" threshold | 0.9 (chosen from the 2026-10-30 evaluation, see the config comment) | `ASSISTANT_ROUTER_MIN_CONFIDENCE` env, `config('assistant.router_min_confidence')` | `config/assistant.php` |
| Assistant: per-user requests/minute | 10 | `config('assistant.rate_limits.user_requests_per_minute')` | `config/assistant.php` |
| Assistant: total request deadline | 22 seconds | `ASSISTANT_TOTAL_DEADLINE_SECONDS` env | `config/assistant.php` |
| Assistant: single HTTP call timeout | at most 10 seconds per attempt, never past the total deadline | `Pipeline::attemptTimeout()` | `Assistant/Pipeline.php` |
| Assistant: retries | 2 retries on the same model (waits 2 s, 5 s; never after a 429), then 1 fallback-model attempt | `config('assistant.retry_waits_seconds')` | `config/assistant.php` |
| Assistant: circuit breaker open duration | 60 seconds (default) | `config('assistant.circuit_breaker.open_seconds')` | `config/assistant.php` |

Note: the measured real round-trip for one Gemini call during this audit
was ~11 seconds (`assistant:check`), which is close to or over the 10-second
per-call cap above — see the Part 2 findings in the audit report for how
this affected the evaluation run.

## (d) Emails and notifications

All mail classes implement `ShouldQueue`, in `app/Modules/Chloe/Notifications/`.

| Class | Subject | Recipient | Trigger |
|---|---|---|---|
| `WorkstationSelectionPendingConfirmation` | "Action Required: Confirm Your Workstation Selection (Valid for 5 Minutes)" | Student | Seat selected |
| `WorkstationSelectionConfirmed` | "Workstation Confirmed" | Student | Confirmation link clicked |
| `LockerKeyNotice::REQUESTED` | "Locker Key Request Received" | Student | Key requested |
| `LockerKeyNotice::REMINDER` | "Reminder: Please Collect Your Locker Key" | Student | Day 3 uncollected |
| `LockerKeyNotice::CANCELLED` | "Locker Key Request Cancelled" | Student | Day 7 auto-cancel |
| `LockerKeyNotice::RETURN_REMINDER` | "Reminder: Please Return Your Locker Key" | Student | 3 days before `return_due_at` |
| `WorkstationStatusNotice::RELEASED` | "Workstation Released" | Student | CGS manual release |
| `WorkstationStatusNotice::RETURNED` | "Workstation Returned" | Student | Student's own Return Workstation |
| `WorkstationStatusNotice::CHANGED` | "Workstation Changed" | Student | CGS reassign |
| `WorkstationStatusNotice::ASSIGNED` | "Workstation Assigned" | Student | CGS Assign Student |
| `WorkstationStatusNotice::RELEASE_NOTICE` (superseded type, no longer sent — see note in code) | "Workstation Release Notice: N Days Remaining" | Student | — |
| `WorkstationStatusNotice::TERMINATION_NOTICE` | "Workstation Loan Terminated: Please Vacate by {date}" | Student | Termination started (manual or automatic trigger) |
| `WorkstationStatusNotice::TERMINATION_WITHDRAWN` | "Workstation Termination Notice Withdrawn" | Student | Withdraw Termination |
| `CandidacyReminder` | "Study Candidacy Expiry Reminder — {date}" | Student | Monthly, from 3 months before expiry |
| `CandidacyDismissed` | "Study Candidacy — Dismissal Confirmed" | Student | CGS confirms a dismissal |
| `CandidacyAppealApproved` | "Study Candidacy Appeal Approved — New Expiry Date" | Student | Dean approves the appeal chain |

CGS performs every seat action through an in-page banner only — confirmed
in `WorkstationManagementController`: no notification is sent to CGS for
any of its own actions (release, reassign, assign, maintenance, restore,
release hold, mark collected/returned).

Bell/in-app notification text was not separately catalogued in this pass —
the notification classes above are Mail-only (`via()` returns `['mail']`
in each one checked); no separate database-notification/bell channel was
found for this module. If a bell UI exists elsewhere in the app reading
these same notifications, it was not traced in this pass.

## (e) Console commands and scheduled jobs

| Command | Purpose | Options |
|---|---|---|
| `workstation:release-expired-selections` | Release seats whose 5-minute confirmation window expired | — |
| `workstation:remind-locker-keys` | Send key reminders, auto-cancel past 7-day window | — |
| `workstation:process-releases` | Start termination notices, auto-release at vacate-by, send return reminders | `--dry-run` |
| `workstation:demo-seed` | Give untracked Occupied seats a demo student; seed 3 unreturned-key seats | — |
| `workstation:demo-clear` | Remove every demo profile and their requests/keys/History rows | — |
| `workstation:demo-login` | Create/reset the "Chloe Ching" test student (`chloe_<matric>@utp.edu.my`) | `--gender=`, `--expiry-date=` |
| `candidacy:remind` | Send the next due monthly candidacy-expiry reminder | — |
| `candidacy:generate-dismissals` | Generate the dismissal candidate list for CGS review | — |
| `assistant:check` | One trivial request to the configured provider; reports OK/FAILED | `--allow-fallback` |
| `assistant:evaluate` | Measure the router on the labelled question set against the real provider (router only, paced, stops at the first 429) | `--resume`, `--only=failed`, `--group=`, `--limit=`, `--heldout`, `--report`, `--allow-fallback` |
| `assistant:ask` | Run the pipeline as a given user, print the answer, "Interpreted as", chips, catalogue id, confidence, model and time | `{email} {question}`, `--recent=*`, `--trace` |
| `assistant:catalogue` | Print the canonical question catalogue for a role | `{role}` (student or cgs) |
| `assistant:log` | Print the latest `assistant_logs` rows | `--limit=20` |

### Scheduled (`routes/console.php`)

| Job | Time |
|---|---|
| `workstation:remind-locker-keys` | Daily 08:00 |
| `candidacy:remind` | Daily 07:00 |
| `candidacy:generate-dismissals` | Daily 07:15 |
| `workstation:release-expired-selections` | Every minute |
| `workstation:process-releases` | Daily 07:30 |

## (f) Database tables owned by this module

| Table | Migration |
|---|---|
| `workstation_locations` | `2026_05_01_000100_create_workstation_locations_table.php` (+ `2026_05_04_000100` photo path, `2026_05_04_000300` rename to physical labels) |
| `workstations` | `2026_05_01_000200_create_workstations_table.php` |
| `workstation_requests` | `2026_05_01_000300_create_workstation_requests_table.php` (+ release scheduling, scheduled-release source, demo profile id, terms acceptance columns added later) |
| `locker_keys` | `2026_05_01_000400_create_locker_keys_table.php` (+ return fields, demo profile id) |
| `student_genders` | `2026_05_02_000100_create_student_genders_table.php` |
| `study_candidacies` | `2026_05_03_000100_create_study_candidacies_table.php` (+ rename dean_rejection→rejection) |
| `study_candidacy_reminders` | `2026_05_03_000200_create_study_candidacy_reminders_table.php` |
| `candidacy_appeal_details` | `2026_05_03_000300_...` (+ form sections, department columns added later) |
| `candidacy_appeal_publications` | `2026_05_03_000320_create_candidacy_appeal_publications_table.php` |
| `candidacy_dismissals` | `2026_05_03_000400_create_candidacy_dismissals_table.php` |
| `workstation_blocks` | `2026_05_04_000200_create_workstation_blocks_table.php` |
| `workstation_pending_selections` | `2026_05_04_000400_create_workstation_pending_selections_table.php` (+ one-confirmed-seat-per-student constraint) |
| `workstation_histories` | `2026_05_05_000100_create_workstation_histories_table.php` (+ student snapshot columns) — append-only |
| `demo_student_profiles` | `2026_10_26_000100_create_demo_student_profiles_table.php` |
| `workstation_termination_notices` | `2026_10_27_000300_create_workstation_termination_notices_table.php` |
| `assistant_logs` | `2026_10_28_000100_create_assistant_logs_table.php` (+ widened `answered_by`, added `model_used`/`error_code`, added `catalogue_kind`) |

Plus one data-migration: `2026_10_27_000200_convert_available_seats_with_a_return_pending_key_to_awaiting_key_return.php`.

## (g) Route roles/permissions

See (a) — every student group is `middleware('role:student')`, every CGS
group is `middleware('role:' . implode(',', Role::cgsTeam()))`, the appeal
queue adds Supervisor/Chair/Dean PGR. The assistant endpoints and the two
public routes (`/workstation/confirm/{token}`, `/workstation/terms*`) are
the only ones not role-gated, for the reasons noted inline in `routes.php`
(token-as-authorisation; email links must open for anyone).

## (h) Assistant

### The flow for a typed question (`Assistant/Pipeline.php`)

1. **Role check** -- student and CGS only; anyone else gets an in-app message.
2. **Privacy** -- `PrivacyMasker::mask()` replaces every email and 8-digit
   student ID with `<EMAIL1>` / `<ID1>`; a known student name is refused
   outright; an email used as a lookup key is asked back ("Please use a
   Student ID or seat number."). None of these reach the model.
3. **An exact suggested-question text** is answered like its chip, no model call.
4. **A manipulation phrase** ("ignore your rules ...") is refused, no model call.
5. **The router** -- ONE Gemini call (structured JSON output, the Interactions
   API `response_format` schema) returns `{id, params, confidence,
   alternatives, clarify}`. The `id` enum is the asking role's own catalogue,
   so the model can only pick an existing entry; it never writes a data answer.
6. **Our validation** -- the id must be in the role's catalogue (otherwise
   invalid output -> step 8); only declared parameter names survive; a period
   must pass `PeriodExtractor` (else the lookup asks which month/quarter/year);
   an ID must be one of this question's own placeholders, mapped back to the
   real value only here; every other value goes through the existing
   validators in `Lookups::run()`. A short follow-up that names its own period
   ("and january?") uses that period.
7. **Execution by kind** -- *lookup*: the existing `Lookups` template, numbers
   from the database (an aggregate lookup may get the existing second compose
   call, validated); *guidance*: the chosen topic's text, optionally rewritten
   by ONE compose call given only that topic (rejected if it invents a number
   or says the text does not cover the question); *action*: never executed --
   a refusal that links the seat, the student's seat, or Workstation
   Management; *out_of_scope*: the existing refusal (a student asking for
   CGS-only data gets a polite staff-only refusal instead).
8. **Fallback (Part 5)** -- when the router is unavailable (budget, 5xx, 429,
   timeout, after retries and the fallback model) or returns invalid output:
   `QuestionNormaliser` (lowercase, "u"/"pls"/"lah" etc.), the interrogative
   guard (how many / which / when / what is never an action), the action
   patterns, `PeriodExtractor`, the keyword rules, and finally "Did you mean"
   chips by catalogue keyword score plus the suggested questions. No further
   model call. Logged as `rule_fallback`.
9. **Transparency** -- every typed answer carries "Interpreted as: <canonical
   question with the parameters filled in>"; below
   `router_min_confidence` or when the router names an alternative, up to 3
   "Did you mean" chips. A chip click goes to `POST /suggestion` with the
   catalogue id and params -- validated the same way, no model call, no quota.

Budget per typed question: at most 1 router call + at most 1 compose call, both
through the per-model budgets, retries, fallback model and circuit breaker.

### The catalogue (`Assistant/Catalogue.php`, `php artisan assistant:catalogue {role}`)

The single source of truth: every lookup, every guidance topic, one action
entry per kind of operation, and out_of_scope -- 22 entries for students, 65
for CGS. Each entry has an id, kind, roles, ONE canonical question (a
suggested-question chip's exact text where one points at it), a description
with contrast hints, 4-6 invented example paraphrases, a parameter schema, a
link, and the lookup's template method / the guidance topic's summary (its
first sentences). `CatalogueTest` fails if a lookup, topic or action exists
in code without an entry, or the reverse.

**Student lookups (4):** `my_workstation`, `my_locker_key`, `available_seats`, `block_comparison`.

**CGS lookups (24):** `seat_availability`, `overall_stats`, `seat_lookup`,
`student_lookup`, `keys_pending_collection`, `keys_pending_return`,
`awaiting_key_return_seats`, `termination_notices`, `maintenance_seats`,
`room_occupancy`, `candidacy_expiring`, `releases_this_week`,
`seat_release_outlook`, `release_forecast`, `almost_full_rooms`,
`insights_summary`, `block_comparison`, `programme_breakdown`,
`gender_comparison`, `key_status_overview`, `candidacy_expiry_distribution`,
`length_of_stay_distribution`, `seat_status_by_block`, `return_outlook`.

`release_forecast` (one named month, quarter, year or span) sums the same
`WorkstationStatistics::seatReleaseForecast()` rows the Insights forecast
chart reads; the last seven reuse the exact `WorkstationStatistics` methods
the Insights charts read, so chat and page numbers cannot disagree.

**Guidance topics:** 15 student (`docs/knowledge/assistant/student/*.md`) +
15 CGS (`docs/knowledge/assistant/cgs/*.md`), loaded fresh on every call.

**Actions (11, never executed):** release, reassign, assign, mark key
collected, mark key returned, set maintenance, restore, terminate or
withdraw, release hold, cancel a key request (student), anything else.

### What is sent to Gemini

*Router call:* the fixed instructions; today's date and weekday
(Asia/Kuala_Lumpur); the asking role's catalogue (ids, canonical questions,
descriptions, parameter hints, invented example paraphrases, one-line topic
summaries from the static knowledge files); ~26 invented few-shot examples;
the masked question; up to 5 masked earlier questions from the browser. No
database value of any kind, no name, no email, no real ID.

*Guidance compose call:* the masked question and earlier questions, and the
ONE chosen topic's static knowledge text.

*Aggregate compose call (unchanged):* the masked question and the aggregate
lookup's structured result -- counts, room codes and programme names, never a
person. `seat_lookup`/`student_lookup`/`my_*` never get it.

### What is logged

`assistant_logs` -- `user_id`, `role`, `occurred_at`, `source`, `lookup` (the
catalogue id: a lookup name, `guidance:<topic>`, an action id, `out_of_scope`,
or a refusal type), `catalogue_kind`, `outcome`, `refusal_reason`,
`result_count`, `answered_by` (`model_router` = the router decided,
`rule_fallback` = the deterministic fallback decided, `direct`/`rule` for the
no-model paths), `model_used`, `error_code`. No question, answer, name, email
or ID column exists. The conversation is never stored server-side: the last 5
questions live in the browser only. `assistant:ask --trace` prints only masked
text and the router's ids/parameters, and never writes to the log.

## (i) Insights page cards

| Card (`#wsi-card-*`) | Shows | Computed by |
|---|---|---|
| `status-by-block` | Seat status (Occupied/Available/Maintenance/Awaiting Key Return) grouped by block | `insightsFactRows()`, tallied client-side |
| `forecast` | Seats that may be released by period (candidacy expiry / termination), projected available-seats line | `seatReleaseForecast()` / fact rows' `release_month`/`release_source` |
| `rooms` | Occupied/capacity per room, highest first | `insightsFactRows()`, grouped by room client-side |
| `gender` | Occupied/Available/Awaiting/Maintenance by Male vs Female rooms | `occupancyByGender()` / fact rows |
| `programmes` | Top 8 programmes by current seat holders | `programmeBreakdown()` |
| `candidacy` | Candidacy expiry buckets (0-3/3-6/6-12/12+ months) | `candidacyExpiryBuckets()` |
| `stay` | Length-of-stay buckets (under 6/6-12/12-18/over 18 months) | `lengthOfStayBuckets()` |
| `keys` | Locker key status grouped by block | `keyStatusOverviewFromFactRows()` / fact rows |
| `returns` | Key return outlook (due 7 days / 8-14 days / overdue) | `keyReturnOutlook()` |

All cards share one `population` built from `insightsFactRows()`
(embedded as JSON in the page), filtered/highlighted client-side — the
"two-layer model" (dropdown filters change the population; clicking
highlights). No caching anywhere in this read path (confirmed by grep —
`Cache::` appears only in `Pipeline.php`, for provider rate-limit/circuit
counters, unrelated to data values).

## (j) Test commands and demo accounts

Run the full suite: `docker exec uresearch-app php artisan test` (the
`uresearch-app` container; the host PHP in this environment lacks
extensions artisan needs). Filter to this module: `--filter=Workstation`
or `--filter=Insights`.

Demo accounts (names/roles only):
- CGS: `cgs@utp.edu.my` (Non-Executive CGS) — password not reproduced here, see README.
- Student test login: "Chloe Ching" (`chloe_<matric>@utp.edu.my`, matric generated), created/reset via `workstation:demo-login`.
- `workstation:demo-seed` populates additional, non-named demo student profiles for CGS screen testing (no real student data).

## (k) Shared files touched (outside `app/Modules/Chloe/`)

Routing, views and migrations are all auto-discovered by
`ModuleServiceProvider` by folder convention (see its own doc comment in
`app/Providers/ModuleServiceProvider.php`) — nothing in that provider
itself needed editing to add this module.

`public/js/chloe-workstation-insights.js` lives under the shared
`public/js/` directory (not under `app/Modules/Chloe/`), but is this
module's own file by naming convention, alongside every other person's
equivalently-named script in the same folder — not a shared file in the
sense of being edited by more than one person.

No edit to an actual Core-owned file (anything under `app/Modules/Core/`)
by this module was found in the git history searched (`git log --oneline
--all -- app/Modules/Core` filtered for Chloe/workstation/candidacy
references, which returned only this module's own merge commits). This
check is not exhaustive — the branch carries many unrelated commits from
other contributors, so a full line-level diff against `main` was not
attempted in this pass; if certainty matters, run
`git diff main -- app/Modules/Core` directly.

## (l) Known limitations

- `TODO.md`'s own Chloe section (searched for "## Chloe") still shows its
  original planning checklist entirely unchecked, even though the module
  described there is built and tested — that file was not kept in sync
  with actual progress for this module and should not be trusted as a
  completion signal.
- `Workstation::STATUS_RESERVED` is defined but no code path in this
  module sets it — either dead, or reserved for a flow not yet built.
- The candidacy appeal extension cap (months) was not re-verified in this
  pass; see `StudyCandidacy::remainingAppealMonths()`/`canAppeal()`
  directly.
- No separate bell/in-app notification channel was found for this module
  distinct from email — every notification class checked returns
  `['mail']` from `via()`. If a bell UI exists, it was not located here.
- Measured 2026-10-30 against `gemini-3.5-flash-lite`: a router call takes
  about 4.5-5 s; a question that also gets a compose call (guidance, an
  aggregate lookup) about 9-11 s -- inside the 22 s total deadline and the
  10 s per-attempt cap. The older note about ~11 s single calls timing out
  predates the router.
- `config('assistant.gemini.timeout')` and `config('assistant.gemini.retries')`
  still exist in `config/assistant.php` but no code reads them any more --
  per-attempt timeouts come from `Pipeline::attemptTimeout()` and retries from
  `retry_waits_seconds`.
- `WorkstationDemoSeedTest::test_termination_notice_dates_are_refreshed_on_rerun_not_duplicated`
  is date-dependent: run on a Friday, `travel(2)->days()` lands on Sunday and
  the working-day vacate-by date does not move, so it fails on Fridays.
