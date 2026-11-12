# Functional test cases (Table 4.x)

Accounts (all @utp.edu.my): student@ (Ahmad Danial, GA student after the GA Extension demo seeder), a non-GA demo student, supervisor@, chair@, director@ (Senior Director), zulkifly.cgs (assigned CGS officer for Nureen's modules), syahmi.cgs (another CGS officer).

## GA Extension (Figure 3.9a)

| ID | Test case | User | Steps | Expected result |
|---|---|---|---|---|
| GAX-01 | Non-GA student cannot open the form | non-GA student | Open GA Extension | Form refused with a message |
| GAX-02 | Shortcut appears near GA end date | student@ (GA ends within 60 days) | Look at the sidebar | "GA Extension (your GA ends …)" shown in sidebar |
| GAX-03 | Save a draft | student@ | Fill part of the form, click Save draft | Draft saved and listed in My Drafts |
| GAX-04 | Delete a draft | student@ | My Drafts → Delete | Draft removed |
| GAX-05 | Missing documents refused | student@ | Submit without a required document | Error shown, not submitted |
| GAX-06 | Backdated start refused | student@ | Submit with a start date in the past | Error shown, not submitted |
| GAX-07 | Submit application | student@ | Fill all fields, submit | Pending at Supervisor; supervisor emailed |
| GAX-08 | Supervisor endorses | supervisor@ | Open the application, Endorse | Pending at CGS; student emailed |
| GAX-09 | Supervisor rejects | supervisor@ | Reject with reason (on a separate application) | Rejected; student sees the reason |
| GAX-10 | CGS returns to supervisor | zulkifly.cgs | Return with reason | Back at Supervisor; reason recorded |
| GAX-11 | CGS approves | zulkifly.cgs | Approve | Approved; student and supervisor emailed; Senior Director informed |
| GAX-12 | CGS rejects | zulkifly.cgs | Reject with reason (on a separate application) | Rejected; student emailed |
| GAX-13 | Other CGS officer cannot decide | syahmi.cgs | Open an application at the CGS stage | Read-only, "Assigned to: Zulkifly", no decision buttons; a forced decision returns 403 |
| GAX-14 | Sensitive data masked | supervisor@ | View the application | IC and account no. shown as •••• 1234 |
| GAX-15 | Generated form PDF | student@ | Download the form after approval | PDF with approvals filled in |
| GAX-16 | Rejected application cannot be approved | zulkifly.cgs | Try to decide on a rejected application | Not possible (no buttons / refused) |

## Supervision (Figure 3.9b)

| ID | Test case | User | Steps | Expected result |
|---|---|---|---|---|
| SUP-01 | Submit a request | student@ | Request a main supervisor | Pending at Requested Supervisor; supervisor emailed |
| SUP-02 | PGFAC memo required | GA student changing main supervisor | Submit without PGFAC memo | Submission blocked with message |
| SUP-03 | Supervisor endorses | requested supervisor | Endorse | Pending at Chair |
| SUP-04 | Supervisor rejects | requested supervisor | Reject with reason (separate request) | Rejected; student sees reason |
| SUP-05 | Chair endorses | chair@ | Endorse | Pending at CGS |
| SUP-06 | Chair rejects | chair@ | Reject (separate request) | Rejected; student emailed |
| SUP-07 | CGS previews letter | zulkifly.cgs | Preview letter | DRAFT letter shown; nothing changes |
| SUP-08 | CGS endorses and issues | zulkifly.cgs | Endorse & issue letter | Approved; letter CGS/SVAPPT/… issued; emails sent |
| SUP-09 | Main supervisor updated | zulkifly.cgs / student@ | Check the student's supervisor after SUP-08 | New supervisor recorded, previous one kept |
| SUP-10 | CGS rejects | zulkifly.cgs | Reject (separate request) | Rejected; student emailed |
| SUP-11 | Revoke letter | zulkifly.cgs | Issued letters → Revoke with reason | Letter stamped REVOKED; previous supervisor restored; emails sent |
| SUP-12 | Verify revoked letter | public (not signed in) | Open /verify/appointment/{ref} | Shows "Revoked" |

## GA/GRA Certification Letter (Figure 3.9c)

| ID | Test case | User | Steps | Expected result |
|---|---|---|---|---|
| CER-01 | Save draft and download attachment | student@ | Fill form, click Download pre-filled attachment | Draft saved; attachment PDF downloads |
| CER-02 | No valid supervisor blocked | student with no supervisor | Try to submit | Blocked with message |
| CER-03 | Submit request | student@ | Submit with signed evidence (PDF) | Pending at Supervisor; supervisor emailed |
| CER-04 | Supervisor endorses | supervisor@ | Endorse | Pending at CGS |
| CER-05 | Supervisor rejects | supervisor@ | Reject (separate request) | Rejected; student emailed |
| CER-06 | Appointment check shown | zulkifly.cgs | Open the request | Shows Verified / Mismatch / Not found; does not block |
| CER-07 | CGS reviews | zulkifly.cgs | Approve | Pending at Senior Director |
| CER-08 | CGS rejects | zulkifly.cgs | Reject (separate request) | Rejected; student emailed |
| CER-09 | Senior Director previews | director@ | Preview letter | DRAFT letter shown; nothing changes |
| CER-10 | Senior Director approves | director@ | Approve | Approved; letter CGS/GACERT/… issued; one page with QR code |
| CER-11 | Senior Director rejects | director@ | Reject (separate request) | Rejected; student emailed |
| CER-12 | Verify valid letter | public (not signed in) | Open /verify/certificate/{ref} | Shows "Valid" and four facts |
| CER-13 | Revoke letter | director@ or zulkifly.cgs | Revoke with reason | Letter revoked; student and supervisor emailed; verification shows "Revoked" |
| CER-14 | Identity number masked | supervisor@ | View the request | Shown as •••• 1234 |

## Attendance Record and Appeal (Figure 3.9d)

| ID | Test case | User | Steps | Expected result |
|---|---|---|---|---|
| ATT-01 | Upload attendance sheet | zulkifly.cgs | Upload a valid sheet | Records saved; summary shows rows added |
| ATT-02 | Invalid row rejected | zulkifly.cgs | Upload a sheet with a future-period row | That row rejected with reason; others saved |
| ATT-03 | Sync now | zulkifly.cgs | Click Sync now | Sync runs; last-run details updated |
| ATT-04 | Below 80% flagged | system / student | Student's latest period below 80% | At risk; student emailed (no projected figure); supervisor copied |
| ATT-05 | Projection flags student | system / zulkifly.cgs | Declining student, projection below 80% (period 4–8) | At risk (Projected) on the At-Risk list |
| ATT-06 | No repeated email | system | Run sync again with no change | No new email |
| ATT-07 | Student never sees projection | flagged student | Open dashboard, overview and history | Action shown (e.g. "Attend N of next M sessions"); no projected % anywhere |
| ATT-08 | Appeal without documents refused | student | Submit appeal without both documents | Error shown, not submitted |
| ATT-09 | Submit appeal | student | Submit with Justification Letter and Monthly Activity Breakdown | Pending at CGS |
| ATT-10 | CGS approves fewer sessions | zulkifly.cgs | Approve fewer sessions than requested | Approved; sessions excused; risk re-checked |
| ATT-11 | CGS rejects appeal | zulkifly.cgs | Reject (separate appeal) | Rejected; student emailed |
| ATT-12 | Leave over allowance warned | zulkifly.cgs | Record more than 14 leave days in a year | Warning shown |

## Shared features

| ID | Test case | User | Steps | Expected result |
|---|---|---|---|---|
| SHR-01 | Track My Applications | student@ | Open Track My Applications | Each application shows correct stage and expected decision date |
| SHR-02 | Overdue reminder | system | Run the reminder command on an overdue application | Reviewer emailed; application marked overdue |
| SHR-03 | Email links work | each recipient | Click links in the emails | Each opens a page that person can access |

Total: 57 test cases.
