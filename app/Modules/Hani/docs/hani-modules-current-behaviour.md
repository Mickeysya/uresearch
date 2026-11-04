# Hani's Modules: Current Behaviour

**Owner:** Nur Hani Sofia (22001418) · **Folder:** `app/Modules/Hani/`
**Snapshot taken:** 3 November 2026, branch `feature/viva-voce`.
**Updated:** 3 November 2026, after Prompt 2 on `feature/hani-automation`. See
`CHANGELOG.md` for the viva automation: one-page eligibility and notice,
system-checked candidacy, softbound System checks, examiner progress,
scheduling help, reminders, and step 5 (result and corrections), which is now
built. Sections 4.2 to 4.7 and 6 below describe the state BEFORE Prompt 2
where they disagree with the changelog.
**Purpose of this file:** an accurate description of how the features behave *right now*, taken from the code, to be turned into proper documentation. It describes what is built, not what is planned, except where marked **Not built**.

---

## 1. Overview

Hani owns three connected modules in UResearch 2.0 (Laravel 12, the CGS postgraduate portal for UTP):

| # | Module | Purpose | `module_type` key(s) |
|---|---|---|---|
| 1 | **Examiner Matching & Pool** | Supervisor nominates examiners; a six-stage approval chain; examiner pool with availability rules; conflict detection | `examiner_nomination` |
| 2 | **Viva Voce Assessment** | Eligibility checklist → Notice of Thesis Submission → softbound thesis (Form #019) → viva date | `viva_voce`, `viva_softbound` |
| 3 | **Re-viva Monitoring** | Tracks a re-examination cycle after a failed viva, with a 5-level outcome | `re_viva` |

The real-world order is Viva → (Examiner Matching runs inside the viva) → Re-viva (only if the viva outcome requires it).

### How the modules relate
- **Viva only *reads* Examiner Matching.** The viva date cannot be set until the student's latest examiner nomination has finished its chain. Nothing in the viva changes the nomination.
- **The DAC / FSMC-AC approvals** in the AE's official guideline are represented by the existing six-stage nomination chain. They are not recorded a second time in the viva.
- **Re-viva is independent.** It is logged manually by CGS staff. A viva result does not open a re-viva automatically. The viva result step is **not built yet** (see §6).
- **Appointment letters** belong to teammate Jason's module and are not linked to the viva.

### Platform rules these modules follow
- Approval chains are declared as `Stage` lists in a `WorkflowModule` class. Only Core's `WorkflowEngine` writes `applications.status` / `current_stage` (via `submit()`, `decide()`, `returnTo()`).
- Each module stores its own data in its own `*_details` tables; shared tables (`users`, `applications`, `approval_history`, `application_documents`) are never altered.
- Uploads go through Core's `DocumentStore` (private disk, random filenames).
- Academic Executive (AE) and Chair queues are **department-scoped**: an AE only sees and acts on students from their own department. The engine enforces this.

---

## 2. Roles involved

| Role | System role key | What they do in Hani's modules |
|---|---|---|
| Student | `student` | Viva checklist, notice, softbound; views their own progress. Sees examiner **status only, never names**. Re-viva: My Re-viva (outcome level, deadline, final thesis upload) and the digital Summary of Corrections. |
| Supervisor | `supervisor` | Nominates examiners for their own students; views their students' viva progress (read only) |
| Academic Executive (AE) | `academic_exec` | Main operator. Acknowledges notices, checks softbound theses, schedules vivas, first stage of the nomination chain, re-viva stages (Send to panel, re-evaluations, consolidation), outcomes and Corrections accepted, conflict detection, pending evaluations. Department-scoped. |
| Senior Executive CGS | `senior_exec_cgs` | Nomination chain: compiles all departments' lists, then finalises and releases the list in one Approve; maintains the examiner pool. Logs re-viva rounds; reads Re-viva Cycles; escalation point for an overdue panel or final thesis |
| Senior Director CGS | `senior_director_cgs` | Nomination chain: verifies, or returns the list to the department |
| Dean of PGR | `dean_pgr` | Nomination chain: approves, or returns the list to the department |
| Non-Executive CGS | `non_exec_cgs` | Reads the released Examiner Report (read-only). Re-viva: reads Re-viva Cycles and downloads the staff-only letters, UTP/CGS/024 reports and Confirmation of Correction (a return contact on the letter); no actions |
| DAC, FSMC-AC, examiners, chairman | not system users | Their decisions are represented by the chain; examiners and the chairman only receive emails |

---

## 3. Module 1: Examiner Matching & Pool

### 3.1 Examiner pool (`/examiners`, Senior Executive CGS)
- Two lists behind tabs: **Internal** and **External**. External examiners carry nine extra, optional columns: faculty approval reference, institution, sector (technical / research), UTP cluster, area of expertise, years of experience, MSc and PhD graduates supervised, first examination date.
- **Institution is required** for an external examiner. When an internal examiner is saved, the external fields are stripped server-side.
- Adding an examiner is a **two-step wizard** (Examiner → External record). For internal examiners the external step is hidden *and* disabled, so its fields are never submitted.
- An examiner can be toggled **Unavailable**, which needs a reason, or back to Active.

**Examiner state is derived, not stored** (`Examiner::state()`), so nothing has to run on a schedule:

| State | Condition |
|---|---|
| Unavailable | `is_active = false` (with `unavailable_reason`) |
| Assigned | `assigned_until` is in the future |
| On Gap | evaluated within the last **90 days** (`last_examination_date` + 90 days) |
| Available | none of the above |

Only **Available** examiners are eligible for nomination (`Examiner::scopeEligible`).

### 3.2 Nomination (`/examiner-nomination/new`, Supervisor)
- A supervisor files a nomination for **one of their own students** (enforced on submit).
- **Four seats:** Internal (main), Internal (backup), External (main), External (backup). The two mains are required.
- **Department rule:** internal examiners must come from the **student's own department**. The form hides other departments' internal examiners once a student is picked, and the server checks again.
- **Touchpoint 1 (individual eligibility):** only eligible (Available) examiners can be picked; checked in the dropdown and again on submit.
- Students cannot file nominations (`createRoute()` is null).

### 3.3 The approval chain (`ExaminerNominationWorkflow`)

| # | Stage key | Who | Decision word |
|---|---|---|---|
| 1 | `academic_exec` | Academic Executive (own department only) | approved |
| 2 | `cgs_compile` | Senior Executive CGS (merges all departments, checks clashes) | compiled |
| 3 | `senior_director` | Senior Director CGS | verified |
| 4 | `dean` | Dean of PGR | approved |
| 5 | `cgs_final` | Senior Executive CGS (holds the finalised report) | finalised |
| 6 | `cgs_release` | Senior Executive CGS, "Release to CGS operations" (recorded by the same Approve as stage 5) | released |

- **Return to department:** at stages 3 and 4, the Senior Director or Dean can send the list back to stage 1 with a **mandatory reason** (`WorkflowEngine::returnTo()`), instead of rejecting it. The candidate keeps one application and one audit trail, and the chain replays forward from the AE. The student and that department's AEs are emailed.
- **Examiner tie-up:** only when the chain **finishes** (released) are all seats marked Assigned for **180 days** (`assigned_until`). Nothing is tied up mid-chain.
- **Pending Evaluation** (`/examiner-nomination/pending-evaluation`, AE): marking an evaluation complete clears `assigned_until` and sets `last_examination_date` to today, which starts the 90-day gap.

### 3.4 Compiled report and conflict detection
- **Examiner Report** (`/examiner-nomination/report`): AE (own department only), Senior Exec CGS, Senior Director, Dean, Non-Exec CGS. Grouped faculty → department → candidate, with cross-department clashes flagged inline. Exportable as **xlsx or csv** (`/examiner-nomination/report/export?format=…`), with the same scoping.
- **Conflict Detection, touchpoint 2** (`/examiner-nomination/conflicts`, AE): groups nominations (pending and approved) by the examiner's faculty and flags any examiner nominated by **more than one department**. Read only; it never auto-rejects.

### 3.5 What the student sees
On **Track My Applications**, a student sees only the nomination's status: *"Examiner nomination in progress"*, *"Examiners appointed"*, or *"Examiner nomination not approved"*. Examiner names are shown to staff only.

---

## 4. Module 2: Viva Voce Assessment

### 4.1 End-to-end flow (MSc by Research and PhD; MSc by Coursework has no viva)

| Guideline step | Who | In UResearch |
|---|---|---|
| 1. Graduation requirements met (PhD: pass RCS) | Student | **Eligibility checklist** |
| 2. Notice of Thesis Submission, 3 months before softbound | Student | **Notice form** → creates the viva record (`viva_voce`) |
| 3. AE contacts supervisor about examiners | AE | AE **acknowledges** the notice → supervisor is emailed to nominate |
| 4. DAC endorsement → FSMC-AC submission | AE | Examiner Nomination chain (Module 1) |
| 5. Thesis signed by author, Sv, co-Sv, chair | Student | Offline (reminder on the softbound page) |
| 6. Softbound via CGS Online Form #019 | Student | **Softbound form** → `viva_softbound`, checked by the AE |
| 7. FSMC-AC approves examiners, appointment letters | FSMC-AC | Nomination chain finishing; letters are Jason's module |
| 8. AE sets viva date, sends calendar | AE | **Viva Scheduling** with `.ics` invitation |
| 9. Student attends viva | Student | Shown on progress panel and calendar |
| 10. AE shares viva report (~1 week after) | AE | **Not built** |

### 4.2 Eligibility checklist (`/viva/eligibility`, Student)
- Self-declared checkboxes from the UTP Postgraduate Handbook 2025. The programme level is read from `users.programme` (e.g. "MSc Full-Time", "PhD Part-Time"); full-time vs part-time comes from the same field.

| Check | MSc (by Research) | PhD |
|---|---|---|
| Research Methodology passed | ✓ | ✓ |
| Publication | 1 article accepted in a Scopus/WoS indexed journal | 1 article in a Q1/Q2 WoS journal, **or** 2 articles in WoS/ERA or Scopus |
| Research Completion Seminar (RCS) passed | not required | ✓ |
| Registered for the current semester | ✓ | ✓ |
| No outstanding fees (incl. Examination & Viva Voce fee) | ✓ | ✓ |
| Within candidacy period | FT 48 / PT 72 months | FT 96 / PT 120 months |

- **MSc by Coursework**, or a blank or unrecognised programme: the page explains the viva does not apply, and saving is refused.
- **Save Progress** stores the ticks. **Submit Notice of Thesis Submission** unlocks (client-side) only when every item is ticked, and the server checks again.
- Once a notice is filed, the checklist is **locked**.
- `verified_by_id` / `verified_at` columns exist for a future "verified by AE" step; nothing uses them yet.
- This page is also the student's **viva home**: once a notice exists, it shows a progress panel (notice, examiner nomination status, softbound, viva date) and a **Submit / Resubmit Softbound Thesis** button when that's the next step.

### 4.3 Notice of Thesis Submission (`/viva/notice`, Student)
- **Server-side guard:** redirects to the checklist if not every item is ticked; redirects to the existing notice if one is already pending or acknowledged. A rejected notice allows a new one.
- Fields: thesis title; expected softbound submission date, which must be **at least 3 months from today**; publication proof (**PDF only, max 5 MB**). Submit stays disabled until a valid PDF is chosen.
- Creates a `viva_voce` application with one stage:

| Stage key | Who | Decision word | Queue title |
|---|---|---|---|
| `notice_received` | Academic Executive | acknowledged | Notices Received |

- The module's display label is **"Notice of Thesis Submission"** (so the engine's "fully approved" email reads as the *notice* being complete, not the whole viva). In the student sidebar it appears as **"Viva"** (see §8).
- **When the AE acknowledges:** the student gets the engine's progress email, and the student's **supervisor is emailed and notified in-app** to nominate examiners, with a link to Nominate Examiners. The AE's confirmation names the supervisor, or warns if none is recorded.

