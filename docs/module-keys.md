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
| `publication` | Publication | Norhanis | built |
| `claims_student` | Student Claims | Norhanis | built |
| `rpd_appeal` | RPD Appeal / Extension | Norhanis | built |
| `rpd_dismissal` | RPD Dismissal | Norhanis | built |
| `attendance_appeal` | Attendance Appeal | Nureen | built |
| `supervision` | Supervisor Appointment | Nureen | built |
| `ga_certification` | GA/GRA Certification Letter | Nureen | built |
| `re_viva` | Re-examination Monitoring | Hani | built |
| `hardbound_submission` | Hardbound Submission | Jason | built |
| `hardbound_appeal` | Appeal Hardbound Submission | Jason | built |
| `appointment_letter` | Appointment Letter & Report Management | Jason | built |
| `workstation` | Workstation Management | Chloe | built · not a workflow, see `app/Modules/Chloe/README.md` |
| `candidacy_reminder` | Study Candidacy Reminder | Chloe | built · not application-backed (own tables + scheduled command), see `app/Modules/Chloe/README.md` |
| `candidacy_appeal` | Study Candidacy Appeal | Chloe | built · own `study_candidacies` table, not shared with Norhanis' RPD trio — see overlap note |
| `candidacy_dismissal` | Dismiss Exceeded Study Candidacy | Chloe | built · not application-backed (own tables + scheduled command), see `app/Modules/Chloe/README.md` |
| `gra_application` | GRA Application + Stage Gates | Haziq | planned · see overlap note |
| `ga_application` | GA Application | Haziq | planned · see overlap note |

**Overlap note.** Chloe's three candidacy keys duplicate the *shape* of
Norhanis' `rpd_appeal` and `rpd_dismissal` (different deadline, near-identical
machinery), and Haziq's two keys duplicate the *subject* of Nureen's
already-built `ga_extension` and `ga_certification`. See the overlap section
in `TODO.md`.

**Update, 2026-09-21 (post-merge of `develop`):** both sides of this overlap
are now actually built, independently, exactly as the note above warned —
Norhanis' RPD trio landed on `develop` with its own `candidacies` table
(`app/Modules/Norhanis/Models/Candidacy.php`) tracking the RPD milestone,
while Chloe's candidacy trio (this branch) tracks study-candidacy expiry in
its own `study_candidacies` table. Nothing was unified, because neither side
knew the other had shipped until this merge. The two are structurally
similar (reminder cadence, an appeal chain with a cumulative-extension cap,
a dismissal list) but track different deadlines for the same student, in
two separate tables with no shared code. **This still needs a team decision**
— whether that's acceptable as two intentionally-separate concerns, or
worth consolidating later — flagged here rather than picked unilaterally
while resolving this merge.

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
| `cgs_prep` | Non-Executive CGS (prepares the appointment pack) | `non_exec_cgs` |
| `faculty` | Faculty | `faculty` |
| `registry` | Registry | `registry` |
| `report_sent` | Report Sent | `academic_exec` |
| `under_panel_review` | Under Panel Review | `academic_exec` |
| `report_received` | Report Received | `academic_exec` |
| `consolidation_scheduled` | Consolidation Scheduled | `academic_exec` |

The last four are Hani's re-viva monitoring stepper. They read as statuses
rather than approvers because that is what they are: one actor advancing a
case through four states, expressed as a chain so the engine still owns the
position.

Checked against `grep` over every `Workflows/*.php` on 2026-09-17, so this
table is the full set, not a sample.

## Actors named in scope documents that are not yet roles

Adding one is a single line in `App\Modules\Core\Support\Role` — but check
first whether an existing role already means the same person.

| Actor | Named in | Likely resolution |
|---|---|---|
| GRS Executive | `haziq.md` | new role, or map to `non_exec_cgs` |
| Research Centre | `haziq.md` | new role — conducts GA interviews |
| Programme Chair | `chloe.md` | reused as the existing `chair` role in the built Study Candidacy Appeal chain (2026-09-21) — CGS confirmation is still outstanding, flag if that turns out wrong |
| Project Director | `norhanis.md` | may be a post-approval export, not a stage |

**Resolved.** `Faculty` is now a role (`App\Modules\Core\Support\Role::FACULTY`),
claimed by `RpdDismissalWorkflow` as the final approver between the Dean's
endorsement and the Registry's termination email. Jason's modules may reuse it.
Seeded as `faculty@utp.edu.my`.
