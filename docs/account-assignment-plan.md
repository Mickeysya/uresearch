# Account assignment plan (dry run)

Generated 2026-11-08 by `chloe:assign-shared-accounts --dry-run --seed=2410`. Nothing in the database was changed. The same seed gives the same plan.

## Summary

- Demo identities: **210**; mapped onto cohort accounts: **180** of **180** accounts; kept on demo profiles: **30**.
- Seat holders mapped: **156** of 156 (94 male, 62 female); former occupants with an unreturned key mapped: **3**.
- Accounts by gender: 109 male, 71 female; part-time accounts used as seat holders (by your instruction): **74**.

## Rule checks

| Result | Rule | Evidence |
| --- | --- | --- |
| PASS | One account per identity, one identity per account | 180 mapped, 180 distinct accounts |
| PASS | Seat holders and former occupants: account gender equals the identity gender (and so the room gender) | 0 mismatches; 7 candidacy-only identities take an account of the other gender (no gendered place involved; the name on the screen changes anyway) |
| PASS | Every seat holder is in a room of the account gender | 156 seat holders checked |
| PASS | Seat holders have an expiry in the future | 0 with an expiry in the past |
| PASS | Seat holders are active (not dismissed, completed or inactive) | 0 not active |
| PASS | No seat holder carries a Softbound / Completed / Inactive label (decision 2: the label is dropped, the account is simply active) | 13 seat holders carried a label (7 softbound, 3 inactive, 3 completed); it is not moved, so their accounts are active |
| PASS | A former occupant with an unreturned key holds no seat and has the key return_pending | 3 former occupants |
| PASS | A dismissed student has no seat, no pending key and no open appeal | 2 dismissed identities mapped, 0 violations |
| PASS | A dismissed student has no activity in any other module (viva, Softbound, Hardbound, examiner, supervision, stage gate) | every other-module table has 0 rows for the 180 accounts (checked read-only) |
| PASS | A dismissal candidate: expired, no seat, no open appeal, no Softbound / completed / inactive label | 15 candidates mapped, 0 violations |
| PASS | At most one open appeal per student | 0 students with two open appeals |
| PASS | No appeal after a Dean rejection | 0 students with a later appeal |
| PASS | extension_months_used = sum of approved months (at most 12), and the expiry = the original expiry + the approved months, appeal after appeal | 14 students with an approval checked; 0 broken |
| PASS | Every account carrying an appeal has a module department, a supervisor (users.supervisor_id) and a chair | 64 appeal-bearing accounts, 0 not routable |
| PASS | Every appeal submitted on or after 2026-11-08 was submitted inside the 3-month window of the expiry that applied then (appeal window effective 2026-11-08; earlier appeals not affected) | 64 appeals submitted before 2026-11-08 skipped (they keep their dates; no expiry is moved); 0 appeals on or after it are outside the window. |
| PASS | Coursework identities stay on clearly labelled demo profiles (the cohort has no coursework accounts) | 8 kept |

## Kept on demo profiles (not moved)

| Why | How many | Matrics |
| --- | --- | --- |
| kept on demo (no account left) | 22 | 21020019, 21020020, 21020032, 21020033, 21020034, 21020035, 21020036, 21020037, 21020038, 21020039, 21020040, 21020041, 21020042, 21020043, 21020044, 21020045, 21020046, 21020047, 21020048, 21020049, 21020050, 21020051 |
| kept on demo (coursework) | 8 | 21020023, 21020024, 21020025, 21020026, 21020027, 21020028, 21020029, 21020030 |

What the kept "no account left" identities carry: 2× with_chair, 3 reminder rows; 1× with_dean, 3 reminder rows; 3× no appeal, 3 reminder rows; 1× returned, 3 reminder rows; 1× no appeal, 3 reminder rows, softbound; 3× no appeal, 2 reminder rows; 1× with_dean, 2 reminder rows; 1× no appeal, 2 reminder rows, softbound; 1× returned, 2 reminder rows; 3× no appeal, 1 reminder rows; 1× with_cgs, 1 reminder rows; 1× no appeal, 1 reminder rows, softbound; 1× with_supervisor; 2× no appeal.

