# Technical paper facts (read-only collection)

Written for: Chloe, as source material for the FYP technical paper (Methodology, Results). Not paper text.

Collected on **2026-11-07** on the development machine (WSL2, Docker). Every number says how it was obtained. No code, schema, config or demo data was changed (the one exception, 6 sign-in audit rows, is listed in section 9). No seed, clear, reset or migrate command was run. No live Gemini call was made. No secret is printed.

## 1. System facts (Methodology)

| Fact | Value | How obtained |
|---|---|---|
| Laravel | 12.69.2 | `php artisan --version` in the app container |
| PHP | 8.3.33 (container) | `php -v` in the app container. `composer.json` requires `^8.2` |
| MySQL | 8.4.11 | `select version()` |
| Docker images | `sail-8.3/app` (app, queue, scheduler), `mysql:8.4`, `redis:7-alpine`, `phpmyadmin:5`, `axllent/mailpit` | `docker compose ps` |

**Containers (7)**, from `docker-compose.yml`

| Container | What it does |
|---|---|
| `uresearch-app` | Runs the Laravel web app (published on host port 8000) |
| `uresearch-queue` | `php artisan queue:work`, sends queued e-mail and notifications |
| `uresearch-scheduler` | `php artisan schedule:work`, fires every module's scheduled commands |
| `uresearch-mysql` | The database (port 3306) |
| `uresearch-redis` | Queue broker |
| `uresearch-phpmyadmin` | Database browser (port 8080) |
| `uresearch-mailpit` | Catches outgoing mail in development (SMTP 1025, web 8025) |

**Libraries** (from `composer.json` and `public/js`): `barryvdh/laravel-dompdf` (PDF: the appeal memo and the Term of Use PDF); `maatwebsite/excel` 4 (Excel export and the dismissal-upload import); Chart.js (`public/js/chart.umd.min.js`) with `chartjs-plugin-datalabels` (charts, self-hosted); `spatie/laravel-activitylog` (audit trail); `doctrine/dbal`.

**`app/Modules/Chloe` structure** (file counts from `find`, 2026-11-07)

| Folder | Files | Content |
|---|---|---|
| `Models/` | 29 | Eloquent models |
| `Http/Controllers/`, `Http/Middleware/` | 16 | Student, approver and CGS controllers |
| `Services/` | 8 | Allocator, appeal service, memo, signature, dismissal upload, document, status provider, seat catalogue |
| `Support/` | 28 | Rules (eligibility, dismissal list, reminder engine, statistics, programme type, pagination) |
| `Console/Commands/` | 27 | Artisan commands (reminders, dismissal, daily refresh, assistant tools, demo seed/clear) |
| `Assistant/` | 21 | Router, catalogue, lookups, guidance library, models (Gemini, fake), evaluation questions |
| `Workflows/` | 1 | `CandidacyAppealWorkflow` |
| `Notifications/` | 6 | Bell and e-mail notices |
| `Resources/views/` | 50 | Blade views (plus `fonts/`) |
| `Database/Migrations/` | 46 | Migrations |
| `Exports/`, `Policies/` | 3, 1 | Excel exports, policy |
| Root | `ModuleProvider.php`, `routes.php`, `README.md` | Registration and routes |

**Tables and migrations.** 46 migrations in `Chloe/Database/Migrations`. `grep "Schema::create"` finds **30 module-owned tables**: `assistant_logs`, `candidacy_appeal_approvals`, `candidacy_appeal_details`, `candidacy_appeal_documents`, `candidacy_appeal_events`, `candidacy_appeal_publications`, `candidacy_appeals`, `candidacy_demo_student_statuses`, `candidacy_departments`, `candidacy_dismissal_exports`, `candidacy_dismissal_import_rows`, `candidacy_dismissal_imports`, `candidacy_dismissals`, `candidacy_reminder_log`, `candidacy_staff_assignments`, `candidacy_staff_profiles`, `candidacy_student_supervisors`, `demo_student_profiles`, `locker_keys`, `student_genders`, `study_candidacies`, `study_candidacy_reminders`, `workstation_blocks`, `workstation_histories`, `workstation_locations`, `workstation_pending_selections`, `workstation_requests`, `workstation_seat_counter`, `workstation_termination_notices`, `workstations`. The whole database has 112 tables; 180 migrations exist on disk across all modules.

