# Known demo exceptions (decided 2026-11-08)

Two things in the demo data look inconsistent on purpose or by decision. Neither is a bug in the Workstation or Study Candidacy code.

## 1. `student2@utp.edu.my` (Nur Farah Adilah, matric 22001002)

Left as it is. No data change.

| Record | Value | Why it looks wrong |
|---|---|---|
| Seat | N2-02-01 #11, request confirmed, no override | The room is male; her gender in `student_genders` is female |
| Programme (`users`) | PhD Part-Time | The seat rule asks for Full-Time research |
| Candidacy status | `active`, with `dismissed_at` 2026-09-21 | "Active" and "dismissed" at once |
| Candidacy expiry | today minus 10 days (2026-10-29 on 2026-11-08) | She still holds the seat; her original expiry is 2026-10-01 with 0 extension months |
| Candidacy department | id 99 (Geoscience) | Her user record says Computing |
| Appeal | `SCA-22001002-01`, `closed_expired` | |

**Why:** the shared `database/seeders/DatabaseSeeder.php` plants her. It gives her seat 11 and marks her female (`workstations()`), and `studyCandidacies()` resets her expiry to "today minus 10 days" every time `./sync.sh` runs. The dismissal date comes from the old dismissal flow. A permanent fix (a consistent account, or no seat) belongs to the owner of that seeder, not to this module.

**What it does on the dashboard:**
- She is one of the 157 occupied seats.
- She is the third "already dismissed" on the Dismissal List.
- **Termination notices: 3 today, 4 after the next 07:30 run.** `workstation:refresh-daily` starts a termination for her ("Loan period ended (candidacy expiry)"). A notice does not release the seat; 315 / 152 / 157 / 3 / 3 stay the same until her vacate-by date passes.
- `workstation:refresh-daily --dry-run` must show **0 seats released** (checked 2026-11-08).

## 2. Appeal `SCA-21010038-01`: the only exception to "a returned appeal goes back to the approver who returned it"

The rule (confirmed 2026-11-08): when an approver Returns an appeal, the student edits and resubmits, and it goes back to **the same approver who returned it**. The code now enforces this in `CandidacyAppealService::resubmit()`: the profile on the latest `returned` decision at that stage is written into the stage's pointer before the appeal moves. Dean returns still go to CGS first (`needs_cgs_recheck`) and then to the Dean; CGS is a role, so any CGS user can act.

`SCA-21010038-01` was returned by a Chair who has no login (a demo profile, Prof. Dr. Ng Yen Ni). Nobody could act as that Chair, so the appeal points to the real chair of the student's department, Dr. Nurul Huda Zainal. The code leaves the pointer alone when the returner has no login. This is the only exception.

Two returned appeals still on demo identities (`SCA-21020035-01`, `SCA-21020043-01`) were not touched; their approvers are demo profiles with no login.

The pointers of `SCA-21010029-01` (back to the Supervisor who returned it) and `SCA-21010086-01` (back to the Chair who returned it) were restored on 2026-11-08 after the account move had re-routed them.
