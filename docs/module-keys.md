# `module_type` registry

Every application type needs a unique key. It is stored in
`applications.module_type` on every row, so **it is permanent** — renaming one
means writing a data migration.

Add a row here in the same commit that registers the workflow.

| Key | Module | Owner | Status |
|---|---|---|---|
| `travel` | Travel | Norhanis | built |
| `ga_extension` | GA Extension | Nureen | built |
| `examiner_nomination` | Examiner Nomination | Hani | built |
| `publication` | Publication | Norhanis | planned |
| `claims_student` | Student Claims | Norhanis | planned |
| `rpd_appeal` | RPD Appeal / Extension | Norhanis | planned |
| `rpd_dismissal` | RPD Dismissal | Norhanis | planned |
| `attendance_appeal` | Attendance Appeal | Nureen | built |
| `supervision` | Supervisor Appointment | Nureen | built |
| `ga_certification` | GA/GRA Certification Letter | Nureen | built |
| `re_viva` | Re-examination Monitoring | Hani | planned |
| `hardbound_submission` | Hardbound Submission | Jason | planned |
| `hardbound_appeal` | Appeal Hardbound Submission | Jason | planned |
| `appointment_letter` | Appointment Letter & Report Management | Jason | planned |
| `workstation` | Workstation Management | Chloe | planned |
| `candidacy_reminder` | Study Candidacy Reminder | Chloe | planned · see overlap note |
| `candidacy_appeal` | Study Candidacy Appeal | Chloe | planned · see overlap note |
| `candidacy_dismissal` | Dismiss Exceeded Study Candidacy | Chloe | planned · see overlap note |
| `gra_application` | GRA Application + Stage Gates | Haziq | planned · see overlap note |
| `ga_application` | GA Application | Haziq | planned · see overlap note |

**Overlap note — do not claim these without agreeing first.** Chloe's three
candidacy keys duplicate the *shape* of Norhanis' `rpd_appeal` and
`rpd_dismissal` (different deadline, near-identical machinery), and Haziq's
two keys duplicate the *subject* of Nureen's already-built `ga_extension` and
`ga_certification`. See the overlap section in `TODO.md`.

## Stage keys in use

Stage keys only need to be unique **within one chain**, so reusing `supervisor`
across modules is fine and desirable — it keeps the vocabulary consistent.

| Key | Label | Role |
|---|---|---|
| `supervisor` | Lecturer/Supervisor | `supervisor` |
| `chair` | Chair of Department | `chair` |
| `cgs_review` | Non-Executive CGS | `non_exec_cgs` |
| `cgs_verify` | CGS Staff | `non_exec_cgs` |
| `dean` | Dean of PGR | `dean_pgr` |
| `senior_director` | Senior Director CGS | `senior_director_cgs` |
| `manager` | Manager CGS | `manager_cgs` |
| `academic_exec` | Academic Executive | `academic_exec` |
| `cgs_approve` | Senior Executive CGS | `senior_exec_cgs` |

## Actors named in scope documents that are not yet roles

Adding one is a single line in `App\Modules\Core\Support\Role` — but check
first whether an existing role already means the same person.

| Actor | Named in | Likely resolution |
|---|---|---|
| GRS Executive | `haziq.md` | new role, or map to `non_exec_cgs` |
| Research Centre | `haziq.md` | new role — conducts GA interviews |
| Programme Chair | `chloe.md` | probably the existing `chair` — confirm with CGS |
| Project Director | `norhanis.md` | may be a post-approval export, not a stage |
| Faculty | `norhanis.md`, `jason.md` | final approver on RPD dismissal — undecided |
