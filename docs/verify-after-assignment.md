# How to verify after assignment

Written for: whoever assigns the 180 student accounts (the Core / database owner or the module owner) and then needs to prove it worked, without knowing the module. Part of the same set as [demo-scenario-spec.md](demo-scenario-spec.md) and [shared-data-contract.md](shared-data-contract.md).

## How to use this page

1. Every row below is one number on one screen. Column **Expected** is the value measured on the demo database on **3 November 2026** (Asia/Kuala_Lumpur) and the value the assignment should keep.
2. Column **Query** is a read-only query. Run it, and compare with the screen. Both must equal **Expected**.
3. SQL queries run in phpMyAdmin (http://localhost:8080, database `uresearch`) or the MySQL command line. Queries marked **app** run the module's own function, so you get the number the card shows; run them inside the app container:
   `docker exec uresearch-app php artisan tinker --execute '<the code>'`
4. All queries only read (`SELECT`, or a function that only reads). None changes data.
5. Rows marked **moves with the date** depend on today's date. On a later day, compare with the *definition* in the Query column instead of the number. Everything else should match exactly.

Screens (all need a CGS login, for example `cgs@utp.edu.my`, password `password` in the demo):
- **Workstation Management** = http://localhost:8000/cgs/workstation
- **Workstation Insights** = http://localhost:8000/cgs/workstation/insights
- **Study Candidacy Management, Operations** = http://localhost:8000/cgs/candidacy
- **Dismissal List** = http://localhost:8000/cgs/candidacy/dismissals
- **Study Candidacy Insights** = http://localhost:8000/cgs/candidacy/insights

## 1. Workstation Management cards (seat catalogue)

| ID | Card | Expected | Query (SQL) |
|---|---|---|---|
| W1 | Total | 315 | `SELECT COUNT(*) FROM workstations WHERE deleted_at IS NULL;` |
| W2 | Available / Occupied / Maintenance / Awaiting Key Return | 152 / 157 / 3 / 3 | `SELECT status, COUNT(*) FROM workstations WHERE deleted_at IS NULL GROUP BY status;` (`disabled` = Maintenance) |
| W3 | Key Pending Collection | **19** (moves with the date: keys not collected after 7 days are cancelled by the daily job) | `SELECT COUNT(*) FROM workstations w JOIN workstation_requests r ON r.workstation_id=w.id AND r.status='confirmed' JOIN locker_keys k ON k.workstation_request_id=r.id AND k.status='requested' WHERE w.deleted_at IS NULL;` |
| W3b | (for information) all key rows in `requested` status | 20: one of them sits on an already released request and is correctly left out of W3 | `SELECT COUNT(*) FROM locker_keys WHERE status='requested';` |
| W5 | Key Return Overdue | 2 (moves with the date) | `SELECT COUNT(*) FROM locker_keys WHERE status='return_pending' AND return_due_at < NOW();` |
| W5b | Keys waiting to come back (all) | 3: the 3 Awaiting Key Return seats | `SELECT COUNT(*) FROM locker_keys WHERE status='return_pending';` |
| W6 | Occupied seats by room gender | male 95, female 62 | `SELECT l.gender, COUNT(*) FROM workstations w JOIN workstation_locations l ON l.id=w.workstation_location_id WHERE w.deleted_at IS NULL AND w.status='occupied' GROUP BY l.gender;` |
| W7 | Seat holders (confirmed requests) | 157, always equal to Occupied | `SELECT COUNT(*) FROM workstation_requests WHERE status='confirmed';` |
| W8 | Seats in a termination notice | 3 | `SELECT COUNT(*) FROM workstation_termination_notices WHERE status='active';` |
| app | All seven cards exactly as shown | `total 315, available 152, occupied 157, maintenance 3, awaiting_key_return 3, key_pending_collection 19, key_return_overdue 2` | `echo json_encode(\App\Modules\Chloe\Support\WorkstationStatistics::overall());` |

The seat counter must still read 315: `SELECT last_number FROM workstation_seat_counter;`

## 2. Seat holders: are they the right kind of student?

| ID | What | Expected | Query (SQL) |
|---|---|---|---|
| S1 | Seat holders in total | 157 | `SELECT COUNT(*) FROM workstation_requests WHERE status='confirmed';` |
| S2 | Seat holders by programme type | 157 full-time research. (Today: 156 full-time research and 1 part-time, the real account `student2@utp.edu.my`; the target is all 157 full-time research) | `SELECT t, COUNT(*) FROM (SELECT CASE WHEN programme LIKE '%coursework%' THEN 'coursework' WHEN programme LIKE '%part-time%' THEN 'part-time' ELSE 'full-time research' END t FROM (SELECT COALESCE(u.programme,p.programme) programme FROM workstation_requests r LEFT JOIN users u ON u.id=r.student_id LEFT JOIN demo_student_profiles p ON p.id=r.demo_profile_id WHERE r.status='confirmed') x) y GROUP BY t;` |
| S3 | Candidacy-only students that cannot hold a seat (expired, dismissed or coursework) | 30 for the full scenario (23 if the 7 recommended drops in the spec were taken) | `SELECT COUNT(*) FROM demo_student_profiles WHERE candidacy_only=1 AND (DATE(candidacy_expiry_date) < CURDATE() OR status='dismissed' OR programme LIKE '%coursework%');` |
| S4 | Demo students in total, and candidacy-only | 210, of which 51 candidacy-only. (These are the module's own demo identities; after assignment this figure is expected to change by exactly the number of identities replaced by real accounts) | `SELECT COUNT(*), SUM(candidacy_only) FROM demo_student_profiles;` |
| S5 | Seat holders whose candidacy expires within 3 months | 3 (moves with the date) | `SELECT COUNT(*) FROM workstation_requests r JOIN demo_student_profiles p ON p.id=r.demo_profile_id WHERE r.status='confirmed' AND DATE(p.candidacy_expiry_date) BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 MONTH);` (after assignment, join `study_candidacies` on `student_id` instead of the demo table) |

Rule check: nobody holds two seats. This must return no rows: `SELECT student_id, COUNT(*) FROM workstation_requests WHERE status='confirmed' AND student_id IS NOT NULL GROUP BY student_id HAVING COUNT(*) > 1;`

## 3. Workstation Insights tiles

| ID | Tile | Expected | Query |
|---|---|---|---|
| W5 | Key returns overdue | 2 | same as W5 |
| W9 | Rooms at or above 90 % occupancy | 1 room: `N2-02-01` (16 of 17 seats) | `SELECT l.room_code, SUM(w.status='occupied') occupied, COUNT(*) total FROM workstations w JOIN workstation_locations l ON l.id=w.workstation_location_id WHERE w.deleted_at IS NULL GROUP BY l.room_code HAVING occupied/total >= 0.9;` |
| W8 | Seats in a termination notice | 3 | same as W8 |
| S5 | Candidacy expires within 3 months | 3 (moves with the date) | same as S5 |
| app | Seats that may be released next month | 0 for December 2026 (moves with the date; three seats are due in November) | `echo json_encode(array_count_values(array_filter(array_column(\App\Modules\Chloe\Support\WorkstationStatistics::insightsFactRows(),'release_month'), fn($m)=>$m!=='none')));` |

## 4. Study Candidacy Management, Operations cards

| ID | Card | Expected | Query (SQL) |
|---|---|---|---|
| O2 | In Progress | 38 | `SELECT COUNT(*) FROM candidacy_appeals WHERE status IN ('with_supervisor','with_chair','with_cgs','with_dean');` |
| O1 | Returned / Approved / Rejected | 7 / 17 / 8 | `SELECT status, COUNT(*) FROM candidacy_appeals GROUP BY status;` (status `returned`, `approved`, `rejected`) |
| O3 | All appeals listed (drafts are not listed) | 74 | `SELECT COUNT(*) FROM candidacy_appeals WHERE status<>'draft';` |
| app | The four cards exactly as shown | `in_progress 38, returned 7, approved 17, rejected 8, all 74` | `$f=\App\Modules\Chloe\Support\CandidacyOperationsQuery::filters([]); echo json_encode(\App\Modules\Chloe\Support\CandidacyOperationsQuery::counts($f));` |

For information, the 74 are: with Supervisor / Programme Manager 9, with Department Chair 11, with CGS 8, with Dean 10, returned 7, approved 17, rejected 8, closed expired 4. There are also 4 drafts, which no list shows.

## 5. Dismissal List cards

| ID | Card | Expected | Query |
|---|---|---|---|
| app | Candidates / Not Yet Exported / Exported | 14 / 12 / 2 (moves with the date: a candidate is a student whose candidacy has expired, with no Softbound, no appeal in review, not completed or inactive, not coursework, not already dismissed) | `$c=\App\Modules\Chloe\Support\CandidacyDismissalList::candidates(); echo json_encode(\App\Modules\Chloe\Support\CandidacyDismissalList::counts($c, \App\Modules\Chloe\Support\CandidacyDismissalList::filters([])));` |
| D1 | Students whose candidacy has expired (the pool candidates come from) | 25 | `SELECT COUNT(*) FROM demo_student_profiles WHERE DATE(candidacy_expiry_date) < CURDATE();` |
| D2 | Export log rows | 2: equals "Exported" | `SELECT COUNT(*) FROM candidacy_dismissal_exports;` |

Of the 25 expired students, the 14 candidates and 11 who are *not* candidates, each with a reason shown on the page: Softbound submitted 2, Inactive or deferred 1, Completed 1, Appeal in review 2, Already dismissed 2, Coursework 3.

## 6. Study Candidacy Insights tiles

| ID | Tile | Expected | Query (app) |
|---|---|---|---|
| app | Population (research students counted) | 176 | `$s=\App\Modules\Chloe\Support\CandidacyStatistics::summary(\App\Modules\Chloe\Support\CandidacyStatistics::population()); echo $s['total'];` |
| app | Expiring within 3 months | 21 (moves with the date) | same code, `echo $s['within3'];` |
| app | No action yet (high risk) | 123 (moves with the date) | same code, `echo $s['high_risk'];` |
| app | Appeals in progress | 38 | `echo \App\Modules\Chloe\Support\CandidacyStatistics::appealsInProgress();` (same as O2) |
| app | Dismissal candidates | 14 | same as the Dismissal List card above |
| app | Status categories | Softbound submitted 10, Appeal in review 36, Appeal returned 7, No action yet 123 | same code, `echo json_encode($s['by_category']);` |

The population includes every student the module counts as research, so it changes whenever the number of demo students or real accounts changes. If you took the 7 drops recommended in the spec, expect the population to fall by 2 (176 to 174), Softbound submitted by 1 and Appeal in review by 1.

## 7. Nothing else may change

Run these before and after and compare. They must be identical (apart from the identities you deliberately replaced):

| Check | Query |
|---|---|
| Shared user accounts | `SELECT COUNT(*) FROM users;` (14 today; it rises only by the accounts you add) |
| Extension months and expiry dates of real students | `SELECT id, student_id, candidacy_expiry_date, extension_months_used, status FROM study_candidacies ORDER BY id;` |
| Appeals untouched | `SELECT COUNT(*) FROM candidacy_appeals;` (78 today: 74 listed and 4 drafts) |
| Workstation history kept | `SELECT COUNT(*) FROM workstation_histories;` (188 today; it may only grow) |
| Locker keys | `SELECT status, COUNT(*) FROM locker_keys GROUP BY status;` (cancelled 2, collected 48, requested 20, return_pending 3, returned 1) |

If a number differs and it is not marked "moves with the date", stop and ask the module owner before changing anything.
