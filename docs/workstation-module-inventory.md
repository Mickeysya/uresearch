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
| Assistant: primary model requests/minute | 12 | `config('assistant.rate_limits.primary.requests_per_minute')` | `config/assistant.php` |
| Assistant: primary model requests/day | 400 | `config('assistant.rate_limits.primary.requests_per_day')` | `config/assistant.php` |
| Assistant: fallback model requests/minute | 4 | `config('assistant.rate_limits.fallback.requests_per_minute')` | `config/assistant.php` |
| Assistant: per-user requests/minute | 10 | `config('assistant.rate_limits.user_requests_per_minute')` | `config/assistant.php` |
| Assistant: total request deadline | 22 seconds | `ASSISTANT_TOTAL_DEADLINE_SECONDS` env | `config/assistant.php` |
| Assistant: single HTTP call timeout | `min(10, total_deadline_seconds)` = 10 seconds in practice | `Pipeline.php` line ~496 | `Assistant/Pipeline.php` |
| Assistant: Gemini client timeout | 15 seconds, 1 retry | `config('assistant.gemini.timeout'/'retries')` | `config/assistant.php` |
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
| `assistant:evaluate` | Run the evaluation question set against the real provider | `--resume`, `--model-only`, `--only=`, `--allow-fallback` |
| `assistant:ask` | Run the pipeline as a given user, print the result | `{email} {question}` |
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
| `assistant_logs` | `2026_10_28_000100_create_assistant_logs_table.php` (+ widened `answered_by`, added `model_used`/`error_code`) |

Plus one data-migration: `2026_10_27_000200_convert_available_seats_with_a_return_pending_key_to_awaiting_key_return.php`.

## (g) Route roles/permissions

See (a) — every student group is `middleware('role:student')`, every CGS
group is `middleware('role:' . implode(',', Role::cgsTeam()))`, the appeal
queue adds Supervisor/Chair/Dean PGR. The assistant endpoints and the two
public routes (`/workstation/confirm/{token}`, `/workstation/terms*`) are
the only ones not role-gated, for the reasons noted inline in `routes.php`
(token-as-authorisation; email links must open for anyone).

## (h) Assistant

**Student lookups (4):** `my_workstation`, `my_locker_key`, `available_seats`, `block_comparison`.

**CGS lookups (23):** `seat_availability`, `overall_stats`, `seat_lookup`,
`student_lookup`, `keys_pending_collection`, `keys_pending_return`,
`awaiting_key_return_seats`, `termination_notices`, `maintenance_seats`,
`room_occupancy`, `candidacy_expiring`, `releases_this_week`,
`seat_release_outlook`, `almost_full_rooms`, `insights_summary`,
`block_comparison`, `programme_breakdown`, `gender_comparison`,
`key_status_overview`, `candidacy_expiry_distribution`,
`length_of_stay_distribution`, `seat_status_by_block`, `return_outlook`.

The last seven reuse the exact `WorkstationStatistics` methods the Insights
page's own charts read, so chat and page numbers cannot disagree.

**Guidance topics:** 15 student (`docs/knowledge/assistant/student/*.md`) +
15 CGS (`docs/knowledge/assistant/cgs/*.md`), loaded fresh on every call,
never cached.

**What reaches the model:** `PrivacyMasker::mask()` runs before any text is
sent — emails and 8-digit student IDs are reversibly masked, and a
detected name is a hard stop (refused), not masked. Lookups in
`config('assistant.aggregate_lookups')` (no personal data — counts and
programme names only) are eligible for an optional second model call that
phrases the structured numbers into prose; `seat_lookup`/`student_lookup`
always use the template, never a model call, since they may carry PII.

**What is logged:** `assistant_logs` — `user_id`, `role`, `occurred_at`,
`source`, `lookup` (a lookup name, `guidance:<topic>`, or a refusal-type
string — never user-typed text), `outcome`, `refusal_reason`,
`result_count`, `answered_by`, `model_used`, `error_code`. No `question` or
`answer` column exists on this table at all.

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
- The assistant's single-HTTP-call timeout (10s, from `min(10,
  total_deadline_seconds)`) is close to the real measured Gemini response
  time (~11s) in this environment, which can cause natural-language
  (model-routed) questions to time out even though the provider itself is
  reachable — see the audit report's Part 2 findings.
