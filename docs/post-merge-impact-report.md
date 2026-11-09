# Post-merge impact report (read-only)

Written for: Chloe, owner of Workstation and Study Candidacy (`App\Modules\Chloe`), to decide what to fix and what to ask each teammate after `develop` was merged.

Nothing was changed: no code, config, schema or data. The only new file is this report. No `demo-seed`, `demo-clear`, `migrate` against MySQL, `migrate:fresh` or push. No live Gemini calls. No secrets printed (only variable names).

## 0. TL;DR (one page)

**What was compared.** My last commit `d3f5ad6` against `ca7d99e` (= `origin/develop` = my current `HEAD`; a fast-forward pull). That is 206 commits and 961 files (+110,030 / −8,642). Authors: Hani Sofia 73, Sharvin 45, Jason 36, Nureen 35, Hanis Erna 16, Haziq 1.

**Does my module still run? Yes.** 926 tests ran: 924 passed, 2 failed. Both failures are stale test assertions, not broken features. All 180 migrations ran from empty on a scratch SQLite database, with 0 dangling foreign keys. My routes, schedule entries, sidebar labels, Insights link and the `scheduler` service are all intact.

**Top 5 problems**
1. **The supervisor backfill is not enough for the 180 real accounts.** `candidacy:backfill-supervisors` picks a lecturer by a hash of the matric number. It never reads the shared `users.supervisor_id`. Real accounts would get an arbitrary supervisor instead of their own.
2. **My department list does not match Core's new `departments` table.** Names and faculty codes differ (`Computing` vs `Computer & Information Sciences`, `FSMC` vs `Faculty of Science, Management & Computing`). Shared `users.department` is a free-text name that my code never reads.
3. **Programme text is read differently by me and by Hani.** I only look for "coursework" and "part-time". Hani requires "phd", "msc" or "master", and treats any text containing "part" as part-time. Texts such as `MSc Full-Time` match none of my 37 catalogue names, so they get a department by a stable hash, not a real mapping.
4. **Two of my tests are stale.** The "My Signature" page moved into Core's "My Signatures" page, and the room-page CSS moved to `public/css/chloe-workstation.css`.
5. **Real accounts have no module rows.** A new student needs `study_candidacies`, `student_genders` and a supervisor row. None of them exist for accounts that are not in the seed. The shared `users` table has no gender, expiry date or start date.

**Hani's viva check sees my dismissals.** She reads `study_candidacies.status = 'dismissed'`. My Dismissal Upload sets exactly that, plus `dismissed_at`. Gaps are in section 3.

**What I must do first**
1. Ask the Core owner for the 180 accounts' programme, department and supervisor data (questions in section 6).
2. Build one adapter that creates `study_candidacies`, `student_genders` and `candidacy_student_supervisors` from `users` (including `supervisor_id`).
3. Fix the 2 stale tests and the assistant text that still tells users to click "Save signature".
4. Keep every column in section 3.4.

## 1. What changed since my branch

**Compared:** `d3f5ad6` (my last commit) to `ca7d99e` (`origin/develop`, my `HEAD`). The merge base is `ca7d99e` itself because `HEAD` was fast-forwarded. `origin/feature/chloe-remaining-modules` is at `89b7385`, and my local branch is 2 commits ahead of it.

### 1.1 Files by area

| Area | Changed |
|---|---|
| Migrations | Added: 2 Core (`departments`, faculty on departments), 33 Hani, 25 Haziq, 8 Jason, 15 Norhanis, 18 Nureen. Deleted: 4 Norhanis (`candidacies`, `rpd_*`). None of mine changed. 180 on disk, 183 recorded in the live DB |
| Models and services | Core: 57 files modified, 40 added. Chloe: 18 modified, 2 added, 2 deleted (by Sharvin) |
| Routes | `routes/console.php`: 5 lines removed (the `rpd:remind` entry). `app/Modules/Core/routes.php` modified |
| Config | New `config/calendar.php`, `config/tracking.php`. `phpunit.xml` now forces sqlite, sync queue, array mail and cache |
| Shared views, layout, sidebar | `layouts/app`, `sidebar*.blade.php` (4 files), `stylesheets`, dashboards, 14 CSS files |
| Seeders | `DatabaseSeeder.php` (+194 lines, still creates my `StudyCandidacy`/`Workstation` rows), `DemoDataSeeder.php`, new `database/sql/cgs_officers.sql` |
| Docker | `docker-compose.yml`: `scheduler` service gained a `build:` block (Sharvin). Still present |
| Tests | Many new Core and module tests. Two of mine changed: `CandidacyPortalTest`, `CandidacyDepartmentRosterTest` |