### 4.4 Softbound thesis: CGS Online Form #019 (`/viva/softbound`, Student)
- Only available once the notice is **acknowledged**. One attempt at a time; none after one is verified. A returned attempt allows a new one.
- Kept as **its own application** (`viva_softbound`), linked to the notice by `viva_application_id`. It is deliberately not a stage on the notice: the shared queue's bulk-approve calls the engine directly, so a stage waiting for a student upload could be approved with nothing uploaded.
- A **4-step wizard** mirroring the real form (UTP/CGS/V3/VV/019):

| Section | Fields |
|---|---|
| 1. Student Details | Matric ID, name, department, programme **shown from the profile** (not re-typed). Field of study, contact number (pre-filled from the profile), personal email. |
| 2. Submission Details | Session (January to December), year (last / this / next), thesis title (pre-filled from the notice). Turnitin similarity %, **≤ 25%**. Word count, **MSc ≤ 50,000 / PhD ≤ 100,000**. Uploads, PDF only: Thesis Format Confirmation Form (10 MB), Thesis (**50 MB**), Turnitin Report (10 MB), and **PhD only**: RCS Correction Confirmation Form (10 MB), RCS Correction Summary (10 MB) |
| 3. Approval & Notifications | Supervisor and AE **shown automatically** from the profile and department. Co-supervisor email, optional, must end in `@utp.edu.my`. |
| 4. Agreement | The form's declaration (must be accepted) |

