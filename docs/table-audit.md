# Database table audit

All 112 tables, checked 2026-11-08 against the code (who writes each one, who
reads it) and against `TODO.md`. Decisions agreed with Sharvin the same day.
Cite this in the report's data-model section.

## Why most tables are empty

On a database with only the base seed, 89 of 112 tables have no rows. That is
by design, not waste:

- **One table per feature.** Hard rule 2 gives every module its own
  `<module>_details` table (and its own logs, histories, child rows) instead of
  adding columns to the shared `users` / `applications` tables. Six people ×
  4–7 modules each = many tables.
- **A table fills when its feature is used.** No applications submitted means
  every `*_details`, `approval_history` and reminder table is empty.
  `./sync.sh --fresh` runs every module's demo seeder and fills most of them.
- **Ten are Laravel's own** (`cache`, `jobs`, `sessions`, `failed_jobs`,
  `migrations`, …) and fill themselves at runtime.

## Every table, by owner

| Owner | # | Tables |
|---|---:|---|
| Laravel / Core | 15 | `users` `applications` `approval_history` `application_documents` `departments` `notifications` `activity_log` `cache` `cache_locks` `failed_jobs` `job_batches` `jobs` `migrations` `password_reset_tokens` `sessions` |
| Norhanis | 8 | `travel_details` `travel_ta_plans` `travel_ta_plan_dates` `publication_details` `publication_authors` `publication_evaluations` `claims_details` `norhanis_stage_reminders` |
| Nureen | 11 | `attendance_records` `attendance_alerts` `attendance_risk_states` `attendance_appeal_details` `attendance_ingest_queue` `attendance_sync_runs` `ga_extension_details` `ga_certification_details` `ga_appointment_records` `supervision_details` `application_reminders` |
| Hani | 24 | `examiners` `examiner_expertise` `examiner_publications` `examiner_contact_history` `examiner_duplicate_dismissals` `examiner_email_proposals` `examiner_import_batches` `examiner_nominations` `examiner_review_items` `examiner_seat_history` `examiner_reminder_logs` `hani_sla_reminder_logs` `viva_details` `viva_eligibility` `viva_examiner_progress` `viva_softbound_checks` `viva_softbound_details` `viva_reminder_logs` `re_viva_details` `re_viva_evaluations` `re_viva_letter_sends` `re_viva_staff_documents` `re_viva_summaries` `re_viva_reminder_logs` |
| Jason | 7 | `hardbound_submission_details` `hardbound_appeal_details` `hardbound_signatures` `hardbound_examiner_signatures` `appointment_details` `appointment_examiners` `appointment_examiner_pool` |
| Chloe | 30 | `workstations` `workstation_blocks` `workstation_locations` `workstation_requests` `workstation_histories` `workstation_seat_counter` `workstation_termination_notices` `workstation_pending_selections` `locker_keys` `student_genders` `study_candidacies` `study_candidacy_reminders` `candidacy_reminder_log` `candidacy_appeals` `candidacy_appeal_details` `candidacy_appeal_approvals` `candidacy_appeal_documents` `candidacy_appeal_events` `candidacy_appeal_publications` `candidacy_dismissals` `candidacy_dismissal_exports` `candidacy_dismissal_imports` `candidacy_dismissal_import_rows` `candidacy_departments` `candidacy_staff_profiles` `candidacy_staff_assignments` `candidacy_student_supervisors` `candidacy_demo_student_statuses` `demo_student_profiles` `assistant_logs` |
| Haziq | 17 | `gra_application_details` `ga_application_details` `gra_ga_student_profiles` `gra_academic_qualifications` `gra_work_experiences` `gra_publications` `gra_terms_acceptances` `ga_terms_acceptances` `stage_gates` `stage_gate_rules` `stage_gate_termination_triggers` `candidature_appeals` `allowance_payment_logs` `allowance_payment_confirmations` `allowance_recommendation_notes` `application_correction_requests` `ta_hour_logs` |

## Findings and decisions

### Dead: nothing writes them, so drop them

Only four, all Chloe's. Everything else in every module has live code writing
to it.

| Table | Decision |
|---|---|
| `study_candidacy_reminders`, `candidacy_dismissals`, `candidacy_appeal_details`, `workstation_pending_selections` | **Drop**, in a new migration. Chloe acts: [`proposals/chloe-legacy-tables.md`](proposals/chloe-legacy-tables.md) |

### Duplicates

| # | What | Tables | Decision |
|---|---|---|---|
| A | GA and GRA "accepted the terms", identical columns | `ga_terms_acceptances`, `gra_terms_acceptances` (Haziq) | **Merge** into `terms_acceptances` + `scheme`. Haziq acts: [`proposals/haziq-terms-acceptances.md`](proposals/haziq-terms-acceptances.md) |
| B | Viva reminder logs, identical columns | `viva_reminder_logs`, `re_viva_reminder_logs` (Hani) | **Keep.** Each drives a different command's "already sent" check; merging saves one table and adds a type filter to every query in a working module |
| C | Chloe's own copies of Core data | `demo_student_profiles` (fake students outside `users`, linked by `demo_profile_id` from 8 tables), `candidacy_departments` (2nd department list), `candidacy_staff_profiles` + `candidacy_staff_assignments` (2nd record of Chairs/lecturers), `candidacy_student_supervisors` (2nd supervisor link) | **Keep for now; record as known duplication.** Built before real student accounts existed; there are now 180 (`docs/accounts.md`). Replacing them touches 8 tables, her seeders, import/export and most screens: too risky this close to submission |
| D | "Remind the approver about a stalled application", built three times | `application_reminders` (Nureen), `norhanis_stage_reminders` (Norhanis), `hani_sla_reminder_logs` (Hani) | **Keep; record.** Each covers only its own modules, so nobody is emailed twice. Unifying means a Core reminder table and rewriting three people's code |
| E | Approver signature stored twice | `hardbound_signatures` (Jason), `candidacy_staff_profiles.signature_path` (Chloe) | **Keep; record.** A Chair uploads the same signature in both modules. Unifying means a Core `user_signatures` table (not a `users` column: rule 2) |

### Look alike, but different on purpose

| Tables | Why both stay |
|---|---|
| `candidature_appeals` (Haziq) / `candidacy_appeals` (Chloe) | different processes: stage-gate appeal vs Study Candidacy extension appeal |
| `examiners` (Hani) / `appointment_examiner_pool` (Jason) | two lists on purpose; resolved in `TODO.md` (dependencies section) |
| `attendance_alerts` / `attendance_risk_states` (Nureen) | a log of alerts sent vs each student's current state |
| `publication_*` (Norhanis) / `gra_publications` (Haziq) / `examiner_publications` (Hani) / `candidacy_appeal_publications` (Chloe) | different things: a publication request, a GRA applicant's record, an examiner's record, an appeal's section C |
| `approval_history` / `examiner_seat_history` / `workstation_histories` / `examiner_contact_history` | each the audit trail of a different thing |

## For the report

The rewrite's one-table-per-module rule kept six people from conflicting with
each other, and its known cost is duplication *between* modules: C, D and E
above. Each is listed with the change that would remove it (move the shared
concept into Core). None causes wrong behaviour today.

How it was checked: every table mapped to the migration that creates it and
the model that uses it; every model searched for code that writes it
(`create`, `updateOrCreate`, relation `create`, `save`); every look-alike group
compared column by column; `TODO.md`'s cross-module overlaps read so settled
decisions were not reopened.