### 1.2 Plain-English TL;DR per person or area

- **Sharvin (Core).**
  - Added the `departments` table and a faculty column.
  - Added one "My Signatures" page driven by a `SuppliesSignature` contract.
  - Added `DecidesOneAtATime` and `SuppliesCandidacyStatus` contracts.
  - Added the in-app document viewer.
  - Rebuilt the sidebar (collapsible CGS tree; student "Track My Applications" holds "My Candidacy").
  - Redesigned the dashboards.
  - Added a `scheduler` container.
  - **Edited files inside my folder:** `CandidacyAppealWorkflow` now implements `DecidesOneAtATime` and `SuppliesSignature`, and its "My Signature" link was removed. He also added `CandidacyStatusProvider` and bound it in my `ModuleProvider`, deleted `candidacy/wca/portal/signature.blade.php` and `components/required-mark.blade.php`, and moved workstation CSS into `public/css/chloe-workstation.css`.
- **Hani Sofia.**
  - Viva voce, softbound, examiner pool and matching, SLA reminders, Process Analytics, re-viva.
  - Her viva eligibility now **reads my `study_candidacies` read-only** (`CandidacyPeriodSource`).
- **Hanis Erna (Norhanis).**
  - Travel, Claims and Publication extensions.
  - **Removed RPD Candidacy:** dropped the `candidacies` table and the `rpd_*` tables, and retired the `rpd_appeal` and `rpd_dismissal` keys (`2026_10_30_000100_remove_rpd_candidacy`).
  - Added stage reminders.
- **Jason.** Appointment letters now read released examiner panels from Hani's nomination chain. Hardbound appeal reworked as an extension request.
- **Nureen.** Attendance ingestion and backtests, GA Extension and Certification drafts, supervision changes, tracking redesign.
- **Haziq.** GRA/GA applications, Stage Gates, Allowance, TA hours. One of his tables references my dismissal as a planned termination trigger (comment only; no query).

### 1.3 Changes that touch what I read or write

| Item | Touched? | Detail |
|---|---|---|
| `users` | No new columns | Still `programme` (varchar 100), `department`, `faculty`, `supervisor_id`, `matric_no` |
| `study_candidacies` | **Read by Hani** | Read-only, section 3 |
| `departments` | **New, Core** | 12 rows, faculty codes `FOE`/`FSMC`/`CFS`. Not the same as my `candidacy_departments` (section 4b) |
| `programmes` | No table exists | Programme is free text in `users.programme` |
| `supervisors` | No table | Shared link is `users.supervisor_id` |
| Notifications | Unchanged | Standard Laravel `notifications` table |
| Approvals | `WorkflowEngine` and `Role` modified | I still use `Role::SUPERVISOR`, `CHAIR`, `DEAN_PGR` and CGS roles |
| Sidebar | **Changed** | Section 2.4 |
| `routes/console.php` | Only `rpd:remind` removed | My 5 entries intact |
| `docker-compose.yml` | `scheduler` gained `build:` | Still present, running |
| `config/assistant.php` | **Not changed** (the file was not in the diff) | |
| `config/candidacy.php` | **Not changed** | |
| Queue and cache | `phpunit.xml` forces sync, array | Section 5 |

## 2. Does my module still work

### 2.1 Migrations (scratch database only)

I ran `php artisan migrate --force` in the app container against an empty SQLite file (`/tmp/scratch_impact.sqlite`, deleted afterwards). I first proved the run targeted the scratch file by printing the active connection and path, before writing anything.