- Differences from the paper/Microsoft Form: similarity % and word count are **extra** (they let the system enforce the Handbook limits); MSc students are **not shown** the RCS uploads (the real form makes them upload blanks); the thesis limit is **50 MB, not 100 MB** (PHP accepts 100 MB per request in total and five files travel together).

| Stage key | Who | Decision word | Queue title |
|---|---|---|---|
| `softbound_check` | Academic Executive | verified | Theses to Check |

- **AE queue card** shows thesis title, session/year, field of study, similarity %, word count, contact details, co-supervisor, the student's **examiner nomination status**, and a **warning if filed less than 3 months after the notice**. It's a warning, not a block; the AE keeps discretion.
- **Approve** = verified. **Reject** = **returned to the student**, which needs a reason; a return without remarks is refused with an error. That attempt closes, the notice stays acknowledged, and the student resubmits.

### 4.5 Viva scheduling (`/viva/scheduling`, AE)
- Not a workflow stage: setting a date changes no status, and the date can be moved.
- The AE sees their department's acknowledged notices in three groups:
  - **Ready to Schedule:** softbound verified **and** the latest examiner nomination approved (released). Only these can be scheduled. The server checks again.
  - **Scheduled:** with Reschedule.
  - **Waiting:** shows exactly what is missing (e.g. "Examiner nomination not started").
