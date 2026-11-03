# Demo scenario specification: what the 180 student accounts must be able to fill

Written for: the owner of the shared (Core) database, who has to decide whether they or the module owner (Chloe, module `Chloe`: Workstation and Study Candidacy) assign the 180 shared student accounts to the demo scenario.
Numbers are a snapshot of the demo database taken on **3 November 2026** (Asia/Kuala_Lumpur). Nothing in this document changes any data.

## How to use this guide

1. Read **"The decision in one minute"** below. It tells you what is needed and what is short.
2. Run the one query in section 3 against your 180 accounts to find out whether you have enough eligible research students.
3. Read section 4 only if the answer is "not enough". It lists what drops, in order.
4. Before anyone assigns anything, read [shared-data-contract.md](shared-data-contract.md): it lists the fields the module reads from the shared database, and the tables the module owns and must not be overwritten.
5. After anyone assigns anything, use [verify-after-assignment.md](verify-after-assignment.md): it has the expected numbers for every card and the read-only query for each.
6. Every number here has an ID (for example **W2**). The query that produces it is under the same ID in `verify-after-assignment.md`, so you can check a number yourself without asking me.

Words used in this guide:
- **Demo student**: a row in the module's own table `demo_student_profiles`. It has a name and a student number but **no login**. Today, 156 of the 157 seat holders are demo students.
- **Shared account**: a row in the shared `users` table with `role = 'student'`. It can log in.
- **Occupant**: a student who currently holds a Workstation seat (a *confirmed* request on a seat).
- **Eligible research student**: active, full-time research, and the study candidacy expiry date is in the future (the exact rule is in section 3).

## The decision in one minute

| What | Number | Source |
|---|---|---|
| Student accounts offered | 180 | given |
| Seats that must be held by a different student each | **157** | W2 |
| Accounts needed that are *not* seat holders (expired, dismissed, coursework) to keep every candidacy scenario | **30** | S3 |
| Accounts needed for the full scenario | **157 + 30 = 187** | S1, S3 |
| Shortfall with 180 accounts | **7** | 187 − 180 |

- **Workstation is the priority.** All 157 seat holders can be filled from the 180 as long as at least **157 of them are eligible research students** (section 3). That leaves 23 accounts for everything else.
- **The 7 missing accounts hurt Study Candidacy, not Workstation.** Section 4 says which 7 scenario students to drop and what changes on the screens if you do.
- **You may not need to choose at all** if you can supply 187 accounts instead of 180.

## 1. What the seat catalogue looks like (does not depend on the shared database)

The seats belong to the module, so these do **not** change when accounts are assigned. Only *who* sits in an occupied seat changes.

| Card | Target after assignment | Query |
|---|---|---|
| Total seats | 315 | W1 |
| Available | 152 | W2 |
| Occupied | 157 | W2 |
| Maintenance | 3 | W2 |
| Awaiting Key Return | 3 | W2 |
| Key Pending Collection | **19 on the card** (see the note below) | W3 |
| Key Return Overdue | 2 | W5 |

Note on "20 key pending collection": the card shows **19**, not 20. The database holds 20 key rows with status `requested` (W3b), but one of them is on a request that has already been released, so the card correctly leaves it out (W3 counts only keys on a seat that is still occupied). If you want the card to read 20, one more occupant must hold a key in `requested` status; that is a data choice for whoever assigns, not a defect.

Seat counts by gender (rooms are gender-specific, see section 3): male rooms 194 seats (95 occupied), female rooms 121 seats (62 occupied). Query W6.

## 2. Who must exist (the three groups of students)

| Group | How many | What each must be | Why |
|---|---|---|---|
| **O** Occupants | **157** | A different eligible research student each, with a recorded gender: **95 male, 62 female** (to match the gender of the room they sit in) | One seat per student is enforced by the database; a seat can only be held by a student of the room's gender |
| **F** Former occupants with an unreturned key | **3** (2 overdue, 1 due within 7 days) | Any student who once held a seat. They hold **no** seat now, so they do not need to be eligible and can be taken from group X | The 3 "Awaiting Key Return" seats need a student whose key is not yet returned |
| **X** Candidacy-only students | **30** | Students who structurally cannot hold a seat. Exactly: **14** dismissal candidates, **8** stop-rule examples (Softbound submitted 2, Inactive/deferred 1, Completed 1, Appeal in review 2, Already dismissed 2) and **8** coursework students (3 of them with an expired candidacy, 5 not). 14 + 8 + 8 = 30 | Needed for the Dismissal List, the reminder stop rules and the "coursework gets no reminders" example |

Group F (3) is counted *inside* group X for the 187 total, because an expired or coursework student can be the person whose key is still out. If you would rather use 3 additional active students for F, the total becomes 190.

The numbers come from S1 to S3 in `verify-after-assignment.md`.

Of the 157 seat holders, 54 also have a Study Candidacy appeal and 13 carry a Softbound/completed/inactive label. These scenarios can share the same account, which is why no extra accounts are needed for them.

## 3. How many of the 180 must be research students

**At least 157 of the 180 must be eligible research students.** Coursework students never hold a seat.

