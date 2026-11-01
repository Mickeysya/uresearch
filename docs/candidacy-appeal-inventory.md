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
| `candidacy_staff_assignments` | which profile is `lecturer` or `chair` of which department (exactly one chair per department; nobody is both, nobody is in two departments). The Dean has no assignment: see section 16 |
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
| dean | the ONE Dean of the university (section 16) | Approve, Return, Reject (final) |

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
| the single Dean | the profile of the account with the Dean role (`CandidacyStaffDirectory::defaultDean()`); `config('candidacy.default_dean_profile')` can name a profile instead |
| documents: official form `.xlsx` required, up to 4 supporting (`xlsx`/`pdf`/`docx`), 10 MB each, extension **and** content checked | `CandidacyDocumentService` |
| history is append-only (events cannot be updated or deleted by code; a test scans the code) | `CandidacyAppealEvent` |

## 4. Routes and who may use them

All routes send `Cache-Control: no-store, max-age=0` (`NoStoreResponse`). Access is decided by `CandidacyAppealPolicy` for every route
(`view`, `edit`, `act`, `downloadMemo`, `downloadDocument`, `viewSignature`); anything else is 403.

| Route | Who |
|---|---|
| `/candidacy` (My Study Candidacy), `/candidacy/appeals/new`, `/candidacy/appeals/draft/{1-4}`, documents upload/remove, `/candidacy/appeals/{id}/edit` + `resubmit` | students (own appeals only) |
| **`/candidacy/appeals/{id}`: THE appeal page** (one URL for everyone), `.../region`, `.../documents/{doc}`, `.../memo`, `/candidacy/live/version` | the student, the people named on that appeal (also before it has reached them: read-only), CGS roles; drafts: the student only. The "Your action" card appears only while the appeal is pending that user's action |
| `/candidacy/portal` (Study Candidacy Approval Portal), `.../region`, `.../live/version`, `POST .../appeals/{id}/decide` | supervisor, chair, dean, CGS roles |
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
Filters: search, department, status, current step, months, waiting at least, round. 25 rows per page (one pagination bar, see section 15). Below 1100 px the table stacks into labelled rows.
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
`ProvidesLinks::links()` adds "My Signature" (and "Candidacy Appeals" for CGS roles that own no engine stage).

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
| `candidacy:close-expired-appeals [--dry-run]` | closes returned appeals whose candidacy expired; idempotent; **daily 07:30 Asia/Kuala_Lumpur**, registered from `ModuleProvider` (`routes/console.php` is shared) |
| `candidacy:verify-memo {appeal_no}` | OK / MISMATCH per stage |
| `candidacy:check-allowance` | `extension_months_used` and the expiry date against the approved appeals |
| `candidacy:backfill-appeals` | one-time, idempotent import of the legacy engine appeal(s) |
| `candidacy:demo-seed` / `candidacy:demo-clear` | the nine departments with their demo roster and the demo appeals / remove exactly those (see sections 9 and 16) |
| `candidacy:remind`, `candidacy:generate-dismissals` | unchanged (reminders and dismissals were not part of this work) |

## 8. Track My Application: decision

The sidebar item "Track My Applications" has two sub-items. **Overview** is Core's (`applications.index`). **My Candidacy** is the route
`candidacy.status`, which belongs to this module, so priority 1 applied: our page renders the summary list (appeal number, status, current step,
requested and approved months, last updated, "View full details"). Core's Overview is untouched and still lists the one legacy engine appeal
(see limitations).

## 9. Demo data and accounts

`candidacy:demo-seed` is deterministic and idempotent. It uses the existing Workstation demo students and **does not change their expiry
dates** (so "loan valid until" and the seat totals 315 / 152 / 157 / 3 / 3 are unchanged); the history is written backwards from each
student's current expiry. Result: 54 demo appeals plus the legacy one (55 in total; 130 approvals, 185 events): every stage, every return type, rejections with reasons, one closed expired, a student at exactly 12 of 12 months, two students with two approvals, 0 to 14 waiting days, 12 approved with memos that verify. Computer & Information Sciences has 23 appeals (the real lecturer, chair, Dean and CGS all have rows in every tab). Every demo row has `is_demo`; `candidacy:demo-clear` removes only those, their
files and the demo staff, never accounts or real signatures.

