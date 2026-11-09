# Shared database facts (read-only check)

Written for: Chloe, to plan the assignment of the shared student accounts.

Source: the real app database `uresearch` (MySQL), read with SELECT only, on 2026-11-07. Nothing was written, seeded, cleared or migrated. No passwords or secrets are shown. Code facts come from the merged code at `ca7d99e`.

**Headline: the database holds only 3 students, not the 180 shared real accounts.** The 180 are not in this database and not in any seeder or migration.

## 1. Students and users

| Role | Count | Created range |
|---|---|---|
| student | **3** | 2026-09-14 to 2026-10-26 |
| academic_exec | 12 | 2026-09-14 to 2026-11-07 |
| supervisor | 1 | 2026-09-14 |
| chair | 1 | 2026-09-14 |
| dean_pgr | 1 | 2026-09-14 |
| faculty | 1 | 2026-10-11 |
| manager_cgs, non_exec_cgs, senior_director_cgs, registry, admin | 1 each | 2026-09-14 |
| senior_exec_cgs | 1 | 2026-10-11 |
| **Total** | **25** | |

The 3 students are the test accounts `student@`, `student2@` and one personal test account (matric `26005007`). They are not the shared real accounts.

`users` columns: `id` bigint unsigned, `name` varchar(150), `email` varchar(150), `email_verified_at` timestamp null, `password` varchar(255), `role` varchar(50), `matric_no` varchar(30) null, `programme` varchar(100) null, `contact_no` varchar(30) null, `department` varchar(150) null, `faculty` varchar(100) null, `supervisor_id` bigint unsigned null, `remember_token` varchar(100) null, `created_at`, `updated_at` timestamp null.

## 2. Programme text (all distinct values for students)

| Programme | Students | In my catalogue (`config/candidacy.php`)? | "coursework" / "part-time" |
|---|---|---|---|
| `MSc Full-Time` | 1 | **No** (no catalogue name; no keyword pattern, so department is chosen by a stable hash) | neither |
| `PhD Part-Time` | 1 | **No** (same) | part-time (my rule needs the hyphenated word "part-time"; this text has it) |
| `Master of Science in Information Systems (Full-Time)` | 1 | **Yes.** The resolver strips a trailing "(Full-Time)", "(Part-Time)" or "(Coursework)" before matching (`CandidacyDepartmentResolver::catalogueEntry`), so this matches `Master of Science in Information Systems` (master_research, CIS) | neither |

Correction to `post-merge-impact-report.md` section 4a: a trailing `(Full-Time)` suffix is **not** a problem. Only texts with no catalogue match and no keyword match fall to the hash.

## 3. Supervisor link

- `users.supervisor_id`: bigint unsigned, nullable, foreign key to `users.id` (the same table; set null on delete).
- Students with it filled: **2**. NULL: **1**. Pointing to an existing user: **2 of 2**. Both point to the one user with role `supervisor`.

| matric_no | supervisor user id | supervisor role | supervisor name |
|---|---|---|---|
| 22001001 | 1 | supervisor | Dr. Aisyah Rahman |
| 22001002 | 1 | supervisor | Dr. Aisyah Rahman |
| 26005007 | NULL | none | none |

Only 3 rows exist, so there are no further samples.

## 4. Other shared columns that could give gender, start date, end date, department, level or mode

| Need | Where | Filled for the 3 students |
|---|---|---|
| Gender | not in `users`. `student_genders.gender` (mine). `demo_student_profiles.gender` (mine, demo) | 3 of 3 (mine) |
| Start / admission date | `study_candidacies.programme_start_date` (mine). `gra_ga_student_profiles.admission_date` (Haziq). `ga_extension_details.admission_date` (Nureen). `hardbound_submission_details.intake` (Jason, varchar 30). `viva_softbound_details.session` (Hani, varchar 9) | mine 3 of 3; Haziq 0 rows; Nureen 0 rows |
| End of candidacy | `study_candidacies.candidacy_expiry_date` and `original_expiry_date` (mine). `ga_extension_details.current_end_date` and `requested_new_end_date` (Nureen) | mine 3 of 3 |
| Department | `users.department` (text name), `users.faculty` (code). `study_candidacies.department_id` (mine) | `users.department` 2 of 3; `users.faculty` 2 of 3; mine 3 of 3 |
| Level (PhD / Master) | `gra_ga_student_profiles.level_of_study`, `ga_extension_details.level_of_study`, `ga_certification_details.level_of_study` and `study_level`, `viva_details.programme_level` (varchar 3). Nothing in `users` | the module tables have 0 rows for these students |
| Mode (full-time / part-time) | `gra_ga_student_profiles.mode_of_study`, `ga_extension_details.mode_of_study` and `study_type`, `ga_certification_details.mode_of_study`. Otherwise only the words in `users.programme` | 0 rows in these tables |

So, apart from my own tables, the shared data that exists for every student is only: name, email, `matric_no`, `programme` (text), and optionally `department`, `faculty`, `supervisor_id`.