Order in which accounts are handed out (so what is dropped is the least visible, as in `docs/demo-scenario-spec.md` section 4): seat holders, former occupants with an unreturned key, already dismissed, dismissal candidates, appeals in review, other appeal students, reminder students, the rest. Coursework identities never move.

## Scenarios by class

| Class | Identities | Mapped | Kept on demo |
| --- | --- | --- | --- |
| occupant | 156 | 156 | 0 |
| former | 3 | 3 | 0 |
| candidacy | 51 | 21 | 30 |

## Appeal-window demo students (named)

- **Before the window:** Goh Boon Kiat (24100309, gohboonkiat_24100309@utp.edu.my), expiry 2028-06-29, can appeal from 2028-03-29.
- **Inside the window:** Naveen Krishnan (24101010, naveenkrishnan_24101010@utp.edu.my), expiry 2027-01-29, window opened 2026-10-29.

## Other-module conflicts avoided

Read-only scan of every table outside this module that names a student (`applications`, viva, Softbound, Hardbound, examiner, supervision, stage gate, GRA/GA): **0 rows** reference any of the 180 accounts, so no account had to be avoided and none has a real Softbound submission. "Softbound submitted" therefore cannot prefer a real Softbound account; it is carried by the module's own `study_candidacies.status = softbound`.

## What apply would have to create (this module's own tables only)

- `candidacy_student_supervisors`: 180 rows (one per mapped account, from `users.supervisor_id`; the existing `candidacy:backfill-supervisors` logic).
- `candidacy_staff_profiles`: 23 new profiles for real supervisors (24 distinct supervisors used) and one per real department chair used (9 module departments); each needs an assignment in `candidacy_staff_assignments`. Signatures: real supervisors and chairs have none uploaded (only the Dean has), so memos of rewritten approvals would carry the kept snapshot image of the replaced demo approver (see Decisions).
- `study_candidacies`: 180 rows rewritten from the identity (expiry, start, status, extension months, original expiry, dismissed date); `candidacy_appeals`, `candidacy_appeal_approvals`, `candidacy_appeal_events`, `candidacy_reminder_log`, `candidacy_dismissal_exports`, `candidacy_dismissal_import_rows`, `workstation_requests`, `locker_keys`: identity columns re-pointed from `demo_profile_id` to `student_id`. `workstation_histories` is append-only and untouched (it keeps the old name text; new rows would carry the new name).

## Decisions applied (2026-11-08)

1. Appeal window effective 2026-11-08; earlier appeals not affected. No expiry is moved.
2. The Softbound / Completed / Inactive label of the 13 seat holders is dropped; the stop-rule examples stay on candidacy-only students.
3. Finished decisions (approved, rejected, returned) stay exactly as signed, with memos, appeal numbers and verification codes unchanged (the code is derived from the appeal number, the approver profile and the decision time, so an appeal that already carries a decision keeps its number). Only the stages still to come on appeals in review, and on returned appeals (whose next stage would otherwise land on a demo approver), are pointed at the real Supervisor / Programme Manager and the real chair of the department. Appeals with no decision yet are renumbered SCA-<matric_no>-<NN>.
4. Foundation in Business Management & Computing, Foundation in Engineering & Science and Integrated Engineering are added to `candidacy_departments` because the shared data has a real chair user for each.

## Expected numbers after the move

The seat, key and notice rows keep their seat and their dates; only the identity on them changes. So: Workstation **315 / 152 / 157 / 3 / 3**, the 74 listed + 4 draft appeals by status and step, Dismissal List cards and the reminder log counts are expected to be **identical to `baseline.json`**, as long as every identity of those scenarios is mapped or kept (see the table above). Population-based Insights numbers (students in the population, expiry bands, programme breakdown) are expected to change slightly because the population changes (kept demo identities are removed only when replaced).

Mapping per identity: `account-assignment-plan.csv`.
