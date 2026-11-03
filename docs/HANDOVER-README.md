# Handover: assigning the 180 shared student accounts to the demo scenario

For: the Core / database owner. You decide whether you or the module owner (Chloe: Workstation and Study Candidacy) assigns the 180 accounts. These documents give you what you need to decide and to check the result. **They contain no assignment command, and nothing in them changes data, schema or code.**

## The three documents

| Read | File | What it tells you |
|---|---|---|
| 1st | [demo-scenario-spec.md](demo-scenario-spec.md) | What the 180 accounts must be able to fill: 157 seat holders, 30 candidacy-only students, how many must be research students, what to do if there are too few. Has a "how to use this guide" at the top |
| 2nd | [shared-data-contract.md](shared-data-contract.md) | The shared fields the module reads (names only), the tables the module owns and must not be overwritten, and the commands never to run during the assignment |
| 3rd | [verify-after-assignment.md](verify-after-assignment.md) | The expected number for every card and tile, and the read-only query that produces each one |

## The answer in five lines

1. **At least 157 of the 180 must be eligible full-time research students**, 95 male and 62 female. Coursework students never hold a seat.
2. The seat catalogue does not change: **315 seats, 3 in maintenance, 3 awaiting key return, 152 available, 157 occupied**. Only the person in each occupied seat changes.
3. The full scenario needs **187 accounts** (157 seat holders + 30 candidacy-only students). With 180 you are **7 short**; the spec says which 7 to drop and what changes on screen. The Workstation numbers are not affected.
4. The Key Pending Collection card shows **19**, not 20 (one key is on a released request and is correctly left out).
5. Several numbers depend on today's date; the verification page marks which ones.

## What to do first

1. Read the spec's section "The decision in one minute" and run the one query in its section 3 on your 180 accounts.
2. Decide who assigns: you, or the module owner. If it is the module owner, hand over the 180 account ids; if it is you, read the data contract first.
3. Take a before-snapshot using the section "Nothing else may change" of the verification page, then assign, then run the verification page top to bottom.

## Do not

Run `workstation:demo-seed`, `workstation:demo-clear`, `workstation:demo-login`, `candidacy:demo-seed`, `candidacy:demo-clear` or `migrate:fresh`. They re-create or delete demo data. Details in the data contract, section 2.3.

## Snapshot

All numbers were measured on 3 November 2026 (Asia/Kuala_Lumpur) on the demo database `uresearch`.
