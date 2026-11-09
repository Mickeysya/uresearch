# Proposal for Chloe: drop four leftover tables

Agreed with Sharvin on 2026-11-08 during a table audit (all 112 tables
checked; every other one has live code writing to it). These four are in
your folder, so the change is yours to make on your branch.

**How:** one new migration in `app/Modules/Chloe/Database/Migrations/`
(e.g. `2026_11_09_000100_drop_legacy_candidacy_and_workstation_tables.php`)
that drops them. Do **not** delete or edit the original create migrations:
everyone has already run them, and `./sync.sh` will run the drop on every
machine after a pull.

| Table | Why it's dead | Delete with it |
|---|---|---|
| `study_candidacy_reminders` | `candidacy:remind` is deprecated and writes nothing; `candidacy_reminder_log` replaced it | `Models/StudyCandidacyReminder.php`, `StudyCandidacy::reminders()`, `Console/Commands/SendCandidacyReminders.php` |
| `candidacy_dismissals` | nothing reads or writes it; `candidacy_dismissal_imports` replaced it | `Models/CandidacyDismissal.php`, `Console/Commands/GenerateCandidacyDismissals.php` (deprecated no-op), the path in `tests/Feature/CandidacyWordingRound4Test.php:61` |
| `candidacy_appeal_details` | the engine-based appeal before 2026-11-01; new appeals go to `candidacy_appeals`; nothing writes it, only the backfill reads it, and there is no production data to backfill | `Models/CandidacyAppealDetail.php`, `Console/Commands/BackfillCandidacyAppeals.php`, its reads in `Workflows/CandidacyAppealWorkflow.php`, `StudentAppealController.php`, `views/candidacy/appeal/_detail.blade.php` / `_sections.blade.php`, and the legacy-only columns on `candidacy_appeal_publications` |
| `workstation_pending_selections` | its rows were created by the emailed confirm-token flow, removed in `f92da4f` (2026-11-03, in-page seat selection); now only read, so always empty | `Models/WorkstationPendingSelection.php`, `Console/Commands/ReleaseExpiredWorkstationSelections.php`, the checks in `WorkstationController`, `WorkstationAllocator`, `WorkstationSeatCatalogue`. **Confirm first** the token flow isn't coming back. |

Kept on purpose: `candidature_appeals` (Haziq, stage-gate appeals) and
`candidacy_appeals` (yours, Study Candidacy appeals) are different processes
despite the names; worth a line in the report, not a merge.

## Documented, not changing now: your copies of Core data

Agreed 2026-11-08 ([`../table-audit.md`](../table-audit.md), finding C):
`demo_student_profiles` (linked by `demo_profile_id` from 8 tables),
`candidacy_departments`, `candidacy_staff_profiles` +
`candidacy_staff_assignments` and `candidacy_student_supervisors` duplicate
`users`, `departments` and `users.supervisor_id`. They were reasonable when
there were no real student accounts. There are now 180 (`docs/accounts.md`).
**No action needed before submission**; it goes in the report as the main
known duplication, with the fix (use Core's tables) as future work. Also
recorded there: your `candidacy_staff_profiles.signature_path` and Jason's
`hardbound_signatures` both store an approver's signature (finding E).
