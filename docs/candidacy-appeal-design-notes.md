# Study Candidacy Appeal: design notes (appeal rebuild, step 1)

Written in Phase 0 (2026-11-01) from the code as it stands. The module is `App\Modules\Chloe`.
Nothing in this document needs a shared-file edit; where something would, it says so in the gap list.

## 1. How appeals work today

### Tables
| Table | Owner | Holds |
|---|---|---|
| `study_candidacies` | ours | one row per real student (`student_id` unique): `programme_start_date`, `candidacy_expiry_date`, `status` (active / softbound / dismissed / inactive / completed), `cumulative_extension_months`, `last_rejection_at`, `dismissed_*` |
| `candidacy_appeal_details` | ours | one row per appeal, **keyed to a shared `applications` row** (`application_id`): `supervisor_id` (a `users` id), free-text `department`, `reason`, section A (`phase`, `writing_completion_percent`, `rcs_status`, `rcs_date`, `rcs_category`, `rcs_expected_date`), section B (`extension_via_gsc`, `extension_via_vc`), `requested_extension_months`, `new_expiry_date`, `disclaimer_acknowledged_at` |
| `candidacy_appeal_publications` | ours | section C: one free-text `description` per row |
| `candidacy_dismissals`, `study_candidacy_reminders` | ours | the dismissal list and the reminder log (not touched by this task) |
| `applications` | **shared engine** | `module_type = 'candidacy_appeal'`, `status` (draft / pending / returned / approved / rejected), `current_stage` |
| `approval_history` | **shared engine** | one row per decision: `stage_key`, `decision`, `remarks` |
| `application_documents` | **shared engine** | the generated memo (via `DocumentStore::storeGenerated`) |

Live data on 2026-11-01: 1 appeal (approved, 6 months, 4 history rows, 1 memo document), 1 publication, 3 candidacies
(real student ids 10, 11, 24), 1 dismissal.

### How the process runs
* `CandidacyAppealWorkflow` (registered in `ModuleProvider::boot()`, `module_type = 'candidacy_appeal'`) declares four stages:
  `supervisor` (role `supervisor`), `chair` (`chair`), `cgs_verify` (`non_exec_cgs`), `dean` (`dean_pgr`).
* `CandidacyAppealController` submits through `WorkflowEngine::submit()`, approvers share one queue and `decide()`
  (approve / reject / return) using `ApprovesApplications::queueFor()`.
* The engine authorises by **role only** (`$actor->role === $stage->role`), so every supervisor sees and can decide every appeal at
  the supervisor stage. It cannot express "only the named person", "no reject before the Dean", or "after a Dean return go to CGS first".
* On the Dean's approval the controller adds the months to `candidacy_expiry_date`, builds the memo with Dompdf
  (`memo_pdf.blade.php`, Tahoma registered from `Resources/fonts`) and stores it with `DocumentStore::storeGenerated`.
* Form sections A to D: stored in `candidacy_appeal_details` and `candidacy_appeal_publications` (see above).

### Who reads appeals (everything that must keep working)
| Reader | Reads | After this task |
|---|---|---|
| `StudyCandidacy::hasOpenAppeal / canAppeal / remainingAppealMonths` | engine statuses, `cumulative_extension_months`, `last_rejection_at` | switched to the new tables, still honouring legacy rows (Phase 1) |
| `SendCandidacyReminders` | `hasOpenAppeal()` | unchanged code, new helper |
| `DismissalGenerator` | `hasOpenAppeal()`, `last_rejection_at`, `remainingAppealMonths()` | unchanged code, new helper (Phase 1 / 2) |
| `WorkstationTerminationTriggers` | `candidacy.status`, `candidacy_expiry_date`, `hasOpenAppeal()` | unchanged |
| Workstation student page "Loan valid until" | `study_candidacies.candidacy_expiry_date` | unchanged (the column and its meaning stay) |
| `CandidacyOperationsController` (CGS widgets) | `Application::where('module_type','candidacy_appeal')` counts | switched to the new tables (Phase 4) |
| `CandidacyController::status` (My Study Candidacy) | the same `applications` rows | rebuilt in Phase 4 |
| Norhanis' `RpdAppealWorkflow` / `Norhanis\Models\Candidacy::canAppeal()` | their own RPD tables | **not ours, not touched** (a different "candidacy" model) |

## 2. The shared side (read-only for us)

* **Track My Applications** (student sidebar) is a tree with two sub-items: **Overview** = `applications.index` (Core,
  `ApplicationTrackingController`, lists `user->applications()`), and **My Candidacy** = `candidacy.status`, a route of **our** module.
  So "the Candidacy sub-section" is ours: priority (1) of the brief applies and our page renders the summary list.
  The Core Overview keeps showing the one legacy Core application (we cannot hide it); it never shows new appeals.
