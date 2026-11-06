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
| `rpd_appeal` | RPD Appeal / Extension | — | **retired** (removed 2026-10-30) · do not reuse |
| `rpd_dismissal` | RPD Dismissal | — | **retired** (removed 2026-10-30) · do not reuse |
| `attendance_appeal` | Attendance Appeal | Nureen | built |
| `supervision` | Supervisor Appointment | Nureen | built |
| `ga_certification` | GA/GRA Certification Letter | Nureen | built |
| `re_viva` | Re-examination Monitoring | Hani | built |
| `viva_voce` | Viva Voce Assessment (Notice of Thesis Submission) | Hani | in progress |
| `viva_softbound` | Softbound Thesis (Form #019) | Hani | in progress |
| `hardbound_submission` | Hardbound Submission | Jason | built |
| `hardbound_appeal` | Appeal Hardbound Submission | Jason | built |
| `appointment_letter` | Appointment Letter & Report Management | Jason | built |
| `workstation` | Workstation Management | Chloe | built · not a workflow, see `app/Modules/Chloe/README.md` |
| `candidacy_reminder` | Study Candidacy Reminder | Chloe | built · not application-backed (own tables + scheduled command), see `app/Modules/Chloe/README.md` |
| `candidacy_appeal` | Study Candidacy Appeal | Chloe | built · own `study_candidacies` table, not shared with Norhanis' RPD trio — see overlap note |
| `candidacy_dismissal` | Dismiss Exceeded Study Candidacy | Chloe | built · not application-backed (own tables + scheduled command), see `app/Modules/Chloe/README.md` |
| `gra_application` | GRA Application + Extension + Stage Gates | Haziq | built |
| `ga_application` | GA Application | Haziq | built |

**Overlap note — do not claim these without agreeing first.** Chloe's three
candidacy keys once duplicated the *shape* of the retired `rpd_appeal` and
`rpd_dismissal` (RPD was removed from Norhanis' scope on 2026-10-30). See the
overlap section in `TODO.md`.

**Resolved — Haziq/Nureen overlap (2026-09-21):** GRA Extension is not a
separate `module_type`. It reuses `gra_application` with
`gra_application_details.application_type = 'extension'` and
`parent_application_id` pointing at the original appointment, routed through
the same four-stage chain — no new key, no shared table with Nureen's
modules. GA Extension stays entirely Nureen's `ga_extension`; Haziq's
`ga_application` covers only the new-application lifecycle, never extension.

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
| `admin_verify` / `admin_eligibility` / `admin_final` | Admin | `admin` |
| `research_centre_interview` | Research Centre | `research_centre` (not in `Support\Role` — see below) |

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
| Programme Chair | `chloe.md` | reused as the existing `chair` role in the built Study Candidacy Appeal chain (2026-09-21) — CGS confirmation is still outstanding, flag if that turns out wrong |
| Project Director | `norhanis.md` | may be a post-approval export, not a stage |

**Resolved.** `Faculty` is now a role (`App\Modules\Core\Support\Role::FACULTY`),
first claimed by the RPD dismissal chain, which has since been removed; no module
uses it at present. Jason's or Chloe's modules may reuse it.
Seeded as `faculty@utp.edu.my`.

**Resolved — Haziq's roles (2026-09-21):** GRS Exec was dropped; his chain
uses only `admin`, `non_exec_cgs`-team, `supervisor`, `senior_director_cgs`
and `student`, all already in `Support\Role`. Research Centre is a real new
role (conducts the GA interview) but is declared in
`App\Modules\Haziq\Support\Roles::RESEARCH_CENTRE` rather than in
`Support\Role` — `Role` is a plain VARCHAR, not an enum, so `EnsureRole` and
`WorkflowEngine` work against it as a string with no Core edit required. If
another module ever needs this role too, promote it into `Support\Role` then.
