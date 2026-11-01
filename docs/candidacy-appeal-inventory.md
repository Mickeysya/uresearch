# Study Candidacy Appeal: inventory

Module: `App\Modules\Chloe` (owner: Chloe). Written 2026-11-01 after the appeal rebuild. The design reasoning is in
[candidacy-appeal-design-notes.md](candidacy-appeal-design-notes.md); this file is the reference for what exists.

## 1. Where the data lives

Our module is the single source of truth. New appeals are **never** written to the shared `applications`, `approval_history` or
`application_documents` tables, so they cannot appear in the old Core approval queue. Nothing here edits a shared table.

| Table | Holds |
|---|---|
| `candidacy_departments` | `code`, `name`, `active`, `is_demo` |
| `candidacy_staff_profiles` | a person who signs: `user_id` (null for staff without a login), `full_name`, `designation`, `department_id`, `signature_path`, `signature_sha256`, `signature_updated_at`, `is_demo` |
| `candidacy_staff_assignments` | which profile is `lecturer` / `chair` / `dean` of which department (one chair and at most one dean per department) |
| `study_candidacies` (existing, extended) | `department_id`, `original_expiry_date`, `extension_months_used` (CHECK 0..12) beside the existing expiry, status and legacy `cumulative_extension_months` |
| `candidacy_appeals` | the appeal: `appeal_no`, `sequence_no`, identity (`student_id` or `demo_profile_id`), `study_candidacy_id`, `department_id`, the named people (`supervisor_/chair_/dean_profile_id`), `status`, `current_stage`, `round`, `returned_by_stage`, `needs_cgs_recheck`, `requested_months`, `approved_months`, `expiry_at_submission`, `new_expiry_date`, sections A, B and D, `declaration_accepted_at`, timestamps (`submitted_at`, `stage_entered_at`, `last_action_at`, `closed_at`), `final_outcome`, memo path/name/time, `legacy_application_id`, `is_demo` |
| `candidacy_appeal_publications` (existing, extended) | section C rows: `title`, `venue`, `status`, `year` (the legacy `description` column stays for imported rows) |
| `candidacy_appeal_documents` | official form and supporting files: `kind`, `original_name`, generated `stored_path`, `mime`, `size`, `sha256`, `round_uploaded` |
| `candidacy_appeal_events` | **append-only** history (see section 3) |
| `candidacy_appeal_approvals` | one row per decision of a round: approver snapshot (`full_name`, `designation`), `signature_snapshot_path` + hash, `decision`, `documents_complete`, `remark`, `decided_at`, `verification_code` |

Database-level rules (MySQL CHECKs and generated unique keys; SQLite partial unique indexes and triggers in the test database, same
names): one open appeal and one draft per student, `(student, sequence_no)` unique, sequence 1..12, months 1..12, exactly one identity per
appeal, allowance 0..12. Files live on the private `local` disk under `candidacy/` (signatures, signature copies, documents, memos); none under `public/`.

## 2. Statuses and transitions

`draft -> with_supervisor -> with_chair -> with_cgs -> with_dean -> approved | rejected`; any stage can send the appeal to `returned`;
`returned -> (resubmit) -> the returning stage`, except a **Dean return**, which resubmits to `with_cgs` (flag `needs_cgs_recheck`) and then
goes back to the Dean; a returned appeal whose candidacy has expired (today is AFTER the expiry date) is set to `closed_expired` by the daily job; it can still be resubmitted on the expiry date itself. `approved`, `rejected`
(final) and `closed_expired` are end states. There is **no withdraw** anywhere. Only `CandidacyAppealService` writes `status` / `current_stage`.

| Stage | Who | Actions |
|---|---|---|
| supervisor | the appeal's named lecturer | Approve, Return |
| chair | the department's chair, fixed at submission | Approve, Return |
| cgs | any CGS role (`candidacy.cgs_roles`) | Verify (needs "documents complete = yes"), Return |
| dean | the department's dean (default dean when it has none) | Approve, Return, Reject (final) |