* **Approvers** reach queues through `ModuleRegistry::queuesForRole($role)`: every registered `WorkflowModule` whose `stages()` contain
  the role gets a sidebar link to the module's `queueRoute()`. The student's "My Application" tree is driven by `createRoute()`.
  `ProvidesLinks::links(User)` lets a module add extra sidebar links / dashboard shortcuts for any role, including CGS users whose
  sidebar (`sidebar-cgs-nav`) is otherwise hard-coded. Both are interfaces our workflow class already sits behind: no shared edit.
* The notification **bell** is the `notifications` table: a notification with the `database` channel and a `toArray()` shaped like
  `ApplicationDecided` appears in the Core feed.
* `WorkflowEngine` writes `applications.status` / `current_stage` and nothing else may (project hard rule 1).

## 3. Architecture decision

1. **New appeals live in OUR tables and run on OUR state machine** (`CandidacyAppealService`). They are never written into
   `applications`, `approval_history` or `application_documents`, so they can never show up in a shared queue where someone could
   decide them outside our rules. The shared engine cannot be driven for the new rules (named people, no reject before the Dean,
   Dean return -> CGS re-check, append-only history), so it is not used for new appeals at all.
2. **Existing appeals are backfilled** (Phase 1, `candidacy:backfill-appeals`, idempotent via a `legacy_application_id` column). The
   old tables and the shared rows stay untouched (read-only legacy).
3. `module_type = 'candidacy_appeal'` and the four stage keys are **permanent** (stored in rows). The legacy workflow class stays
   registered under the same key and only keeps three jobs: sidebar visibility (its `stages()` carry the four roles), `createRoute()`
   and `queueRoute()` pointing at **our** pages, and `ProvidesLinks`. Its Core summary/detail hooks keep serving the legacy row.
4. **One policy** (`CandidacyAppealPolicy`) decides who may view / act / download for every route, so no route can forget a check.
5. Student identity follows the Workstation module: `student_id` or `demo_profile_id` (`StudentDirectory` / `StudentIdentity`).

## 4. Behaviour table

| Behaviour | Inside our module? How | Needs a shared edit? |
|---|---|---|
| Who sees which appeal | yes: our queue queries filter on the named `*_profile_id` belonging to the user (CGS stage: role check); one policy | no |
| Who may decide | yes: the service checks stage and person inside a locked transaction | no |
| Routing by department and named people | yes: `departments`, `staff_profiles`, `staff_assignments`; people fixed on the appeal at submission | no |
| Return handling (resubmit to the returning stage; Dean return -> CGS re-check -> Dean) | yes: `round`, `returned_by_stage`, `needs_cgs_recheck` | no |
| Dean re-check path | yes (same) | no |
| Notifications (email + bell, matrix) | yes: our notification classes, `['mail','database']` / `['database']` | no |
| No withdraw | yes: there is no such route or service method | no |
| 12-month rule | yes: `extension_months_used` with a DB CHECK 0..12 plus service validation | no |
| Navigation entries for approvers | yes: `queueRoute()` of our registered workflow + `ProvidesLinks` | no |
| Navigation for students | yes: "My Candidacy" is already ours; "New Application" entry via `createRoute()` | no |
| Track My Application summary | yes: render it on `candidacy.status` (priority 1) | no |
| Signatures, memo, verification codes | yes: Dompdf, GD, files under our own storage path | no |
| Live updates | yes: same pattern as `WorkstationLiveVersion` | no |
| Cache headers on our pages | yes: our `NoStoreResponse` middleware on our routes | no |
| Hide the one legacy appeal from Core's Overview page | no | yes (Core page) |
| Core dashboard badges that count engine-queue items for our four roles | no (they count only engine rows, so they will show 0 for new appeals) | yes (Core service) if the badge must include our appeals |
| Core sidebar highlighting of our portal pages for approvers | partly (our own page header shows where you are) | cosmetic, not needed |

## 5. Gaps to report (none edited)

1. The Core "Overview" page keeps listing the single legacy appeal (it is a real Core application). New appeals are not there by design.
   Smallest change if it should go: filter `module_type = 'candidacy_appeal'` out of `ApplicationTrackingController::index()`.
2. Core's approver dashboard counts only engine queue rows; new appeals will not add to that number. The portal queue and our
   own sidebar link are the places that show real counts.

## 6. Phase plan and commits
Phase 0 notes (this file) -> Phase 1 data model + backfill -> Phase 2 service, policy, signatures, notifications, commands ->
Phase 3 memo PDF -> Phase 4 pages, live updates, demo data. One local commit per finished phase.

## 7. Phase 1 delivered (data model)