## 5. Core `departments` against my `candidacy_departments`

Core `departments` columns are `id`, `name`, `faculty`, `is_active`, timestamps. **There is no `code` column.**

| Core id | Core name | Faculty | Matches my department? |
|---|---|---|---|
| 1 | Foundation in Business Management & Computing | CFS | none |
| 2 | Foundation in Engineering & Science | CFS | none |
| 3 | Chemical Engineering | FOE | `CHE` Chemical Engineering (exact name) |
| 4 | Civil & Environmental Engineering | FOE | `CEE` Civil & Environmental Engineering (exact name) |
| 5 | Electrical & Electronics Engineering | FOE | `EEE` Electrical & Electronic Engineering (near: "Electronics" vs "Electronic") |
| 6 | Integrated Engineering | FOE | none |
| 7 | Mechanical Engineering | FOE | `MEC` Mechanical Engineering (exact name) |
| 8 | Petroleum Engineering | FOE | `PET` Petroleum Engineering (exact name) |
| 9 | Applied Sciences | FSMC | `APS` Fundamental & Applied Sciences (different name, same area) |
| 10 | Computing | FSMC | `CIS` Computer & Information Sciences (different name, same area) |
| 11 | Geosciences | FSMC | `GEO` Geoscience (plural vs singular) |
| 12 | Management | FSMC | `MHU` Management & Humanities (different name, same area) |

- **Core departments with no match in mine:** Foundation in Business Management & Computing, Foundation in Engineering & Science, Integrated Engineering.
- **My departments with no exact-name match in Core:** `EEE`, `APS`, `CIS`, `GEO`, `MHU` (all have a close Core equivalent above). `CHE`, `CEE`, `MEC`, `PET` match exactly.
- Faculty differs in form and in one case in content: Core uses codes (`FOE`, `FSMC`, `CFS`); mine uses full names, and my `GEO` sits under "Faculty of Engineering" while Core puts Geosciences under `FSMC`.
- Mine have ids 3 and 94 to 101 (not a sequence) and no foreign key to Core.

## 6. Staff

| Role | Count | Email domain | Name (only one per role in the whole table) |
|---|---|---|---|
| supervisor | 1 | utp.edu.my | Dr. Aisyah Rahman |
| chair | 1 | utp.edu.my | Dr. Lim Wei Chun |
| dean_pgr | 1 | utp.edu.my | Prof. Dr. Hafiz Osman |
| manager_cgs, non_exec_cgs, senior_exec_cgs, senior_director_cgs | 1 each | utp.edu.my | |
| academic_exec | 12 | utp.edu.my | |
| faculty, registry, admin | 1 each | utp.edu.my | |

- **How Chair and Dean are identified:** by `users.role` (`chair`, `dean_pgr`). No other table.
- **Chair's department:** `users.department` = `Computing`, `users.faculty` = `FSMC` (text, not an id). The Dean has `department` = `PGR` and no faculty. The supervisor has `Computing` / `FSMC`.
- **My `candidacy_staff_profiles`:** 60 rows, **4 linked to a `users` row**, 56 not linked.

## 7. Tables in the merged code that record end of candidature, admission or study mode

| Table | Columns |
|---|---|
| `study_candidacies` (mine) | `programme_start_date`, `candidacy_expiry_date`, `original_expiry_date` |
| `gra_ga_student_profiles` (Haziq) | `admission_date`, `mode_of_study`, `level_of_study`, `programme`, `visa_expiry_date` |
| `ga_extension_details` (Nureen) | `admission_date`, `current_end_date`, `requested_new_end_date`, `mode_of_study`, `study_type`, `level_of_study` |
| `ga_certification_details` (Nureen) | `mode_of_study`, `level_of_study`, `study_level`, `semester` |
| `hardbound_submission_details` (Jason) | `intake`, `programme` |
| `viva_softbound_details` (Hani) | `session`, `field_of_study` |
| `viva_details` (Hani) | `programme_level` |
| `viva_eligibility` (Hani) | `within_candidacy_period`, `registered_current_semester` (self-declared or read from my table) |
| `stage_gate_rules` (Haziq) | `level_of_study` |

Most of these are filled per application by the student, so they are empty until a student applies.

## 8. What I could not find

- The 180 shared real student accounts: not in this database, and not in any seeder or migration.
- A programme catalogue or programme table in the shared database: none exists. Programme is only free text.
- A shared gender column, start date or candidacy end date on `users`: none.
- A shared code for departments: `departments` has no `code`, and `users.department` is text, so there is no id link to either department list.
- A supervisor table for students other than `users.supervisor_id`. There is no co-supervisor link on `users` (Haziq stores `co_supervisor_id` on his application details only).
- A separate table that marks Chair or Dean for a department: none. Only the role value and the `users.department` text.
- Real values for study mode and level on the 3 students: the module tables that hold them have 0 rows.