Dean approval (one transaction): approved months = requested months (the Dean cannot change them), new expiry = current expiry + months
(calendar months, no overflow), `study_candidacies.candidacy_expiry_date` and `extension_months_used` updated, the memo built, history written.
Dean rejection is final and needs at least one reason from the Dean's list ("Other" needs a remark): `final_outcome = rejected_final`, `study_candidacies.last_rejection_at` set, the expiry date stays unchanged, and no further appeal is accepted. The reasons are stored with the decision (event `reason_codes`, approval `remark`), shown on the appeal page and in the history, and sent in the rejection e-mails and bell. (Per Handbook 4.3 a rejection alone does not make a student a dismissal candidate; see Planned. The existing dismissal generator is unchanged in this round.)

## 3. Rules, numbers and where to change them

| Rule | Where |
|---|---|
| eligibility: active candidacy; **on or before the expiry date** (the expiry day itself is still a valid day; compared as Asia/Kuala_Lumpur calendar dates, not UTC; the day after is refused); no open appeal; no earlier final rejection; at least 1 month left; 1..remaining months | `CandidacyAppealService::eligibilityProblems()` |
| allowance 12 months | `config('candidacy.max_extension_months')` |
| waiting markers: gold dot from 5 days, red from 10 (no reminders, no escalation) | `config('candidacy.waiting_marker')` |
| CGS roles (view every appeal, act at the CGS stage): `cgs`, `non_exec_cgs`, `senior_exec_cgs`, `manager_cgs`, `senior_director_cgs` | `config('candidacy.cgs_roles')` (the four are `Role::cgsTeam()`, the roles the Workstation CGS routes accept) |
| signature required to approve (lecturer, chair, dean; optional for CGS) | `config('candidacy.signature_required_stages')` |
| signature image limits (PNG/JPG, 1 MB, min 200x60, resized into 600x200) | `config('candidacy.signature')` |
| return / reject reason lists per stage; "Other" needs a remark. The Dean's rejection list is the agreed starting list (CGS will confirm it); keys are stored with the decision, labels are display-only; rows made before the list changed use `candidacy.legacy_reject_reasons`, and a rejection with no reason shows "Not recorded" | `config('candidacy.reasons')` |
| e-mail wording and placeholders | `config('candidacy.mail')` |
| default dean when a department has none | `config('candidacy.default_dean_profile')`, else the first `dean_pgr` user's profile |
| documents: official form `.xlsx` required, up to 4 supporting (`xlsx`/`pdf`/`docx`), 10 MB each, extension **and** content checked | `CandidacyDocumentService` |
| history is append-only (events cannot be updated or deleted by code; a test scans the code) | `CandidacyAppealEvent` |

## 4. Routes and who may use them

All routes send `Cache-Control: no-store, max-age=0` (`NoStoreResponse`). Access is decided by `CandidacyAppealPolicy` for every route
(`view`, `edit`, `act`, `downloadMemo`, `downloadDocument`, `viewSignature`); anything else is 403.

| Route | Who |
|---|---|
| `/candidacy` (My Study Candidacy), `/candidacy/appeals/new`, `/candidacy/appeals/draft/{1-4}`, documents upload/remove, `/candidacy/appeals/{id}/edit` + `resubmit` | students (own appeals only) |
| **`/candidacy/appeals/{id}`: THE appeal page** (one URL for everyone), `.../region`, `.../documents/{doc}`, `.../memo`, `/candidacy/live/version` | the student, the people named on that appeal (also before it has reached them: read-only), CGS roles; drafts: the student only. The "Your action" card appears only while the appeal is pending that user's action |
| `/candidacy/portal` (Approval Portal), `.../region`, `.../live/version`, `POST .../appeals/{id}/decide` | supervisor, chair, dean, CGS roles |
| `/candidacy/portal/appeals/{id}` (old review URL) | redirects to the appeal page |
| `/candidacy/signature` (My Signature), `.../image` | the same roles; the image only to its owner |
| `/cgs/candidacy` (Candidacy Management > Operations), `.../region`, `/cgs/candidacy/live/version`, `/cgs/candidacy/live/stats`, `/cgs/candidacy/dismissals` | CGS team |
| `/candidacy-appeal/*` (old URLs) | redirect to the new pages; nothing can be decided through them |

Every non-named user gets 403 on the appeal URL and never sees the appeal in any list.

### Approval Portal tabs