**Routes** (`php artisan route:list --json`): 386 in the whole app; **87 belong to Chloe** (49 `workstation.*`, 38 `candidacy.*`).

**Pages per role** (from the 87 routes and their `role:` middleware)

| Role | Pages |
|---|---|
| Student | Workstation: block picker, rooms in a block, seat list with in-page confirmation, room layout image, locker key request and cancel, return seat. Study Candidacy: My Candidacy status, New Appeal (4-step draft), edit and resubmit, appeal page with step bar, supporting documents, memo download |
| Approver (supervisor, chair, dean) | Study Candidacy Approval Portal (queue, decide), signature (now Core's "My Signatures", see section 8) |
| CGS (non-exec, senior exec, manager, senior director) | Workstation Management (landing with stat cards, block, room, seat detail, seat catalogue, suggestions, History and seat exports, locker key actions), Workstation Insights, Study Candidacy Operations, Study Candidacy Insights, Dismissal List (with export), Dismissal Upload; they also use the Approval Portal |
| Registry | Dismissal Upload only (the one Chloe route that lists the `registry` role) |
| All signed-in | Term of Use page and PDF (no sign-in needed), assistant endpoints |

**Scheduled jobs** (`php artisan schedule:list`, times are Asia/Kuala_Lumpur; `ModuleProvider.php:110-113`, `routes/console.php`)

| Time | Command |
|---|---|
| every minute | `workstation:release-expired-selections` |
| 07:00 | `candidacy:remind` (deprecated: prints a message and sends nothing, kept because the shared `routes/console.php` still schedules it; see `SendCandidacyReminders.php`) |
| 07:15 | `candidacy:generate-dismissals` |
| 07:30 | `workstation:refresh-daily`, `candidacy:close-expired-appeals`, `workstation:process-releases` |
| 08:00 | `candidacy:send-reminders`, `workstation:send-reminders`, `workstation:remind-locker-keys` |

By design, state changes happen at 07:30 and the notices for them are queued to leave at 08:00 (`Support/ScheduledDelivery.php`).

## 2. Automated testing (Results)

**Run:** only Chloe's test files, once, in the app container, on the separate in-memory SQLite test database (`phpunit.xml` forces sqlite, sync queue, array mail and cache). Command: `php artisan test` on `Assistant*`, `Candidacy*`, `CatalogueTest`, `GeminiKeySafetyTest`, `Insights*`, `LockerKeyTest`, `NewAggregateLookupsTest`, `SchedulerConfigurationTest`, `Workstation*` and `Unit/CandidacyPaginationWindowTest`, with `--log-junit`.

| Measure | Value |
|---|---|
| Test files | 55 (54 in `tests/Feature`, 1 in `tests/Unit`) |
| Test cases run | **972** (917 methods named `test_*`; the rest are data-provider cases) |
| Passed | **970** |
| Failed | **2** |
| Skipped | 0 |
| Assertions | 18,994 |
| Duration | 501.6 s |

The 2 failures are stale tests, not broken features:
1. `CandidacyAssistantRolesTest` (line 280): expects the label "Save signature" in a candidacy view. The signature page moved to Core's "My Signatures" page after the merge.
2. `WorkstationCgsScreensTest` (line 784): expects an inline CSS rule that moved to `public/css/chloe-workstation.css`.

**Test cases by area** (from the JUnit file; each file assigned to one area)

| Area | Files | Cases |
|---|---|---|
| Workstation selection and allocation (select, confirm, assign, release, same-seat race outcome) | 3 | 68 |
| Locker keys | 1 | 13 |
| Termination, awaiting key return, daily release schedule | 2 | 33 |
| Term of Use | 1 | 15 |
| Seat catalogue (seats, rooms, layout) | 1 | 15 |
| CGS screens, header pattern, demo data and login | 4 | 82 |
| Insights (workstation and candidacy) and statistics | 5 | 73 |
| Live updates | 1 | 36 |
| Appeal state machine, approval policy, portal, student pages | 6 | 124 |
| Dean adjustment, expiry arithmetic, Handbook alignment | 2 | 26 |
| Reminders and scheduler | 3 | 29 |
| Dismissal list, export, upload, status reader | 4 | 36 |
| Memo verification | 1 | 7 |
| Assistant (routing, privacy, fallback, extraction, answers, widget, key safety, question catalogue) | 15 | 303 |
| Roster, backfill, demo data, pagination, wording | 6 | 112 |
| **Total** | **55** | **972** |

**The concurrency test: there is no parallel-request test.** The only test of that kind is `test_two_simultaneous_assigns_to_the_same_seat_only_one_succeeds` (`tests/Feature/WorkstationManagementTest.php:321`). Despite the name it is **sequential**: it assigns the seat to student A, then expects the second assignment (student B) to throw `RuntimeException`. So: **0 parallel requests, 0 rounds**; it proves the outcome (one holder per seat), not true simultaneity. The protection itself is in the code: `lockForUpdate()` inside a transaction (`WorkstationAllocator.php:129, 191, 198, 216`) and unique indexes on `workstation_requests` (`workstation_id_if_confirmed`, `student_id_if_confirmed`, per `docs/shared-data-contract.md` section 1.4). I found no document recording a manual multi-process race run. If the paper needs a real concurrency result, one has to be run and recorded.

**Reminder de-duplication test:** `CandidacyReminderEngineTest::test_running_the_job_twice_sends_nothing_twice` (`tests/Feature/CandidacyReminderEngineTest.php:73`). It sets the expiry to 2027-05-31, sets today to 2027-02-28 and runs the job: the first run sends 1, the second sends 0, and `CandidacyReminderLog` has 1 row. A neighbouring test steps through 2027-02-28, 03-30, 03-31, 04-30 and 05-01 and expects the milestones 3, 2 and 1 to go out once each on their dates. Another test checks that a new expiry date starts a fresh cycle.

**Browser (Playwright) checks.** They are not in the repository. They are scripts in `/home/chloe/projects/_visual-test/` (outside the repo), e.g. `e2e_part2_fixed.js`, `e2e_part34.js`, `e2e_part43.js`, `e2e_part45_visual.js`, `e2e_part5.js`, `e2e_part5b.js`, `e2e_part6_exports.js`, `e2e_part7_assistant.js`, `appealpage.js`, `dismissal.js`, `full390.js`, `dark.js`, `verify_round7.js`, `verify_round8.js`, with screenshots in `screenshots-*` folders. Widths found by searching those scripts: **1280 px** (24 uses), **1440 px** (5 uses), **390 px** (1 use, `full390.js`). I did not re-run them. Note: Playwright's browser could not start on the host today (missing system library `libnspr4`), so the figures in section 7 were taken inside the app container.

## 3. Assistant evaluation (Results)

**Source of numbers.** `php artisan assistant:evaluate --report` (prints saved results only, no calls), for each of the three saved progress files in `storage/app/`. Run dates are the file times.

**Important limit: only the Study Candidacy question set has a saved result. No Workstation-domain evaluation result is stored.** The saved files start at question 260, which is the first Candidacy question; the Workstation set (656 questions in `evaluation_questions.php`) has no saved results. I did not run any new live evaluation.

| Run | File date | Live or stub | Model | Questions |
|---|---|---|---|---|
| Live Gemini, router only | 2026-11-02 16:14 | **Live** | `gemini-3.5-flash-lite` | 76 (student only), a partial run |
| Stub "route" (stub answers with the expected id) | 2026-11-02 10:20 | Stub, no provider | `stub-primary` | 353 (334 router-answered, 19 answered before the router) |
| Stub "fallback" (model unavailable, keyword fallback answers) | 2026-11-02 10:21 | Stub, no provider | none | 353 |

**Live Gemini run (Candidacy set, student role, 76 questions, 2026-11-02)**

| Measure | Value |
|---|---|
| Routing, exact id | 97.4% (74 of 76) |
| Routing, id or accepted alternative | 97.4% |
| Kind accuracy | 98.7% |
| Parameter accuracy | not applicable (no parameterised questions in this slice) |
| Role leaks | 0 |
| Correct refusals | not measurable here (0 out-of-scope or action questions in this slice) |
| Average router response time | 4,830 ms |
| Confidence | all 76 answers in the 0.9 to 1.0 band, 97.4% correct |
| Failures (2) | "my appeal was returned, what do i do now" (expected `cand_after_return_rejection`, got `cand_my_return_reason`); "suppose the dean turns down my appeal, then what" (expected `cand_after_return_rejection`, got `cand_dean_rejection`) |

**Stub runs (Candidacy set, all roles; these test the pipeline, not Gemini)**

| Run | Questions per role | Routing exact | Accepted | Kind | Params | Role leaks | Avg time |
|---|---|---|---|---|---|---|---|
| Stub route | student 107, approver 90, cgs 156 (353) | 100% | 100% | 100% | 100% of 6 | 0 | 26 ms |
| Stub fallback (keyword fallback only) | student 107, approver 90, cgs 156 (353) | 51.6% (student 57.9, approver 52.2, cgs 46.8) | 51.6% | 52.4% | 0% of 2 | 0 | 39 ms |

The stub-route 100% only shows the pipeline hands the expected id through. The fallback run shows what answers without the model: about half (51.6%). In the stub-route run, out-of-scope questions: kind correct 100% (17 of 17); action questions: 100% (10 of 10).

**Real usage log** (`assistant_logs` table, SELECT only): 1,647 logged questions in total (Workstation 1,418, Candidacy 229); `model_router` answers used `gemini-3.5-flash-lite`. These are usage records, not an accuracy measure.

**Catalogue size** (`php artisan assistant:catalogue <role> --domain=<domain>`, 2026-11-07)

| Domain | Student | Approver | CGS |
|---|---|---|---|
| Workstation | 22 (4 lookup, 15 guidance, 2 action, 1 out-of-scope) | 1 (out-of-scope only) | 65 (24 lookup, 30 guidance, 10 action, 1 out-of-scope) |
| Candidacy | 34 (7 lookup, 24 guidance, 2 action, 1 out-of-scope) | 33 (5 lookup, 26 guidance, 1 action, 1 out-of-scope) | 55 (14 lookup, 36 guidance, 4 action, 1 out-of-scope) |

Question files: `evaluation_questions.php` has 656 questions (Workstation set); `evaluation_questions_candidacy.php` has 360 (Candidacy set, includes cross-domain questions).

**Model calls per question** (`Assistant/Pipeline.php`, `config/assistant.php`)
- **Candidacy domain: 1 router call.** A candidacy guidance topic is shown as written ("no model rewrite", `Pipeline.php`, `answerGuidance`, the `! $isCandidacy` condition), and candidacy lookups are template answers. None of the `aggregate_lookups` belong to Candidacy.
- **Workstation domain: 1 router call, plus at most 1 compose call.** The second "compose" call still exists: (a) for a guidance answer, one call rewrites the topic text (accepted only if every number in it appears in the source); (b) for the 16 lookups listed in `config.assistant.aggregate_lookups` (seat availability, overall stats, room occupancy, programme breakdown and similar) one call composes the text from the result. If the budget is out or the model fails, the topic text or template is used instead.
- A suggested-question chip click, or an exact catalogue question, costs **0 calls**.
- Evidence from the log: the most calls on one question is 2; 1,569 of 1,647 rows have 0 calls (chips, rules, direct answers), 76 have 1, 2 have 2 or more. The evaluation command turns compose off (`Pipeline::disableCompose()`), so the evaluation numbers measure the router alone.

## 4. Performance (Results)

Measured 2026-11-07, after the test run had finished, on this machine. Method: a read-only copy of the app (`php -S`, file sessions and file cache, real database) inside the app container; log in once per role, then 5 timed `curl` GETs per page (`time_total`, includes PHP bootstrap and database queries, excludes browser rendering). All responses were HTTP 200. Warm cache after the first hit within the 5.

| Page (role) | Median of 5 | Min | Max |
|---|---|---|---|
| Student Workstation page `/workstation` (student) | **51 ms** | 47 | 52 |
| Appeal page `/candidacy/appeals/4` (student, approved appeal) | **68 ms** | 67 | 82 |
| CGS Workstation Management `/cgs/workstation` (CGS) | **69 ms** | 58 | 71 |
| CGS Operations `/cgs/candidacy` (CGS) | **98 ms** | 85 | 100 |
| Study Candidacy Insights `/cgs/candidacy/insights` (CGS) | **1,751 ms** | 1,600 | 1,992 |
| Dismissal List `/cgs/candidacy/dismissals` (CGS) | **443 ms** | 438 | 597 |

Data size at the time: 315 seats, 210 demo students plus 3 real students, 78 appeals. The Insights page is the slowest because it builds many aggregates in one request.

**Commands (dry run)**

| Command | Wall time | Students checked |
|---|---|---|
| `candidacy:send-reminders --dry-run` | 0.77 s | **213** checked; would send 5; not sent: completed 3, coursework 8, expired 24, inactive 3, paused (appeal in review) 36, softbound submitted 10 |
| `workstation:refresh-daily --dry-run` | 0.42 s | The command does not print how many it checked. It evaluates the seat holders (157 confirmed requests). Result: would start 1 termination, release 0 seats, 0 return reminders |

Note: wall time includes starting `docker exec` and the framework.

## 5. What is automated

Manual steps are taken from `docs/scope/technical.md` (CGS used disconnected platforms: UTrace, UCampus, Microsoft Forms, Excel and e-mail), `docs/knowledge/workstation/cgs/*`, `docs/knowledge/candidacy/00-system-flow.md` and `docs/candidacy-appeal-inventory.md`. The "before" is the manual routine those documents describe; where a document does not describe the old routine step by step, the step is my reading of what the feature replaces.

### Workstation Management

| Manual step | Now done by | Still manual |
|---|---|---|
| Keep a list of seats, rooms, status | Seat catalogue and live stat bar | |
| Student asks CGS for a seat; CGS checks eligibility, gender and a free seat | Student selects a seat in the page; the allocator checks eligibility, gender, one seat per student, and confirms at once (`lockForUpdate`) | |
| Send and chase the confirmation e-mail | Confirmation dialog; acceptance of the Term of Use recorded on Confirm | |
| Notice expired candidacy, tell the student to leave | `workstation:refresh-daily` (07:30) starts termination; notices at 08:00; `workstation:process-releases` releases on the day | CGS may "Release now" or "Withdraw Termination" |
| Remind about overdue locker keys; cancel keys never collected | `workstation:remind-locker-keys` (day 3 reminder, day 7 cancel) | Handing over and taking back the physical key; "Mark as Collected" and "Mark as Returned" at the counter |
| Reassign, set maintenance, correct mistakes | CGS Workstation Management actions, every one written to History | CGS decides |
| Prepare occupancy statistics and exports | Insights page and live Excel exports of results and History | |

### Study Candidacy Reminder

| Manual step | Now done by | Still manual |
|---|---|---|
| Find who is near expiry (Excel) | `candidacy:send-reminders` evaluates every student daily | |
| E-mail each student 3, 2 and 1 month before expiry | Same command at 08:00, de-duplicated per student, expiry date and milestone | |
| Decide who should not be reminded | Rules: research only, paused while an appeal is in review, stopped when softbound is submitted or the student is completed or inactive | Softbound status is still demo data until the source table is connected |

### Study Candidacy Appeal

| Manual step | Now done by | Still manual |
|---|---|---|
| Collect the appeal form and documents | 4-step New Appeal with uploads | |
| Route the paper to Supervisor / Programme Manager, Chair, CGS, Dean | Workflow routing, queues per approver, status page for the student, bell and e-mail notices | |
| Physical signatures | Signature image stamped on decisions and memo (Supervisor, Chair, Dean required) | Each approver uploads a signature once |
| Chase stalled appeals; close dead ones | Waiting-days indicators; `candidacy:close-expired-appeals` closes a returned appeal after expiry | |
| Calculate the new expiry date | Computed on Dean approval from the approved months (1 to remaining allowance of 12) | |
| Write and send the memo | Memo PDF generated and e-mailed on Dean approval, with verification codes (`candidacy:verify-memo`) | |
| Verify documents | CGS Verification stage with a "documents complete" decision | **CGS still checks the documents** |
| Decide | Approver decisions in the portal | **The decisions stay human** (Supervisor, Chair, CGS, Dean) |
| Expiry changes outside appeals (deferment, change of mode or programme) | Not built | Approved by the Registrar and updated by CGS by hand |

### Dismiss Exceed Study Candidacy

| Manual step | Now done by | Still manual |
|---|---|---|
| Work out who has exceeded their candidacy | `candidacy:generate-dismissals` (07:15) and the live Dismissal List (one rule used by page, export and upload preview) | |
| Prepare the list for the Registry | Excel export of all or the filtered list | |
| Registry processes the dismissals | Outside the system | **Registry decision and action stay manual** |
| Record the result | Dismissal Upload: preview then confirm; sets the student's status to dismissed; a dismissed student leaves the list and is visible to Hani's viva check | CGS or Registry uploads the file |
| Release the dismissed student's workstation | Automatic release pipeline with 14/7/3-day notices (`workstation:process-releases`) | |

## 6. Demo dataset (Methodology)

All from SELECT queries on 2026-11-07 (and read-only commands where stated).

| Item | Count |
|---|---|
| Seats (not deleted) | 315: available 152, occupied 157, awaiting key return 3, maintenance (`disabled`) 3 |
| Confirmed seat holders | 157 |
| Locker keys | collected 48, requested 20, return pending 3, returned 1, cancelled 2 |
| Demo students (`demo_student_profiles`) | 210 (51 candidacy-only); real student accounts 3 |
| Appeals by status | approved 17, rejected 8, returned 7, closed expired 4, draft 4, with supervisor 9, with chair 11, with CGS 8, with dean 10 (78 in total) |
| Approved memos verified OK | **17 of 17** (`candidacy:verify-memo` run for each approved appeal number, read-only; 0 mismatches) |
| Dismissal candidates now | 15 of 213 subjects (`CandidacyDismissalList::candidates()`); 0 real students and 2 demo students already marked dismissed |
| Reminder log rows | 82 (`candidacy_reminder_log`) |
| Departments | 9 (`candidacy_departments`) |
| Staff profiles | 60 (4 linked to a login) |
| Programmes in the catalogue | **31** (`count(config('candidacy.programmes'))`). Earlier notes said 37; the config holds 31 |

## 7. Figures

Saved as JPEG, **1440 px wide viewport, deviceScaleFactor 2** (images are 2880 px wide), in `/home/chloe/projects/_paper-figures/`. Method: read-only copy of the app inside the app container against the real database (proven first: the CGS page showed 315 / 152 / 157 / 3 / 3 seats). After sign-in **every non-GET request was blocked** by the script; the log shows 0 blocked requests, so nothing tried to write. Only pages were opened; no Select, Approve, Export or Confirm was clicked. The `.env` file and keys are not shown. Names are demo data.

| Figure | File | Page |
|---|---|---|
| a | `/home/chloe/projects/_paper-figures/a-student-workstation-room-seats.jpg` | Student room J2-01-05 with the seat list (2880 x 2432) |
| b | `/home/chloe/projects/_paper-figures/b-student-seat-confirmation-dialog.jpg` | "Select this seat?" dialog opened, then cancelled (dialog confirmed closed) (2880 x 1800) |
| c | `/home/chloe/projects/_paper-figures/c-cgs-workstation-management.jpg` | CGS Workstation Management with stat cards (2880 x 2230) |
| d | `/home/chloe/projects/_paper-figures/d-workstation-insights.jpg` | Workstation Insights (2880 x 10142) |
| e | `/home/chloe/projects/_paper-figures/e-new-appeal-step-1.jpg` | New Appeal step 1 (the existing draft; no new draft created) (2880 x 1988) |
| f | `/home/chloe/projects/_paper-figures/f-study-candidacy-approval-portal.jpg` | Study Candidacy Approval Portal (as Dean) (2880 x 2766) |
| g | `/home/chloe/projects/_paper-figures/g-approved-appeal-step-bar.jpg` | Approved appeal SCA-22001001-01 with the step bar (2880 x 3876) |
| h | `/home/chloe/projects/_paper-figures/h-candidacy-operations.jpg` | Operations (2880 x 6954) |
| i | `/home/chloe/projects/_paper-figures/i-candidacy-insights.jpg` | Study Candidacy Insights (2880 x 7704) |
| j | `/home/chloe/projects/_paper-figures/j-dismissal-list.jpg` | Dismissal List (2880 x 3552) |
| k | `/home/chloe/projects/_paper-figures/k-assistant-panel-suggested-chips-not-clicked.jpg` | **Partial:** the assistant panel open with its 6 suggested-question chips, **no chip clicked** (2880 x 1800) |

**Why (k) is partial:** clicking a chip posts to the assistant and writes one `assistant_logs` row to the real database, which would change data. I tried to avoid that with a throwaway scratch database on the shared MySQL server, but that request was refused, so I did not do it. To get the answered-chip picture, either allow one log row, or approve a scratch database.

Figures c, d, h, i, j are full-page and very tall; crop for the paper.

## 8. Statement check

| # | Statement | Verdict | Evidence |
|---|---|---|---|
| 1 | Term of Use acceptance is recorded when (after the confirmation e-mail was removed) | **True.** It is recorded when the student presses **Confirm** in the seat dialog | `WorkstationAllocator.php:151-153` sets `terms_accepted_at = now()` and `terms_edition = 'First Edition'` with the comment "Pressing Confirm in the dialog is the acceptance"; the dialog text is `seats.blade.php:108`. The old e-mail-link path (`WorkstationAllocator.php:229-232`) still records it on click for holds made before the change |
| 2 | CGS Mark as Collected sends an e-mail or bell only | **True, bell only** (no e-mail) | `LockerKey::collect()` notifies `LockerKeyNotice::COLLECTED` (`LockerKey.php:249`); `LockerKeyNotice.php:47`: `COLLECTED ? ['database'] : ['mail','database']`. Other key notices do send e-mail |
| 3 | The signature page approvers use after the merge | **Changed.** It is Core's single **"My Signatures"** page (`/signatures`, route `signatures.index`); the candidacy section appears there | `CandidacyAppealWorkflow::signatureFor()` supplies the section; the "My Signature" link was removed from the workflow's links; Core route at `Core/routes.php:68`. The `candidacy.signature.*` routes still exist for upload, delete and image |
| 4 | The Dean can change approved months between 1 and the remaining allowance with a reason | **True** | `CandidacyAppealService::resolveApprovedMonths` (`:411-427`): months must be between 1 and `remainingAppealMonths()`; a different number needs a reason of at least 5 characters (`MIN_ADJUST_REASON = 5`, `:50`); re-checked in `applyFinalApproval` (`:435`) |
| 5 | Total extension is capped at 12 months by a database check | **True** | `2026_11_01_000200_add_appeal_allowance_columns_to_study_candidacies_table.php:25`: `CHECK (extension_months_used BETWEEN 0 AND 12)` on MySQL (a trigger does the same on SQLite for tests). The code also reads the cap from `config('candidacy.max_extension_months')` = 12 |
| 6 | Reminders are sent at 3, 2 and 1 calendar months before expiry at 08:00 | **True** | `CandidacyReminderEngine.php:21, 34, 43` (milestones `[3,2,1]`, `subMonthsNoOverflow`, so 31 May minus 3 months is the last day of February); `config/candidacy.php:111`; scheduled `dailyAt('08:00')` in Asia/Kuala_Lumpur at `ModuleProvider.php:113`. A student who enters late skips the passed milestones ("late entry: skipped 3-month" in the dry run). The older 07:00 `candidacy:remind` sends nothing |

## 9. Confirmation: nothing changed

Row counts of **all 112 tables** were taken before and after (`SELECT count(*)` per table, saved in the scratchpad). **111 tables are identical.** The single difference: `activity_log` 860 to 866 (+6). Those 6 rows are "Signed in" audit entries (ids 1382 to 1387) caused by the 6 logins needed for the timing and the screenshots (student, CGS and Dean). Sessions and cache used files, not the database. Main tables before and after: `users` 25, `study_candidacies` 3, `candidacy_appeals` 78, `workstations` 315, `workstation_requests` 168, `assistant_logs` 1,647, `migrations` 183. The tests ran on in-memory SQLite. `git status` shows only the new docs files. Container-only temporary files (browser copy, test output) and my temporary server were removed.

## 10. What I could not find or could not do

- A true parallel-request concurrency test (none exists; section 2).
- Saved evaluation results for the Workstation domain, and live results for approver and CGS roles (only a 76-question student slice exists live).
- Stored Playwright checks inside the repository (they live in `_visual-test`, outside it).
- The number of students `workstation:refresh-daily` checks (not printed).
- Figure (k) with an answered chip (would write a log row; section 7).