- **Set Date form:** date (not in the past), start time, venue or online link, chairman's name and email, co-supervisor name and email (optional; both or neither; the email is pre-filled from Form #019). The form shows the panel (internal and external main examiners) and the supervisor.
- **On save:** an email with a **`.ics` calendar file** goes to the student, the supervisor, both main examiners, the chairman and the co-supervisor. Students and supervisors also get an in-app notification; examiners and the chairman (not users) get email only. Duplicate addresses are sent once.
- **Rescheduling** sends a "Viva rescheduled" email. The calendar event keeps the same ID and a higher sequence number, so calendar apps **move** the event instead of adding a second one. Event length: 2 hours; times converted from Asia/Kuala_Lumpur to UTC.
- The viva date appears in UResearch's **Calendar** for the student, their supervisor, and the AE of that department.
- Only the AE of the student's own department can open or save a schedule (403 otherwise).

### 4.6 Supervisor view (`/viva/my-students`, Supervisor)
Read only: each of the supervisor's students who has a notice, showing notice status, examiner nomination status, softbound status, and viva date and venue.

### 4.7 Viva notifications summary

| Event | Who is notified | Channel |
|---|---|---|
| Notice submitted | Student (receipt); **AE(s) of the student's department** | Email + in-app |
| Notice acknowledged | Student (engine email); **Supervisor** ("please nominate examiners") | Email + in-app |
| Softbound submitted / resubmitted | Student (receipt); **AE(s) of the department** ("New / Resubmitted softbound thesis") | Email + in-app |
| Softbound returned | Student (engine email + an explanation quoting the AE's reason, saying the viva is still open) | Email + in-app |
| Softbound verified | Student (engine email) | Email + in-app |
| Viva scheduled / rescheduled | Student, supervisor (email + in-app); examiners, chairman, co-supervisor (email) | with `.ics` |

All notifications are queued and sent **after** the database commit; a mail failure never undoes the student's or AE's action. In-app notifications raise the red unread count on the sidebar's **Notification** link. For staff, clicking an in-app notification keeps them on the Notification page (a Core behaviour), so the AE emails include a direct **"Open your queue"** link.

---

## 5. Module 3: Re-viva Monitoring

Rebuilt around CGS practice in Prompt 3 (2026-11-04). The four stages and
the module key are unchanged; everything else is module-side state.

**Logging** (`/re-viva/new`, Senior Executive only):
- "Expected resubmissions" lists category-4 vivas and level-4 rounds with no round logged, earliest window close first. `?student=` pre-selects (from the notification).
- The round links the viva (`viva_application_id`) and stores its resubmission window: round 1 from `viva_details`; round N+1 from the level-4 outcome date (`hani.reviva.next_round_window_months`, [0, 6], provisional); a legacy student with no viva result needs the viva result date.
- Outside the window: logged anyway, with a warning and a "Logged outside window" flag.
- Uploads: corrected thesis and Summary of Corrections (PDF, DocumentStore), or the student's checked digital summary.
- The main examiners stay held for the round.

**Stages** (AE, department-scoped; `DecidesOneAtATime`):

| # | Stage key | What happens |
|---|---|---|
| 1 | `report_sent` | **Send to panel**: one "Correction of Master's/Doctoral Thesis" letter (Dompdf) per current main examiner, stored staff-only, emailed with a signed link to the corrected thesis and the Summary of Corrections (works for `hani.reviva.link_days` from when the email is built; tampered → 403, expired → 410 page with the CGS contacts); flagged emails skipped with the reason; the stage completes in the same action. A plain Approve is refused. |
| 2 | `under_panel_review` | The card counts working days with the panel. `re-viva:monitor`: 20 working days → AE reminded; 25 → Senior Exec told (once each). **Resend letter** (AE of the department or Senior Exec): the same letter, a fresh 35-day link, logged; flagged emails refused with the reason; "last sent" shown per examiner. |
| 3 | `report_received` | AE records each main examiner's UTP/CGS/024: category 1–4, "viva again" for a fail, signed form (staff-only), optional ratings. Completes only with both. |
| 4 | `consolidation_scheduled` | Divergence alert when the two reports map to different levels. |

**Outcome** (`/re-viva/outcomes`, AE, own department): the system suggests the level when the examiners agree, otherwise "Consolidation needed"; remarks are required to consolidate or override.

| Level | From the reports | Effect |
|---|---|---|
| 1 | Pass | Final thesis within 1 month; mains' evaluation complete (gap starts) |
| 2 | Minor corrections | Within 3 months; complete |
| 3 | Major corrections | Within 6 months; complete |
| 4 | Fail + viva again YES | Oral examination: Senior Exec asked to log round N+1; mains stay held until it is logged or its window closes (then freed with no gap) |
| 5 | Fail + viva again NO | Final; complete |

The student and supervisor are told the level and the next step, never an examiner's name or individual recommendation.

**Levels 1–3:** the student uploads the final thesis on My Re-viva; the AE clicks **Corrections accepted**, which generates the pre-filled **Confirmation of Correction** (examiners 1 and 2, chairman) for signatures, staff-only.

**Re-viva Cycles** (`/re-viva/cycles`, Senior Exec and Non-Exec, read-only): every round grouped (in progress, awaiting outcome, final thesis due, next round to log, closed) with tracker, window, deadlines, re-evaluations, staff files, overdue flags and a rule-based **risk score** (0–100, factors shown, sections sorted by it).

**`re-viva:monitor`** (daily, `--dry-run`): panel limits; student reminders 30 and 7 days before the window closes and before the final-thesis deadline; final thesis overdue → AE and Senior Exec once; level-4 window closed → mains freed, AE and Senior Exec once.

**Old rows:** rounds logged before Prompt 3 keep `correction_deadline` (+6 months) and `hardbound_deadline` (+12 months) and still show them. New rows use the window, and `correction_deadline` holds the final-thesis deadline after the outcome.

---

## 6. Step 5 of the viva (built in Prompt 2; this was the plan)
- AE uploads the **viva report** received from the chairman and shares it with the student (~1 week after the viva).
- AE records the **viva result category** (1 = final thesis within 1 month; 2 = up to 3 months; 3 = 3–6 months; 4 = 6–12 months and re-submit for viva, i.e. hand-off to Re-viva; 5 = dismissed).
- Corrections upload after the viva.
- Until then, the viva tracker's last step ("Result & Corrections") never completes.

---

## 7. Step trackers

A reusable tracker (`Hani/Support/ModuleTracker.php` + `Hani/Resources/views/partials/module_stepper.blade.php`) uses the same markup and CSS as Core's stepper. Every step's state comes from **stored status**, never from which page is open:
- ✓ **completed:** links to its read-only tracking page where one exists
- **current:** highlighted, with a **"What to do now"** line underneath
- **upcoming:** greyed, not clickable
- ✕ **rejected:** used for re-viva level 4 and 5

The current step is the first step not yet done; a later step that is already done stays ticked (e.g. examiners appointed before the softbound is verified).

| Where | Steps |
|---|---|
| Student viva home, notice page, softbound form | Notice of Thesis Submission → Softbound Thesis → Examiner Appointment *(status only)* → Viva Session → Result & Corrections |
| AE's Set Viva Date form | Same viva steps for that student (without the "What to do now" line) |
| Each card in the Examiner Nomination queue | The six real chain stages, with "With <stage>" / "Released…" / "Not approved…" |
| Each card in the Re-viva queue, Re-viva Outcomes, Re-viva Cycles and My Re-viva | **"Re-viva round N"**: Resubmission → Panel Review → Report & Consolidation → Re-viva Result. Levels 1–3: "passed with corrections, due by …" (then "final thesis accepted"). Level 4: "CGS logs re-viva round N+1…". Level 5: "final outcome; no further action". |

Long list pages (the AE's notice and softbound queues, the supervisor's progress table) have no per-row tracker, to avoid extra database queries.

---

## 8. Sidebar entries

The sidebar is generated by Core from what each module declares.

| Role | Entries from Hani's modules |
|---|---|
| Student | **My Application → Viva** (one entry for the whole viva; links to `/viva/eligibility`; highlighted on every `viva.*` page). The notice and softbound pages are reached from the tracker and buttons; their URLs still work. |
| Supervisor | Actions: Nominate Examiners · Students' Viva Progress |
| AE | Pending My Action: Examiner Nomination · Re-viva Monitoring (4 stages) · Notice of Thesis Submission · Softbound Thesis. Actions: Pending Evaluation · Conflict Detection · Examiner Report · Re-viva Outcomes · Viva Scheduling |
| Senior Exec CGS / Senior Director / Dean | Pending My Action: Examiner Nomination (their stages). Actions: Examiner Report |
| Senior Exec CGS (re-viva) | Log Re-viva Submission · Re-viva Cycles |
| Non-Exec CGS | Examiner Report (read-only) · Re-viva Cycles (read-only) |
| Student with a re-viva | My Re-viva (once they have a round or a category-4 viva) |

The single "Viva" entry relies on a small, backward-compatible Core change: Core's student sidebar uses a module's optional `navLabel()` / `navPattern()` if defined (only `VivaVoceWorkflow` defines them).

---

## 9. Data model (Hani's own tables)

| Table | One row per | Key columns |
|---|---|---|
| `examiners` | examiner | name, email, department, faculty, type (internal/external), is_active, unavailable_reason, last_examination_date, assigned_until, external-only fields |
| `examiner_nominations` | nomination application | application_id, internal_main_id, internal_backup_id, external_main_id, external_backup_id, thesis_title, notes |
| `viva_eligibility` | student | student_id (unique), research_methodology_passed, publication_accepted, rcs_passed, registered_current_semester, no_outstanding_fees, within_candidacy_period, verified_by_id, verified_at |
| `viva_details` | notice application | application_id, programme_level (snapshot), thesis_title, expected_softbound_date, viva_at, venue, chair_name, chair_email, co_supervisor_name, co_supervisor_email, scheduled_by_id, schedule_sequence |
| `viva_softbound_details` | softbound attempt | application_id, viva_application_id, field_of_study, contact_number, personal_email, session, year, thesis_title, similarity_percent, word_count, co_supervisor_email, declaration_accepted |
| `re_viva_details` | re-viva round | application_id, cycle_number, previous_cycle_id, viva_application_id, resubmission_at, resubmission_opens_at/closes_at, viva_result_date, logged_outside_window, sent_to_panel_at, correction_deadline (final thesis), hardbound_deadline (old rows), outcome_level, suggested_level, outcome_recorded_at, final_thesis_submitted_at, corrections_accepted_at |
| `re_viva_evaluations` | round and main seat | examiner_id, category 1–4, viva_again, ratings, report_document_id |
| `re_viva_staff_documents` | staff-only file | kind (correction_letter / re_evaluation_report / confirmation_of_correction), seat, examiner_id, private path, emailed_at, email_skipped_reason |
| `re_viva_reminder_logs` | alert sent | re_viva_detail_id, kind, reference (unique) |
| `re_viva_summaries` | digital Summary of Corrections | student_id, rows, thesis_pages, submitted_at, used_by_detail_id |

**Document types** stored via `DocumentStore`: Publication Proof; Thesis Format Confirmation Form; Softbound Thesis; Turnitin Report; RCS Correction Confirmation Form; RCS Correction Summary; re-viva thesis. Softbound attempts filed before the Form #019 rework used "Similarity Report".

---

## 10. Known limitations

1. ~~**Bulk actions skip module checks.**~~ Fixed in Prompt 2: both viva
   workflows implement `DecidesOneAtATime`. Previously: The shared queue's "Approve / Reject selected" calls the engine directly. For Hani's modules this means a bulk **reject** of softbound theses skips the mandatory reason and the "returned" email, and a bulk **acknowledge** of notices skips the supervisor email. One-at-a-time decisions work fully. The fix needs a Core hook (the same gap affects Jason's signature gate).
2. **No live pop-up** for new work. The AE gets an email and the red unread count, which updates on the next page load.
3. **Search box width** in every queue is narrower than intended (a one-line Core CSS bug, `:first-child` vs `:first-of-type`); not fixed, pending the team.
4. **Candidacy end and fees are self-declared**; there is no data source for them in UResearch.
5. **Chairman and co-supervisor are typed in** by the AE, because neither is stored anywhere else in the system.
6. ~~The Re-viva Outcomes page's subtitle said level 4 lets the student open another cycle.~~ Fixed in Prompt 3: CGS logs the next round.

## 11. Open questions (pending the AE)
- Does MSc go through faculty-level compilation / Dean endorsement, or straight from DAC to FSMC-AC? (The current chain applies to both MSc and PhD.)
- PhD examiner panel is assumed to be the same as MSc (1 internal + 1 external + chairman, each examiner with a backup); to be confirmed.

---

## 12. Tests

`tests/Feature/Hani/`: 7 files, run with `php artisan test --filter=Hani` (inside the `uresearch-app` container).

| File | Covers |
|---|---|
| `ExaminerNominationTest` | four-seat panel, internal department rule, seat types, tie-up only when the chain finishes, return to department (reason required, only at allowed stages), report scoping |
| `ExaminerPoolTest` | internal/external lists, external-field stripping, institution required, two-step add form |
| `ReVivaTest` | one open cycle at a time, level-4 loop-back, the stepper, the blocked-student picker |
| `VivaEligibilityTest` | checklist gating, MSc vs PhD items, coursework excluded, notice rules, one open notice, notifications, the single "Viva" sidebar entry |
| `VivaSoftboundTest` | waits for an acknowledged notice, Form #019 limits, PhD RCS uploads, return-and-resubmit, AE notifications (own department only) |
| `VivaScheduleTest` | no date until the nomination is approved, invitations to the whole panel, `.ics` time and escaping, reschedule sequence, department scoping, supervisor prompt on acknowledgement, students never see examiner names |
| `ModuleTrackerTest` | re-viva round and outcome rules, trackers rendering on the AE's re-viva and nomination queues |

Full suite at the time of writing: **217 tests passing**.

## 13. Commit state at the time of writing

Committed on `feature/viva-voce` (not pushed): eligibility + notice; softbound; scheduling + Form #019 rework; AE and supervisor notifications.

**Not yet committed:** the step trackers, the student examiner-name fix, and the single "Viva" sidebar entry (including the two-line Core change in `sidebar-student-nav.blade.php` and `sidebar.blade.php`).