Real accounts mapped to Computer & Information Sciences (emails only; passwords are never recorded):

| Role | Email |
|---|---|
| lecturer | `supervisor@utp.edu.my` (Dr. Aisyah Rahman) |
| chair | `chair@utp.edu.my` (Dr. Lim Wei Chun) |
| the Dean | `dean@utp.edu.my` (Prof. Dr. Hafiz Osman; the one Dean for the whole university) |
| CGS | `cgs@utp.edu.my`, `manager@utp.edu.my`, `director@utp.edu.my`, `seniorexec@utp.edu.my` |

These accounts have no signature on file: upload one on "My Signature" before approving. The seeded `cgs@utp.edu.my` password does
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
   A Dean rejection alone does **not** make a student a candidate (Handbook 4.3). CGS reviews the list; the final decision is outside our scope. The dismissal list will ask
   `Support\StudentStatusReader` at run time and never copy the status.
3. **Registry upload page** (Registry and CGS roles) is ONLY for **dismissal confirmation** (the Registry's confirmed dismissals, with preview, row errors, confirm to apply, who/when logged).
   Expiry-date changes outside appeals (deferment, change of mode or programme, other) are made by **CGS** as a manual adjustment with a reason and a letter reference; an optional CGS Excel upload may come later.
   Reminders recalculate on their own (the log is keyed by the expiry date) and `extension_months_used` is unchanged.
   **Open item:** confirm with CGS whether a changed expiry date already includes approved appeal months (assumed yes).
4. **Reminder log page**: not now (the log table exists; a page for CGS to see it is later).
5. **CGS menu order reserved**: Operations, Insights, Dismissal List. The Insights forecast chart "expiring within 3 months" will filter the student list when clicked, like Workstation Insights
   (this replaces the "Students Near Candidacy Expiry" card removed from Operations).
6. **Excel export of the Operations list** (to be reminded to the user in the next phase): exactly the filtered list, same query and columns, CGS roles only.
7. **Improvements to discuss with CGS**: urgent reminders 3 days and 1 day before the expiry date.
8. **Shared Softbound source**: the reader's shared adapter is written but inactive. **Open item:** the shared Softbound table name and its student ID, submitted date and status fields are to be obtained from the module owner. Then set
   `candidacy.status_source` to `shared_with_demo_fallback` (recommended after the merge) or `shared` (production, overlay off).

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

## 15. UI conventions: one pagination component

Every paginated candidacy list (Operations and every Approval Portal tab, 25 rows per page) uses ONE bar: the view `candidacy/wca/_pagination.blade.php` with the
window logic in `Support/CandidacyPagination`. Its markup and CSS are the Workstation CGS seat-list bar (`.cgs-pagination`, `.is-current`, `.is-disabled`; the
declarations are copied into `chloe-candidacy.css` because the Workstation page defines them inline): "« Prev", numbered buttons with the current page in dark blue, "Next »".

| Rule | Detail |
|---|---|
| Prev / Next | greyed `span` with `aria-disabled="true"` when they cannot be used (Prev on page 1, Next on the last page): not a link, not focusable, no hover |
| Current page | not a link, `aria-current="page"` |
| Window | at most 7 slots (5 at 480 px and below, smaller buttons; both bars are rendered and CSS shows one). Up to 7 pages: every number. More: first and last always shown, exactly 7 slots, "…" for gaps (plain text, never two side by side). 12 pages: page 1-4 `1 2 3 4 5 … 12`, page 6 `1 … 5 6 7 … 12`, page 9-12 `1 … 8 9 10 11 12` |
| Line below | `Showing 26 to 39 of 39 appeals` (right noun per list). 0 results: `No appeals found` and no bar. One page: only the "Showing" line |
| State | every link keeps tab, search, filters and card in the URL; changing a filter, card, tab or search drops `page` (back to page 1) |
| Bad page numbers | below 1, empty or non-numeric -> page 1; beyond the last page -> the last page (never an empty page or an error) |
| Live update | the bar is part of the refreshed region, and the region URL carries the current page, so a 15 s refresh keeps the page; if the page no longer exists it shows the last one |
| Back from the appeal page | the return path includes `page`, so Back lands on the same page |

Workstation pagination is not changed. Note for the Workstation owner: its bar prints every page number (no window), so a very long list would wrap onto several lines.

## 16. Departments, roster, department auto-fill, single Dean, My Signature

**Nine departments** (`config('candidacy.departments')`, stored in `candidacy_departments` with a `faculty` column):
Faculty of Engineering: Chemical Engineering; Civil & Environmental Engineering; Electrical & Electronic Engineering; Mechanical Engineering; Petroleum Engineering; Geoscience.
Faculty of Science, Management & Computing: Fundamental & Applied Sciences; Computer & Information Sciences; Management & Humanities. Department filters group them by faculty.

**Roster rules** (enforced by `candidacy:demo-seed` and covered by tests): exactly 1 Department Chair per department (9 chairs); 5 to 7 lecturers per department; nobody is both
a lecturer and a chair, and nobody belongs to two departments; every name is unique and no fake name equals a real account's name. The real chair account is the Chair of
Computer & Information Sciences and the real lecturer account is one of its lecturers (the other 4 to 6 are fake); the other 8 chairs and all other lecturers are fake staff
profiles with no login (`user_id` null). Names (Malay, Chinese and Indian, with titles) come from `CandidacyDemoRoster` with a fixed seed, so every reseed gives the same people.
The student's Supervisor list is the lecturers of the student's department, sorted by the name without its title, and never contains a chair.

**One Dean for the whole university**: the profile of the account that has the Dean role (`dean_pgr`); no department has a Dean assignment, and every appeal, memo, e-mail and
history entry names that person. If a second account ever gets the Dean role the lookup takes the first by id: confirm which one is meant before that happens.

**Department auto-fill.** A student never chooses a department. `CandidacyDepartmentResolver`: the candidacy record's `department_id`; when empty it is derived from the programme text: first the exact name in the programme catalogue
(`config('candidacy.programmes')`, see the catalogue section below), then `config('candidacy.programme_department_patterns')` (Chemical, Civil/Environmental, Electrical/Electronic, Mechanical, Petroleum, Geo, Computer/Information/IT/Software,
Applied Science/Chemistry/Physics/Mathematics, Management/MBA/Humanities; first match wins); text that matches nothing is placed by a hash of the student ID (stable). The accounts in
`config('candidacy.cis_demo_accounts')` always belong to Computer & Information Sciences. New Appeal step 1 shows the department read-only ("Taken from your student record"), offers only the
lecturers of that department and shows the Department Chair read-only. A posted department that is not the student's own, or a supervisor who is not a lecturer of it (including a chair or the Dean), is refused on
the server; a student with no department gets a clear message and cannot submit.

**My Signature.** One page and one route (`candidacy.signature.show`), labelled "My Signature" everywhere (sidebar, the portal filter card, the "Upload your signature" link). The portal
button and the appeal-page link pass a validated internal `return` path (same rules as Back); after saving or removing a signature the user goes back there with the filters kept, and from the sidebar
stays on the signature page. (The hardbound module has its own, unrelated signature page and sidebar item; it is not ours.)

**Study Candidacy Approval Portal.** The heading and the browser tab read "Study Candidacy Approval Portal". The shared layout writes a fixed `<title>UResearch</title>`, so our page sets the tab
title itself from `data-doc-title` (module script); the layout itself would need an edit to do it server-side.

**"&" is escaped once.** Component attributes written as `subtitle="{{ ... }}"` were escaped by Blade and again by the component; they are now passed as bound values (`:subtitle="..."`). Tests render
a department name with "&" on the pages, filters, e-mail, bell and memo.

**Programme catalogue.** `config('candidacy.programmes')` is the single list of programmes: exact name, level (`coursework`, `master_research`, `phd`) and department. Department names:
Chemical Engineering; Civil & Environmental Engineering; Electrical & Electronic Engineering; Mechanical Engineering; Petroleum Engineering; Geoscience (Faculty of Engineering);
Computer & Information Sciences; Fundamental & Applied Sciences; Management & Humanities (Faculty of Science, Management & Computing). A student's department is the catalogue department of their
programme name (a trailing "(Full-Time)" / "(Part-Time)" is ignored); text that is not in the catalogue (real students keep the text they have) falls back to the keyword patterns, then the hash.
**The programme text of demo students comes from the demo seeders** (`workstation:demo-seed`), never typed by hand, so `workstation:demo-clear`, `candidacy:demo-clear` and re-seeding never bring
the old text back. Research programmes are shown as "<name> (Full-Time)"; every demo student is Full-Time research, because Workstation eligibility excludes Part-Time and Coursework.
The Coursework entries of the catalogue are therefore not used by any demo student.

**Demo data now.** All nine departments have demo students (14 to 20 each) and demo appeals (3 to 20 each); Computer & Information Sciences has 19 demo appeals plus the legacy appeal
SCA-22001001-01 (account `student@utp.edu.my`), which is left alone, so that account has 6 of 12 months used (it can still submit for up to 6 months).

## 17. Dean rejection confirmation, student status reader, automatic reminders

**Dean rejection (two steps).** "Reject (final)" opens the existing modal (reasons, remark for Other); its button only opens a SECOND dialog, "Confirm rejection of <appeal no.>", that
lists the consequences (closed as Rejected (final); no further appeal; the expiry date stays; the student can still submit the Softbound thesis before the expiry date, otherwise becomes a dismissal
candidate for CGS to review; the student, Supervisor, Department Chair and CGS are notified by e-mail and bell) and the chosen reasons and remark. Nothing is saved until "Confirm rejection"
(red; "Discard" is the secondary button and has the focus). Esc, clicking outside and Discard close only the second dialog (the first keeps what was entered) and change nothing. The final button is
disabled after one click, and the server refuses a second post (the appeal is no longer pending the Dean), so a double click rejects once. The student's e-mail and the appeal page say the student may
still submit the Softbound thesis before the expiry date.

**Student status reader** (`Support\StudentStatusReader`, one place): Softbound submitted (with date), completed, inactive (deferred included), dismissed, and which source answered. Lookup order, stop at the first hit:
1 the shared Softbound table (`SharedStatusAdapter`, **inactive** until the table is known); 2 the labelled **demo overlay** table `candidacy_demo_student_statuses`, only when the shared source has no record AND the student is
one of our demo students; 3 none = not submitted. A real student's own candidacy record (`study_candidacies.status`, `dismissed_at`) is read after the shared source. Config `candidacy.status_source` = `demo` | `shared_with_demo_fallback` | `shared`.
Any screen that shows a value resolved from the overlay says "Demo data" (the "Student candidacy" card of the appeal page for staff; the dry-run output). Commands: `candidacy:demo-softbound <studentID>` (writes only the overlay, demo students only),
`candidacy:check-status-source` (counts shared / overlay / candidacy record / none). The overlay is filled by `candidacy:demo-seed` and cleared by `candidacy:demo-clear`. No page lets CGS set Softbound.

**Expiry reminders** (`Support\CandidacyReminderEngine`, command `candidacy:send-reminders [--dry-run] [--date=YYYY-MM-DD]`, daily **08:00 Asia/Kuala_Lumpur**, after `candidacy:close-expired-appeals` at 07:20).
- Eligible: research students (Full-Time or Part-Time; Coursework excluded, `candidacy.reminders.programme_types`), with a current expiry date, today on or before it, and no Softbound submitted / completed / dismissed / inactive-or-deferred.
- An appeal in review (with supervisor, chair, CGS or dean) **pauses** reminders; they resume when the appeal is returned (variant C), rejected or approved. A pause records nothing.
- Milestones: expiry date minus 3, 2 and 1 calendar months, no month overflow (31 May minus 3 months = the last day of February). A milestone is sent by the first run on or after its date. If several are due and unsent, ONLY the latest is sent and the earlier ones are recorded as skipped.
- De-duplication: `candidacy_reminder_log`, unique on (candidacy, expiry date, milestone) (and on (demo student, expiry date, milestone)). Keyed by the expiry date, so a new expiry date starts a new cycle. Running twice or restarting never sends twice.
- Variants: **A** normal; **B** no further appeal possible (12 months used, or the Dean rejected the last appeal; `candidacy.reminders.send_when_appeal_blocked`); **C** an appeal is returned to the student.
  E-mail wording as agreed (subjects "Candidacy expiry reminder: n month(s) left (expires date)" and "Action needed: your extension appeal was returned (candidacy expires date)") plus a bell notification that carries a link.
- `--dry-run` writes and sends nothing. `--date` is allowed only with `--dry-run` or in the local environment.
- Demo students (Workstation demo profiles) are reminded by e-mail only (no login); their log rows are removed by `candidacy:demo-clear`. `candidacy.reminders.demo_students` switches this off (default: on except in production).
- The old `candidacy:remind` (still scheduled at 07:00 by the shared `routes/console.php`, which is not ours to edit) is now a no-op, so nothing is sent twice. No reminder log page and no 3-day/1-day reminders yet (section 13).
- Walkthrough with exact commands and real output: `docs/candidacy-reminder-demo.md`. Items to confirm with CGS: `docs/candidacy-cgs-confirmation-list.md`.
- Known gap (shared file): the shared notification feed only follows `application_id`, so clicking the reminder in the bell stays on the notification page; the link is in the notification data (`url`) and in the e-mail.

## Daily schedule (Asia/Kuala_Lumpur)

Two moments each morning. **07:30 REFRESH** changes data only; **08:00 REMINDERS** sends e-mail and bell notices. Every notice that a scheduled job owes a student goes out at 08:00:
the state change is made at 07:30 and its notice is queued with a delay until 08:00 the same day (`Support\ScheduledDelivery`). A run after 08:00 (manual, or a late scheduler) delivers at once;
page loads and people's own actions are never delayed. Running a job twice sends nothing twice (`reminder_sent_at`, `return_reminder_sent_at`, `candidacy_reminder_log`).

| Time | Command (defined in `ModuleProvider`) | What it does |
|---|---|---|
| 07:30 | `candidacy:close-expired-appeals` | closes returned appeals whose candidacy expired (student notice held until 08:00) |
| 07:30 | `workstation:refresh-daily` | key collection auto-cancel (day 7), vacate-period auto-release, candidacy-expiry / dismissal termination triggers (termination notice held until 08:00) |
| 08:00 | `workstation:send-reminders` | key collection reminder (day 3) and key return reminder |
| 08:00 | `candidacy:send-reminders` | the 3 / 2 / 1 month candidacy expiry reminders |

Older names still work: `workstation:process-releases` and `workstation:remind-locker-keys` run the refresh part and then the reminders part (inside the refresh context, so nothing they send
before 08:00 goes out early); `candidacy:remind` is a deprecated no-op. `routes/console.php` is shared and still lists the older entries (07:00 `candidacy:remind`, 07:30
`workstation:process-releases`, 08:00 `workstation:remind-locker-keys`); they are harmless duplicates and the owner of that file can delete them. Other modules' own jobs (e.g. `rpd:remind`,
`supervision:remind-stalled`) are not ours.
