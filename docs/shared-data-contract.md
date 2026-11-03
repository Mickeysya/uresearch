# Shared data contract: what the module reads, and what it owns

Written for: the owner of the shared (Core) database. You do not need to know the Workstation or Study Candidacy module to use this page. It answers two questions before anyone assigns the 180 student accounts:

1. Which shared fields does the module rely on? (section 1)
2. Which tables belong to the module and must not be overwritten? (section 2)

Nothing here changes data, schema or code. Part of the same set as [demo-scenario-spec.md](demo-scenario-spec.md) (what must be filled) and [verify-after-assignment.md](verify-after-assignment.md) (how to check it).

## 1. What the module needs from the shared database

The module **reads** these. Field names only.

### 1.1 Student accounts: table `users`

| Field | Used for |
|---|---|
| `id` | Links every module row to the account |
| `role` | Must be `student` for a student. Staff roles decide who sees which screen: `supervisor`, `chair`, `dean_pgr` (the one Dean of PGR), and the CGS roles (`manager_cgs`, `non_exec_cgs`, `senior_exec_cgs`, `senior_director_cgs`, `academic_exec`) |
| `name` | Shown on screens, e-mails and the memo |
| `email` | Where e-mails go; also how the module recognises its demo login accounts |
| `matric_no` | The student number: used in appeal numbers (`SCA-<matric_no>-01`), search, exports |
| `programme` | **Free text.** The module decides "research" or "coursework" and "full-time" or "part-time" by looking for the words `coursework` and `part-time` in this text (case-insensitive). It also maps the text to a department. An empty programme means "not eligible for a seat" |

The module does **not** read `users.department`, `users.faculty`, `users.supervisor_id` or `users.contact_no`.

### 1.2 Other shared tables

| Table | What the module does |
|---|---|
| `notifications` | **Writes** bell notifications for the user (standard Laravel notifications). Reads nothing |
| `applications`, `approval_history`, `application_documents` | Read **once**, by the one-off import command that has already been run. The module never writes these |
| Softbound / completed / inactive status table of another module | **Not connected yet.** The module's configuration (`config/candidacy.php`, key `status_source_table`) has empty slots for: table name, student-id column, status column, submitted-date column and the status values that mean submitted, completed and inactive. Until someone fills them in, the module shows a labelled demo overlay instead. This is the one item the module still needs from another owner |

### 1.3 What every assigned account also needs, in the module's own tables

An account in `users` is not enough to appear on the module's screens. For each of the 180 accounts, someone has to create these module-owned rows (the creation is part of the assignment, which this guide deliberately does not script):

| Module table | Fields | Why |
|---|---|---|
| `study_candidacies` | `student_id`, `department_id`, `programme_start_date`, `candidacy_expiry_date`, `original_expiry_date`, `status` (`active`, …), `extension_months_used` (0 to 12) | The seat eligibility rule and every reminder, appeal and dismissal rule read the expiry date and status from here |
| `student_genders` | `student_id`, `gender` (`male` or `female`) | Rooms are gender-specific; there is no gender in `users` |
| `candidacy_student_supervisors` | `student_id`, `staff_profile_id`, `kind` | The Supervisor / Programme Manager an appeal is routed to. An account without one cannot start an appeal ("Your Supervisor / Programme Manager is not recorded yet. Please contact CGS.") |

### 1.4 Rules the database already enforces (you cannot work around them)

- **One seat per student:** `workstation_requests.student_id_if_confirmed` is unique. A second confirmed seat for the same account is refused.
- **One holder per seat:** `workstation_requests.workstation_id_if_confirmed` is unique.
- **Extension months:** `study_candidacies.extension_months_used` must be between 0 and 12.
- A locker key row needs either a real student (`locker_keys.student_id`, which references `users`) or a demo student (`locker_keys.demo_profile_id`).

## 2. What the module owns and will not overwrite

### 2.1 Tables the module owns

Everything below is created by the module's own migrations. Nobody else should write to these.

| Area | Tables |
|---|---|
| Workstation seats | `workstations`, `workstation_locations`, `workstation_blocks`, `workstation_seat_counter` |
| Workstation people and keys | `workstation_requests`, `workstation_histories`, `workstation_pending_selections`, `workstation_termination_notices`, `locker_keys` |
| Study Candidacy | `study_candidacies`, `study_candidacy_reminders`, `candidacy_departments`, `candidacy_staff_profiles`, `candidacy_staff_assignments`, `candidacy_student_supervisors` |
| Appeals | `candidacy_appeals`, `candidacy_appeal_approvals`, `candidacy_appeal_events`, `candidacy_appeal_documents`, `candidacy_appeal_publications`, `candidacy_appeal_details` |
| Reminders, dismissal | `candidacy_reminder_log`, `candidacy_demo_student_statuses`, `candidacy_dismissals`, `candidacy_dismissal_exports`, `candidacy_dismissal_imports`, `candidacy_dismissal_import_rows` |
| Demo identities | `demo_student_profiles`, `student_genders` |
| Assistant | `assistant_logs` (no question or answer text is ever stored) |

Uploaded files live on the private disk: `storage/app/private/candidacy/appeals/…` (appeal documents and memos) and `storage/app/private/workstation/layouts/…` (room layout images). They are not under `public/`.

### 2.2 Shared tables the module never writes

`users`, `applications`, `approval_history`, `application_documents`. Two exceptions, both explicit: `notifications` is written only through Laravel's standard notification feature, and the optional command `workstation:demo-login` creates **one** student login account in `users` (it is a development helper; do not run it during the assignment).

### 2.3 Commands that must **not** be run during or after the assignment

These re-create or delete demo data and would undo the work:

`workstation:demo-seed`, `workstation:demo-clear`, `workstation:demo-login`, `candidacy:demo-seed`, `candidacy:demo-clear`, and `migrate:fresh` (it wipes the whole database).

Read-only or add-only commands that are safe: `candidacy:send-reminders --dry-run`, `workstation:refresh-daily --dry-run`, `candidacy:backfill-supervisors` (it only fills missing supervisor rows).

### 2.4 What the assignment touches in the module's tables

Only the **occupant** of an occupied seat changes. The seat, its room and block, its status, and the seat counter (currently 315) stay as they are. In practice that means the rows that name a student: `workstation_requests` (`student_id` or `demo_profile_id`), `locker_keys` (`student_id` or `demo_profile_id`) and, for the Study Candidacy side, `candidacy_appeals` (`student_id` or `demo_profile_id`), `candidacy_reminder_log` and `candidacy_demo_student_statuses`.

`workstation_histories` is **append-only** and keeps the student's name and number as text at the time of each action, so old history rows keep showing the demo names. That is deliberate.