| Role | Tabs |
|---|---|
| Supervisor, Chair, Dean | **Pending my action** (button *Review*; waiting-days dot and legend; "Re-check after Dean return" tag) / **Ongoing** (named on it, no final result, not pending me; *Current step* column; *View details*) / **History** (named on it, final result: Approved, Rejected, Closed expired; *My decision* = Approved / Returned / Rejected (the Dean's own rejection) / Not reached, and *Final outcome*; *View details*). The three never overlap |
| CGS roles | **Pending my action** / **Decided by me**, plus the text link "View all appeals →" to Operations (no other role gets it) |

Filters (search, department, months, waiting at least, round) apply to the active tab. Counts on the tabs refresh live.

### Operations (CGS)

Four cards: **In progress** (with supervisor, chair, CGS or dean; not returned), **Returned**, **Approved**, **Rejected**. Closed expired is not a card; it is
listed under **All** and the Status filter. A card click filters the list (active card highlighted, click again to clear; the state is in the URL, so a refresh keeps it).
Cards, tabs (In progress / Returned / Approved / Rejected / All) and the table come from `CandidacyOperationsQuery` with the same filters, so they cannot disagree.
Filters: search, department, status, current step, months, waiting at least, round. 25 rows per page. Below 1100 px the table stacks into labelled rows.
"View details" opens the appeal page; Back returns to this page with its group, filters and page number.

### Back and the return path
Lists add `?return=<their own URL>` to the appeal link. `CandidacyReturnPath` accepts it only if it is an internal `/candidacy…` or `/cgs/candidacy…` path (never an
absolute or protocol-relative URL, a backslash, control characters or `..`); otherwise Back goes to the role's default list.

### Live updates (same design as Workstation)
Poll every 15 s while the tab is visible, check at once when it becomes visible, a "Pause updates" control, the list region replaced in place (scroll position,
filters, tab, active card and page kept). Endpoints: Portal `/candidacy/portal/live/version` (only the caller's own tabs and counts), Operations
`/cgs/candidacy/live/version` and `/cgs/candidacy/live/stats` (CGS only, throttled with the shared `workstation-live` limiter), appeal page `/candidacy/live/version?appeal=`.
All send `Cache-Control: no-store`.

### Assistant icon
The candidacy pages include the Workstation widget (`workstation/_assistant-widget`) in static mode (`wsaStatic`): identical markup, styles, blink, eye follow and
greeting bubble, one fixed line, no suggestions, no input, no request (the endpoint URLs are not rendered). A test compares the two renderings.
Every candidacy page leaves 120 px free at the bottom so the icon never covers the last row's button.

### One wording
Tabs and text use **Pending my action** everywhere. Statuses come from one map, `CandidacyAppealPresenter::statusLabel()`. A test scans the module and its docs for the old words.

### Planned: Excel export of Operations list
Not built yet (no Export button). Next phase: export exactly the filtered Operations list (same query and columns as the table) to `.xlsx`, CGS roles only.

Navigation uses the module mechanism, not a shared edit: `CandidacyAppealWorkflow::queueRoute()` = `candidacy.portal.queue` and
`ProvidesLinks::links()` adds "My Appeal Signature" (and "Candidacy Appeals" for CGS roles that own no engine stage).

## 5. Notifications (e-mail and bell together; the subject always starts with the appeal number)

| Event | Goes to | Template |
|---|---|---|
| submitted / resubmitted / approved at a step | the person (or all CGS users) now holding the appeal | `turn` |
| approved or verified at a step | the student | `approved_student` |
| returned | the student (reasons, remarks, "resubmit before {{expiry_date}}") | `returned_student` |
| returned | the lecturer; the Chair too after a CGS or Dean return; CGS too after a Dean return | `returned_info` |
| Dean rejection (final) | the student; all named people and CGS | `rejected_student`, `rejected` |
| Dean approval | the student (bold approved months and new expiry, **memo attached**); all named people and CGS | `final_student`, `final_info` |
| closed because not resubmitted | the student | `closed_expired` |

A demo student has no login: e-mail only. A failed e-mail never undoes a committed decision. The queue worker must be restarted after a
config change (it keeps the old config in memory).

## 6. Memo, signatures and verification

* **Memo** (Dompdf, A4, black and white, Tahoma): page 1 memorandum (To the Dean; Through the Chair and the Supervisor, each with signature
  and stamp; From; Date; body; student declaration stamp; APPROVED block with months, new expiry, the Dean's signature and stamp); page 2
  endorsement and approval record (step, name and designation, decision, date and time GMT+8, verification code; CGS appears here).
* **Stamp**: `Digitally approved by {name}, {designation} on {dd Mon yyyy, hh:mm} (GMT+8) · Ref {appeal_no} · Code {code}`.
* **File name**: `<appeal_no>_<Student Name>_<StudentID>_Memo - Appeal for Extension.pdf`; stored as `candidacy/memos/<id>-<random>.pdf`.
* **Signatures**: each approver uploads one image on "My Signature"; it is decoded with GD, re-encoded as PNG, resized, stored under a generated
  name. Every decision stores its own **copy** (`signature_snapshot_path`), so replacing a signature never changes an issued memo.
* **Verification code**: first 10 characters (upper case) of HMAC-SHA256 over appeal number, stage, profile id and decision time, keyed with the app key.
  `php artisan candidacy:verify-memo <appeal_no>` prints OK or MISMATCH per stage (code and signature copy).

## 7. Commands and schedules

| Command | Purpose |
|---|---|
| `candidacy:close-expired-appeals [--dry-run]` | closes returned appeals whose candidacy expired; idempotent; **daily 07:20 Asia/Kuala_Lumpur**, registered from `ModuleProvider` (`routes/console.php` is shared) |
| `candidacy:verify-memo {appeal_no}` | OK / MISMATCH per stage |
| `candidacy:check-allowance` | `extension_months_used` and the expiry date against the approved appeals |
| `candidacy:backfill-appeals` | one-time, idempotent import of the legacy engine appeal(s) |
| `candidacy:demo-seed` / `candidacy:demo-clear` | 6 departments with demo staff and 41 demo appeals / remove exactly those (see section 9) |
| `candidacy:remind`, `candidacy:generate-dismissals` | unchanged (reminders and dismissals were not part of this work) |

## 8. Track My Application: decision

The sidebar item "Track My Applications" has two sub-items. **Overview** is Core's (`applications.index`). **My Candidacy** is the route
`candidacy.status`, which belongs to this module, so priority 1 applied: our page renders the summary list (appeal number, status, current step,
requested and approved months, last updated, "View full details"). Core's Overview is untouched and still lists the one legacy engine appeal
(see limitations).

## 9. Demo data and accounts

`candidacy:demo-seed` is deterministic and idempotent. It uses the existing Workstation demo students and **does not change their expiry
dates** (so "loan valid until" and the seat totals 315 / 152 / 157 / 3 / 3 are unchanged); the history is written backwards from each
student's current expiry. Result: 41 demo appeals (3 drafts; waiting at supervisor 6, chair 6, CGS 5 (one re-check), Dean 4, with 0 to 14
waiting days; returned by each of the four stages; 9 approved with memos including one student with two approvals and one at exactly 12 of
12 months; 3 final rejections; 1 closed-expired), plus the legacy one (42 in total). Every demo row has `is_demo`; `candidacy:demo-clear` removes only those, their
files and the demo staff, never accounts or real signatures.

Real accounts mapped to Computer & Information Sciences (emails only; passwords are never recorded):

| Role | Email |
|---|---|
| lecturer | `supervisor@utp.edu.my` (Dr. Aisyah Rahman) |
| chair | `chair@utp.edu.my` (Dr. Lim Wei Chun) |
| dean | `dean@utp.edu.my` (Prof. Dr. Hafiz Osman; default dean and CIS dean) |
| CGS | `cgs@utp.edu.my`, `manager@utp.edu.my`, `director@utp.edu.my`, `seniorexec@utp.edu.my` |

These accounts have no signature on file: upload one on "My Appeal Signature" before approving. The seeded `cgs@utp.edu.my` password does
not work and was not reset.

## 10. Shared files touched

None edited. New files only: `config/candidacy.php`, `public/css/chloe-candidacy.css`, `public/js/chloe-candidacy.js`, our module, our tests
and docs. Two existing Workstation tests got a pinned clock (`WorkstationInsightsTest`, `WorkstationDemoSeedTest`; test code only).

## 11. Known limitations

1. Core's **Overview** page still lists the one legacy engine appeal (a real Core application). Smallest change: filter
   `module_type = 'candidacy_appeal'` out of `ApplicationTrackingController::index()`.
2. Core's approver **dashboard badges** count only engine queue rows, so they do not include the new appeals; the portal queue shows the real counts.
3. `study_candidacy_id` is nullable on an appeal (demo students have no `study_candidacies` row), so their allowance is derived from their appeals.
4. The legacy `cumulative_extension_months` is kept and merged with `extension_months_used` (the larger wins) until it is retired.
5. The floating assistant icon can sit on top of the last column of a table at the far right (same behaviour as the Workstation widget).
6. The required-documents list is generic (official form + up to four supporting files) and is to be reviewed.
7. The shared stylesheet colours the CGS stat numbers and the shared "Back" link blue; the CGS candidacy pages keep that existing design.

## 12. Naming
The chair step is called **Department Chair** in every display text (pages, step bars, filters, e-mails, bell, memo, reason lists, demo data). Stored values are
unchanged (`chair`, `with_chair`, `Role::CHAIR`), and the memo verification code does not use the label, so `candidacy:verify-memo` still prints OK for existing memos.

## 13. Planned (documentation only: nothing here is built)

1. **Applied appeals are flagged**: a student with an open appeal is flagged and is not listed as a dismissal candidate while the appeal is open.
2. **Dismissal candidate rule**: today is after the current expiry date **and** no Softbound submitted **and** no open appeal **and** the student is not inactive, completed or dismissed.
   A Dean rejection alone does **not** make a student a candidate (Handbook 4.3). CGS reviews the list; the final decision is outside our scope.
3. **Softbound and other student statuses (inactive, completed, dismissed) are read-only from the other module through ONE reader class.** Lookup order, stop at the first hit:
   1. the shared Softbound module's real table;
   2. our own, clearly labelled **demo overlay** table, used ONLY when the shared source has no record for that student AND the student is one of our demo students;
   3. no record = not submitted.
   The overlay never overrides a shared record and never applies to real students. We never write to the shared tables. Config `candidacy.softbound_source`:
   `demo` (overlay only, until merge) / `shared_with_demo_fallback` (recommended after merge) / `shared` (production, overlay off).
   The overlay table is filled by `candidacy:demo-seed` and cleared by `candidacy:demo-clear`. A demo-only command `candidacy:demo-softbound <studentID>` writes only to the overlay.
   Any screen that shows a value resolved from the overlay marks it **"Demo data"**. A planned command `candidacy:check-softbound-source` prints how many students resolve from shared, overlay and none.
   Reminders and dismissal candidates query the reader at run time and never copy the status. No page lets CGS set Softbound.
   **Open item:** the shared Softbound table name and its student ID, submitted date and status fields are still to be obtained from the module owner.
4. **Registry upload page** (Registry and CGS roles). Upload types: (a) confirmed dismissals; (b) expiry-date changes with a reason (deferment, change of mode, change of programme, other) and a letter reference.
   Preview with row errors, confirm to apply, log old and new date, who and when; reminders recalculate; `extension_months_used` is unchanged; a CGS manual adjustment with a reason is the backup.
   **Open item:** confirm with CGS whether the Registry's new expiry date already includes approved appeal months (assumed yes).
5. **Excel export of the Operations list** (to be reminded to the user in the next phase): exactly the filtered list, same query and columns, CGS roles only.
6. The "Students Near Candidacy Expiry" information was removed from Operations in this round; it returns later on the CGS overview page.

## 14. Rule sources

| Rule | Source |
|---|---|
| Extension appeal basics and eligibility | Postgraduate Student Handbook 2025, 2.1 (p.7) and 2.1.1 (p.8) |
| Candidacy period and its extension | Handbook 2025, 2.5 to 2.7 |
| Dismissal: a rejection alone is not a ground; candidate conditions | Handbook 2025, 4.3 (p.19) |
| Softbound and the standing of the student | Handbook 2025, 4.4 (p.20) |
| Appeals and decisions | Handbook 2025, 5.3 (p.30) |
| Approval order including the Dean | confirmed with CGS during requirement gathering |
| 12-month cap on extensions | confirmed with CGS during requirement gathering |
| A Dean rejection blocks all future appeals | confirmed with CGS during requirement gathering |
| An appeal is allowed on the last day of the expiry date | confirmed with CGS during requirement gathering |
| Dean rejection reasons list | starting list, to be confirmed by CGS |