An account is eligible for a seat only if all three are true (this is the module's own rule, `WorkstationEligibility`):
1. Its study candidacy status is `active`.
2. Its study candidacy expiry date is in the future.
3. Its programme text contains neither `part-time` nor `coursework` (the module reads the text; an empty programme is not eligible).

Also: **95 of them must be male and 62 female**, because a seat in a male room can only go to a male student and the other way round. Gender is not in the shared `users` table; it is stored in the module's table `student_genders`, so someone has to supply it (see the data contract).

To count how many of your 180 qualify on rule 3 alone (rules 1 and 2 are the module's own `study_candidacies` rows, which are created when the account is assigned), run this on the shared database, replacing the `WHERE` part with whatever identifies your 180:

```sql
SELECT
  SUM(programme NOT LIKE '%coursework%' AND programme NOT LIKE '%part-time%' AND programme <> '') AS full_time_research,
  SUM(programme LIKE '%part-time%')  AS part_time,
  SUM(programme LIKE '%coursework%') AS coursework,
  SUM(programme = '' OR programme IS NULL) AS no_programme
FROM users
WHERE role = 'student' AND /* <- the condition that selects the 180 accounts */ 1 = 1;
```

For reference, in the demo database today 156 of the 157 seat holders are full-time research. The 157th is a real account (`student2@utp.edu.my`) whose programme says "PhD Part-Time"; it holds a seat because CGS assigned it by hand. The module's rule would not let that student select a seat alone (query S2). To keep the data clean, assign a full-time research student to that seat too, so all 157 are eligible.

## 4. If the 180 accounts are not enough

There are two different shortages. Handle them separately.

### 4a. Fewer than 157 eligible research students (a Workstation shortage)

Let **R** be the number of eligible research students among the 180 (counting both genders correctly: at most 95 male and 62 female can be used). Then the highest achievable numbers are:

| Card | Value if R ≥ 157 | Value if R < 157 |
|---|---|---|
| Total / Maintenance / Awaiting Key Return | 315 / 3 / 3 | 315 / 3 / 3 (unchanged) |
| Occupied | 157 | **R** |
| Available | 152 | **152 + (157 − R)** |
| Key Pending Collection | 19 | min(19, R) |
| Key Return Overdue | 2 | 2 (needs 2 former occupants from group F) |
| Seats in termination notice | 3 | min(3, R) |

Examples: R = 150 gives occupied 150 and available 159; R = 140 gives occupied 140 and available 169; R = 120 gives occupied 120 and available 189.

What drops, in this order: **occupied** (down to R), then **available** rises by the same amount, then the occupancy percentages and the "rooms at or above 90 % occupancy" tile (today 1 room, N2-02-01 with 16 of 17 seats). Totals, maintenance seats, awaiting-key-return seats and overdue keys do **not** drop.

**Recommendation:** make sure at least 157 eligible research students are in the 180, split 95 male and 62 female. If that is impossible, accept occupied = R rather than turning real students into fake research students.

### 4b. Not enough accounts for the candidacy-only group (a Study Candidacy shortage)

With 180 accounts: 157 seat holders + 30 candidacy-only students = 187, so **7 scenario students must be dropped** (or 7 accounts added).

Recommended order of what to drop (least visible first), 7 in total:

| Drop | How many | What changes on screen |
|---|---|---|
| Coursework students whose candidacy has **not** expired (keep the 3 expired ones, which show "not listed for dismissal") | 5 | The reminder run's "not sent: coursework" falls from 8 to 3. Nothing else |
| One duplicate stop-rule example each of "Softbound submitted" and "Appeal in review" (keep one of each) | 2 | The Candidacy Insights population falls by 2 (176 today); the "Softbound submitted" and "Appeal in review" categories fall by 1 each. The Dismissal List cards do **not** change |

Do **not** drop the 14 dismissal candidates, the 2 already-dismissed students, or any occupant. The expected Operations and Dismissal numbers in `verify-after-assignment.md` assume they stay.

## 5. Things I could not know and that you need to check

- **Who the 180 accounts are.** The shared database is not available in this module's environment. I only have 3 shared student accounts (`student@utp.edu.my`, `student2@utp.edu.my` and one more), so I could not count programme types or genders among the 180. Section 3 has the query.
- **Dates move the numbers.** Anything that depends on "today" (expiring within 3 months, keys overdue, seats released next month, waiting days) was measured on 3 November 2026. Run the verification on the day of assignment and compare against the *definitions*, not the exact numbers, for those items. `verify-after-assignment.md` marks each one.
- **History keeps the old names.** The Workstation history log stores the student's name and number as text at the time of each action. If occupants change from demo students to real accounts, old history rows still show the demo names. That is intentional (history is append-only); tell me if you want it different.
- **One account, one seat.** The database refuses a second confirmed request for the same student or the same seat. The assignment therefore needs 157 *different* accounts for the 157 seats; it cannot reuse one account for two seats.

## 6. What this guide does not do

- It does not contain any command that assigns accounts, creates rows, or changes data, schema or code.
- It does not decide who assigns the accounts. That is yours to decide.