- **180 migrations ran in order with no error.** 112 tables were created.
- **0 dangling foreign keys** (checked every table's foreign keys against existing tables).
- No duplicate table or column names between my migrations and the others.
- No changed type or enum in `users`, `roles` or programme fields (`users` migration is unchanged; `Role` values are strings).
- The live MySQL database has no unapplied migration and still records 4 migrations for files that were deleted (`2026_03_05_000100..400`). Harmless: the tables are gone and nothing references them.
- `candidacies` table: gone from both the scratch and live databases. No `rpd*` tables remain.

### 2.2 Tests (in the app container; `phpunit.xml` forces in-memory SQLite, so MySQL was never touched)

Command scope: my Candidacy*, Workstation*, Assistant*, Scheduler*, Locker*, Insights*, GeminiKeySafety, Catalogue, NewAggregate and WorkflowReturn tests. Result: **924 passed, 2 failed** (18,440 assertions, 344 s).

| Failing test | First error | Cause | Fix |
|---|---|---|---|
| `CandidacyAssistantRolesTest > every page and button…` (line 280) | `To contain: Save signature` | The assistant knowledge names a "Save signature" button, but `candidacy/wca/portal/signature.blade.php` was deleted when Sharvin consolidated signatures into Core's "My Signatures" page (Core's page shows "Remove" and an upload form) | Mine: update the assistant answers to the Core page's wording, then remove those labels from the test list |
| `WorkstationCgsScreensTest > the room page constrain…` (line 784) | `To contain: .cgs-seat-text { flex: 1 1 160px; … }` | Inline styles moved to `public/css/chloe-workstation.css`; the rule there is now `.cgs-seat-text { display: flex; … }` | Mine: point the test at the stylesheet and the new rule |

The scheduler tests (`SchedulerConfigurationTest`) and the assistant tests passed, with the fake provider (no live Gemini).

### 2.3 Routes, permissions, schedule, Docker

- **Routes:** 45 `candidacy.*` and 49 `workstation.*` routes are registered. All 10 route names listed in section 3.4 were checked and exist.
- **Insights link:** present. Provided by my workflow's `ProvidesLinks` as "Study Candidacy Insights" (`candidacy.cgs.insights`), and `CandidacyInsightsPageTest` passes.
- **Scheduler:** `php artisan schedule:list` shows all 9 of my scheduled entries (`candidacy:remind` 07:00, `candidacy:generate-dismissals` 07:15, `candidacy:send-reminders` 08:00, `candidacy:close-expired-appeals`, `workstation:*`). `routes/console.php` lines for my commands are intact.
- **`scheduler` service** is present in `docker-compose.yml` and running.
- **Note:** `candidacy:remind` (07:00, console.php) and `candidacy:send-reminders` (08:00, `ModuleProvider`) are two schedule entries for reminder sending. This existed before the merge. Confirm they do not both email the same student.

### 2.4 Labels and sidebar