Tables added (prefix `candidacy_`): `candidacy_departments`, `candidacy_staff_profiles`, `candidacy_staff_assignments`,
`candidacy_appeals`, `candidacy_appeal_documents`, `candidacy_appeal_events` (append-only), `candidacy_appeal_approvals`.
The legacy `candidacy_appeal_publications` table was **extended** (nullable `candidacy_appeal_id`, `title`, `venue`, `status`,
`year`; the legacy columns became nullable) because the brief's name for the new publications table was already taken by it.
`study_candidacies` gained `department_id`, `original_expiry_date`, `extension_months_used` (CHECK 0..12).
`candidacy_appeals.legacy_application_id` (unique) is the only column beyond the brief: it makes the backfill idempotent.
`study_candidacy_id` is nullable on an appeal because a demo profile has no `study_candidacies` row.

Rules enforced by the database (MySQL: CHECK constraints and generated unique keys `open_key` / `draft_key` / `single_holder_key`;
the SQLite test database: partial unique indexes and RAISE triggers carrying the same constraint names): one open appeal and one
draft per student, `(student, sequence_no)` unique, sequence 1..12, requested/approved months 1..12, exactly one identity per appeal,
one chair and at most one dean per department, allowance 0..12.

Helpers: `StudyCandidacy::allowanceUsed()` is `max(extension_months_used, cumulative_extension_months)` until the legacy counter is
retired; `hasOpenAppeal()` looks at the new table and at not-yet-imported legacy applications; `hasFinalRejection()` honours both
`final_outcome = rejected_final` and the legacy `last_rejection_at`.

Backfill on the development database (2026-11-01): 1 department, 4 staff profiles, 2 assignments (lecturer, chair), 1 appeal
(`SCA-22001001-01`, approved, 6 months, memo kept), 5 events, 4 approvals, 1 publication, 3 candidacies updated; nothing unmapped;
`candidacy:check-allowance` reports OK for all 3 candidacies.

## 8. Phase 2 delivered (rules and service)

* `CandidacyAppealService` is the only writer of `candidacy_appeals.status` / `current_stage`: `eligibilityProblems`, `saveDraft`, `submit`,
  `resubmit`, `decide` (approve / verify / return / reject), `closeExpired`. Every method is one transaction on a locked row; notifications
  go out after the commit and a mail failure never undoes a decision.
* `CandidacyAppealPolicy` (registered in `ModuleProvider`) is the single access rule: owner; named person of the stage; CGS roles from
  `config('candidacy.cgs_stage_roles')`; signature images only for their owner.
* `CandidacySignatureService`: GD decode, min 200x60, resize into 600x200, re-encode PNG, generated name on the private `local` disk;
  decisions copy the image (snapshot). Demo placeholder drawn with GD.
* `CandidacyAppealNotice` + `config('candidacy.mail')` implement the notification matrix (mail + bell; demo students mail only).
* `candidacy:close-expired-appeals [--dry-run]`, scheduled 07:20 Asia/Kuala_Lumpur from `ModuleProvider` (routes/console.php is shared).
* `CandidacyMemoService::generate()` is the Phase 3 hook (returns null until then, so approvals work without a memo).
* Dean rejection also sets `study_candidacies.last_rejection_at`, which keeps the dismissal generator working ("appeal rejected").
* Tests: `CandidacyAppealServiceTest` (33). Legacy reminder / dismissal / old appeal tests still pass untouched.

## 9. Phase 3 delivered (memo PDF)

* `CandidacyMemoService::generate()` (called inside the Dean-approval transaction, so a failed render rolls the approval back) renders
  `Resources/views/candidacy/memo/appeal_memo.blade.php` with Dompdf: A4, Tahoma, black and white (logo converted to grey in memory).
  Page 1: memorandum (To / Through Chair and Supervisor with signature + digital stamp / From / Date), body, student declaration stamp,
  APPROVED block with the Dean's signature + stamp. Page 2: endorsement and approval record with the verification codes (CGS appears here).
* Stamp: `Digitally approved by {name}, {designation} on {dd Mon yyyy, hh:mm} (GMT+8) · Ref {appeal_no} · Code {code}`.
  Signatures come from the per-decision copy, never from the live profile.
* Stored as `candidacy/memos/<appeal id>-<random>.pdf` on the private disk; download name
  `<appeal_no>_<Student Name>_<StudentID>_Memo - Appeal for Extension.pdf` (illegal characters removed). Download rights: policy `downloadMemo`.
* `php artisan candidacy:verify-memo <appeal_no>` prints OK / MISMATCH per stage (code recomputed from the record, signature copy hash).
* Checked visually (Ghostscript render of both pages) and by text extraction in `CandidacyMemoTest`.