- **Student sidebar:** one tree, "Track My Applications" > Overview, **"My Candidacy"**. The label is not renamed. There is no second top-level entry (Sharvin's comment in `sidebar-student-nav.blade.php` explains why).
- **CGS sidebar:** **"Candidacy Management"** (tree: Operations, Dismissals) is not renamed. "Workstation Management" is a separate item.
- **"My Signature":** my link was removed on purpose. Core now owns the single "My Signatures" page, and my module is one section on it.
- `sync.sh --check` (run earlier this session) still reports: `composer install` needed, `.env` keys missing, stale compiled caches, and the queue worker needing a restart. Run `./sync.sh` yourself when ready; I did not.

## 3. Who reads my tables

### 3.1 Uses outside `App\Modules\Chloe`

| File:line | What | Columns | Read / write |
|---|---|---|---|
| `Hani/Support/Eligibility/CandidacyPeriodSource.php:25-27` | viva eligibility, "within candidacy period" | `student_id`, `programme_start_date`, `candidacy_expiry_date`, `status` | **Read only** (guarded by `Schema::hasTable/hasColumns`) |
| `Core/Contracts/SuppliesCandidacyStatus.php`, bound in `Chloe/ModuleProvider.php` | Core student dashboard card | via my `CandidacyStatusProvider` (reads `StudyCandidacy`) | Read only, through the contract |
| `database/seeders/DatabaseSeeder.php:5-11, 90, 330, 661-679` | seeds `Workstation*`, `LockerKey`, `StudentGender`, `StudyCandidacy` | many | **Write** (seeding only) |
| `tests/Support/BuildsCandidacyWorld.php`, `MakesEligibleWorkstationStudents.php`, `Core/StudentDashboardTest.php`, `Hani/VivaEligibilityVerificationTest.php:52` | test fixtures | various | Write (test DB only) |
| `Haziq/…create_stage_gate_termination_triggers_table.php:13` | comment only: mentions `candidacy_dismissal` as a planned trigger | none | No query |
| `config/assistant.php` | assistant wording | none | No query |

No other module touches `candidacy_dismissal*`, `candidacy_appeals`, `candidacy_departments`, `candidacy_staff_*`, `candidacy_student_supervisors`, `workstations*`, `locker_keys`, `demo_student_profiles` or `student_genders`.

### 3.2 Hani's viva check, exactly

- **Expiry date:** `study_candidacies.candidacy_expiry_date`, compared with today (`startOfDay`). Also shows `programme_start_date`.
- **Dismissed:** `study_candidacies.status === 'dismissed'` (constant `DISMISSED`).
- **Does my Dismissal Upload reach her?** Yes. `CandidacyDismissalUpload.php:190` sets `status = 'dismissed'`, `dismissed_at` and `dismissed_by_id` on the candidacy, and also records a row in `candidacy_dismissal_import_rows`. Her check sees the `status` value.
- **Gaps**
  1. She does not read `dismissed_at`. My own `StudentStatusReader` treats `dismissed_at IS NOT NULL` as dismissed too. Today both are set together, so there is no gap, but any future path that sets only one would split the two modules.
  2. Her code returns "self-declared" **before** the dismissed check when `candidacy_expiry_date` is empty. A dismissed row with no expiry date would not show as dismissed.
  3. Demo-only students (`demo_student_profiles`, no `users` row) are invisible to her. Intended; real accounts are fine.
  4. `softbound`, `completed` and `inactive` statuses are treated as ordinary (date check only). Is that what Hani intends for a student who has finished?

### 3.3 Other places the dismissed state is stored

`study_candidacies.status` and `dismissed_at`/`dismissed_by_id`; `candidacy_dismissals` (the list); `candidacy_dismissal_import_rows` (which upload dismissed whom); `demo_student_profiles.status` and `candidacy_demo_student_statuses` (demo students only).

### 3.4 Columns and values that must not be renamed or removed

- `study_candidacies`: `student_id`, `programme_start_date`, `candidacy_expiry_date`, `status`, and the value `'dismissed'`. (Plus `dismissed_at`, `dismissed_by_id`, used by me.)
- The `SuppliesCandidacyStatus` contract and `CandidacyStatusProvider` (Core's dashboard card depends on them).
- Route names used by the sidebar: `candidacy.status`, `candidacy.cgs.operations`, `candidacy.cgs.dismissals`, `candidacy.portal.queue`, `candidacy.cgs.insights`, `candidacy.cgs.dismissal-upload`, `candidacy.signature.*`, `workstation.select`, `workstation.cgs.operations`, `workstation.cgs.room`.
- `module_type` for my appeal workflow and the stage keys (permanent per CLAUDE.md rule 3).

## 4. Alignment with the shared database and other modules

**Limit:** the 180 real student accounts are **not visible** in the merged code, seeders or migrations. The live DB has only 3 students (`student@`, `student2@`, `chloe_26005007@`). Counts per case for the 180 therefore cannot be given. Everything below is based on the schema, the seeders and these 3 accounts.

### 4a. Programmes

- **Shared representation:** `users.programme`, varchar(100), **free text**, no catalogue id. Other modules copy or read it: Hani (`VivaChecklist`, letters), Jason (`hardbound_submission_details.programme` varchar 150, `AppointmentSheet`), Haziq (own `gra_ga_student_profiles.programme` varchar 150).
- **Values seen:** `MSc Full-Time`, `PhD Part-Time`, `PhD Full-Time` (Hani's seeder), `Master of Science in Information Systems (Full-Time)` (live).
- **My rules:** `ProgrammeType::of()` returns coursework only if the text contains "coursework", otherwise research. Part-time is read by the word "part-time". Department comes from the 37-entry catalogue, then from regex patterns, then from a hash of the matric number.
- **Problems found**

| Case | Effect |
|---|---|
| Text not in my catalogue (e.g. `MSc Full-Time`, `PhD Part-Time`) | No catalogue match, no keyword match, so the department is chosen by a stable hash. Live evidence: `student2@utp.edu.my` (`PhD Part-Time`, shared department `Computing`) is placed in `GEO` |
| Text with a suffix such as `(Full-Time)` | Handled: the resolver strips a trailing `(Full-Time)`, `(Part-Time)` or `(Coursework)` before matching (corrected in `shared-db-facts.md`) |
| A coursework programme written without the word "coursework" | Treated as research, so it gets research reminders and can hold a seat |
| Part-time written as "PT", "Part Time" or "Part-time" without the hyphen | The hyphen matters in my rule. Hani only needs "part" |
| Hani needs `phd`, `msc` or `master` in the text | A text with none of these gets no viva level in her module; the same text is still "research" for me |

### 4b. Departments and faculties

| Concept | Mine (`candidacy_departments`, 9 rows) | Core (`departments`, 12 rows) |
|---|---|---|
| Faculty | full name, 2 values | code: `FOE`, `FSMC`, `CFS` |
| Computing | `CIS` Computer & Information Sciences | Computing (FSMC) |
| Applied Science | `APS` Fundamental & Applied Sciences | Applied Sciences |
| Geoscience | `GEO` Geoscience | Geosciences |
| Management | `MHU` Management & Humanities | Management |
| Electrical | `EEE` Electrical & Electronic Engineering | Electrical & Electronics Engineering |
| Chemical, Civil, Mechanical, Petroleum | match by name (small wording differences) | |
| Integrated Engineering, 2 Foundation departments | **none** | present |

`users.department` holds the **name as text** (e.g. `Computing`), not an id. My module does not read it. `departments` has no foreign key to my table, so the two are two separate lists of the same concept.

### 4c. Supervisors, chairs, Dean

- **Shared:** `users.supervisor_id` (one supervisor per student, FK to `users`). Staff are `users` with `role` = `supervisor`, `chair`, `dean_pgr`, `faculty`, `academic_exec`, CGS roles. Chair is identified by `role='chair'` plus `users.department`. Haziq adds `co_supervisor_id` on his application details. Jason and Hani take supervisor names from forms and sheets.
- **Mine:** `candidacy_student_supervisors` (213 rows: 205 supervisor, 8 programme manager) pointing to `candidacy_staff_profiles` (60 profiles, **only 4 linked to a login**, 56 are named people with no `users` row), and `candidacy_staff_assignments` per department.
- **After assigning the real accounts**
  - A student with no `study_candidacies` row is not covered at all by the backfill.
  - Students with a candidacy but no supervisor row get a lecturer from their department chosen by `crc32(matric) % N`. This ignores `users.supervisor_id`, so the real supervisor is lost.
  - An appeal routed to a profile with no login (56 of 60) cannot be actioned by a person. This is already true today for the demo staff.
  - Shared staff accounts that my profiles do not know: every supervisor, chair and dean except the 4 linked profiles. The live DB has 1 supervisor, 1 chair, 1 dean.
- **What to add (proposal, not done):** one adapter or command that (1) creates a `candidacy_staff_profiles` row for every shared supervisor/chair/dean `users` row (setting `user_id`), (2) creates `candidacy_student_supervisors` from `users.supervisor_id`, and (3) assigns chair and dean by `users.department`. **`candidacy:backfill-supervisors` is not enough on its own**; it is a fallback for students whose supervisor is genuinely unknown.

### 4d. Student fields

| Needed | In shared `users`? | Where it must come from |
|---|---|---|
| matric number, name, email, programme | Yes | `users` |
| department | As a text name only | map name to `candidacy_departments.id` (4b) |
| gender | **No** | `student_genders` (rooms are gender-specific) |
| programme start date | **No** | `study_candidacies.programme_start_date` |
| candidacy expiry date | **No** | `study_candidacies.candidacy_expiry_date` and `original_expiry_date` |
| status, extension months used | **No** | `study_candidacies` |

Missing for real accounts: gender, start date, expiry date, department id, supervisor row. Nobody else's module stores these.

### 4e. Softbound status (proposal only; `config/candidacy.php` not changed)

The real Softbound lives in **Hani's module**: `applications.module_type = 'viva_softbound'` (workflow `VivaSoftboundWorkflow`, one stage `softbound_check` decided as `verified`), with details in `viva_softbound_details` (linked by `application_id` and `viva_application_id`). `applications` has `student_id` (a user id), `status` (`draft`, `pending`, `approved`, `rejected`, `returned`) and `submitted_at`.

Proposed values for `status_source_table`:

```php
'table' => 'applications',            // filter module_type = 'viva_softbound'
'student_id_column' => 'student_id',  // a user id, NOT the matric number: join users.matric_no
'submitted_at_column' => 'submitted_at',
'status_column' => 'status',
'submitted_values' => ['pending', 'approved'],
'completed_values' => ['approved'],   // approved = the Academic Executive verified it
'inactive_values' => [],              // no deferred/inactive concept exists in the merged code
```

Caveat: my slots expect a matric column and no module filter. They would need a small adapter (a join to `users` and a `module_type` condition). Confirm with Hani that "approved" means completed.

### 4f. Overlaps and conflicts

| Topic | Result |
|---|---|
| Signatures | Resolved: one "My Signatures" page. Jason's code still has its own "My Signature" comment and route comment; cosmetic |
| Sidebar | One sidebar. Mine are items inside Core's templates (Core edits). No duplicate for me |
| Schedulers | One `scheduler` container. No second scheduler |
| Notifications | All use Laravel's `notifications`. Each module has its own reminder log (`application_reminders`, `norhanis_stage_reminders`, `hani_sla_reminder_logs`, my `candidacy_reminder_log`). Different logs, same table for bell messages |
| Approvals | `WorkflowEngine` shared. Mine uses it |
| Student directory | None shared. Core has `UserAdminController` and `DepartmentAdminController`; I have my own staff directory (`CandidacyStaffDirectory`) |
| E-mail layout | Not compared in depth; check with Sharvin if a common layout was added |

## 5. Risks and what I must do

| # | Risk or conflict | Severity | Evidence | Owner | Smallest fix |
|---|---|---|---|---|---|
| 1 | Backfill ignores `users.supervisor_id` | Breaks a feature (wrong supervisor routing) | `BackfillCandidacySupervisors.php:93-99` | Me | Adapter reading `users.supervisor_id` (4c) |
| 2 | Real accounts have no `study_candidacies`, `student_genders` or supervisor rows | Blocks (no seat, no appeal) | `shared-data-contract.md` §1.3; live DB has 3 candidacies | Me, with Core owner | Assignment command after data arrives |
| 3 | Department mismatch (names, faculty codes, 3 unmatched) | Breaks a feature (appeal routing, statistics) | 4b | Me, Core | Name-to-code map; ask Core whether to share one table |
| 4 | Programme free text not in the catalogue is placed by hash | Breaks a feature (wrong department) | `student2@` placed in GEO | Me | Ask Core for exact programme texts; widen patterns |
| 5 | Two stale tests | Cosmetic (suite red) | Section 2.2 | Me | Update assistant text and the two assertions |
| 6 | Assistant tells users to "Save signature" in a page that no longer has it | Breaks a feature (misleading answer) | `CandidacyAssistantRolesTest.php:276-280` | Me | Rewrite the answer for "My Signatures" |
| 7 | Sharvin edited files inside my folder | Cosmetic / process (ownership) | commits `01f3ca9`, `be3ab33`, `79e4185` | Core | Agree to review those edits; keep them |
| 8 | Hani reads `status` only, not `dismissed_at`; expiry-null skips the dismissed check | Cosmetic today | 3.2 | Hani, me | Agree that `status='dismissed'` is the contract |
| 9 | Staff profiles without a login (56 of 60) cannot decide an appeal | Breaks a feature | live DB | Me | Link profiles to the real staff accounts (4c) |
| 10 | Assistant needs a Gemini key in the shared environment | Blocks the assistant only (falls back to fake if provider is `fake`) | `config/assistant.php` | Core (env) | Set variables below |
| 11 | Shared queue/cache: assistant rate limits and route cache use the cache | Breaks a feature if the cache is `array` or per-machine | `config/assistant.php` | Core | One Redis for the shared environment |
| 12 | Scheduler container | OK, present | `docker-compose.yml` | Core | Keep it in the shared deployment |
| 13 | Shared sidebar labels | OK; "My Candidacy", "Candidacy Management" kept | 2.4 | Core | Ask before any rename |
| 14 | Two candidacy reminder commands (07:00 and 08:00) | Possible double e-mail | `routes/console.php:15`, `ModuleProvider.php:113` | Me | Confirm one is dry or a different job |
| 15 | 7-account shortfall: spec needs 187, you have 180 | Cosmetic (screens show fewer demo rows); seat counts unaffected | `HANDOVER-README.md` | Me | Unchanged: still 7 short. The real account count is not visible, so confirm it is still 180 |

**Environment variable names for the assistant (names only):** `ASSISTANT_PROVIDER`, `GEMINI_API_KEY`, `ASSISTANT_GEMINI_MODEL`, `ASSISTANT_GEMINI_FALLBACK_MODEL`, `ASSISTANT_PRIMARY_REQUESTS_PER_MINUTE`, `ASSISTANT_PRIMARY_REQUESTS_PER_DAY`, `ASSISTANT_PRIMARY_TOKENS_PER_MINUTE`, `ASSISTANT_FALLBACK_REQUESTS_PER_MINUTE`, `ASSISTANT_FALLBACK_REQUESTS_PER_DAY`, `ASSISTANT_FALLBACK_TOKENS_PER_MINUTE`, `ASSISTANT_USER_REQUESTS_PER_MINUTE`, `ASSISTANT_DAILY_RESERVE`, `ASSISTANT_DAY_TIMEZONE`, `ASSISTANT_TOTAL_DEADLINE_SECONDS`, `ASSISTANT_ROUTE_CACHE_SECONDS`, `ASSISTANT_ROUTER_MIN_CONFIDENCE`, `ASSISTANT_MAX_KNOWLEDGE_CHARS`, `ASSISTANT_ALMOST_FULL_PERCENT`. Also `WORKSTATION_LIVE_REQUESTS_PER_MINUTE`, `CANDIDACY_DEFAULT_DEAN_PROFILE_ID`, `CANDIDACY_REMINDERS_DEMO_STUDENTS`, and the shared `QUEUE_CONNECTION`, `CACHE_STORE`, `REDIS_HOST`, `MAIL_*`.

## 6. Final report details

### 6.1 Commits and tests

- Compared `d3f5ad6` to `ca7d99e` (`origin/develop`). `HEAD` = `ca7d99e`. `origin/feature/chloe-remaining-modules` = `89b7385`.
- Tests: 926 run, 924 passed, 2 failed (section 2.2). Migration check: 180 migrations on scratch SQLite, 0 errors.
- Not run: the rest of the suite (Hani, Jason, Nureen, Norhanis, Haziq, Core tests). They are not mine.

### 6.2 Questions to ask each teammate

- **Sharvin / Core owner**
  1. Can I get the 180 accounts with programme text, department name and `supervisor_id`? Are gender, start date and expiry date available anywhere?
  2. Should `candidacy_departments` and `departments` become one table, or do I keep a name-to-code map?
  3. Which edits to my folder should I treat as final (signature page, status provider, CSS)?
  4. Will the shared environment have one Redis, one Gemini key (and which `ASSISTANT_PROVIDER`), and the `scheduler` container?
- **Hani**
  1. Is `status = 'dismissed'` enough for you, or should I also keep `dismissed_at` in step?
  2. What should her check show for `softbound`, `completed` and `inactive` students?
  3. Is `applications.status = 'approved'` for `viva_softbound` the right "completed" signal?
  4. Which programme texts will the real accounts use (she needs "phd", "msc" or "master")?
- **Hanis Erna (Norhanis)**: Is the removal of `candidacies` final? Any module still expecting the RPD candidacy list?
- **Jason**: Does Hardbound need candidacy dates or dismissal status from me?
- **Nureen**: Does attendance or supervision need my supervisor assignments, or the shared `users.supervisor_id` only?
- **Haziq**: Do stage gates need to read `study_candidacies` (his termination trigger comment mentions `candidacy_dismissal`)? If so, through a contract.

### 6.3 Confirmation: nothing changed

Row counts of the main tables, measured at the start of this task and again after the work (live MySQL, `uresearch`):

| Table | Before | After |
|---|---|---|
| `users` | 25 | 25 |
| `study_candidacies` | 3 | 3 |
| `candidacy_appeals` | 78 | 78 |
| `workstations` | 315 | 315 |
| `migrations` | 183 | 183 |

Earlier in this session (before this task, in the first environment restart) `users` read 14. Between that reading and the start of this task something else added users; nothing I ran here did. `git status` is clean apart from this new file. The scratch SQLite file was deleted. The tests ran on in-memory SQLite only.
