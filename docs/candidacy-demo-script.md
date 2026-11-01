# Dismissal process: demo script

Seed first: `php artisan candidacy:demo-seed` then `php artisan candidacy:demo-registry-file`. Passwords: `password`. Accounts: `cgs@utp.edu.my` (CGS), `registry@utp.edu.my` (Registry).

1. **Open the list.** Log in as CGS, Candidacy Management > Operations > **Dismissal List**. Cards: Candidates 14, Not yet exported 12, Exported (awaiting Registry) 2.
2. **Filter.** Click the "Not yet exported" card; pick Department "Computer & Information Sciences"; Programme level PhD; Last appeal outcome "Approved earlier". The button now reads "Export filtered list to Excel". Clear.
3. **Why a student is not on it.** Search `21020015` (Softbound submitted), `21020023` (Coursework), `21020019` (appeal in review on the expiry day), `21020021` (already dismissed): none is listed.
4. **Export.** Click "Export all candidates to Excel". Open `dismissal-candidates_<date>.xlsx`. The page now shows "Exported on" for all 14 and the cards read 0 / 14.
5. **The Registry's answer.** Open `storage/app/demo/registry-confirmed-sample.xlsx` (7 candidates plus 3 invalid rows). The Registry does its work outside the system.
6. **Upload.** Log out; log in as `registry@utp.edu.my` (the menu shows only **Dismissal Upload**). Choose the sample file, **Preview**: 7 "Will be dismissed", 1 "Unknown student ID", 1 "Not a current candidate" (Softbound submitted), 1 "Duplicate row in file". Nothing is saved yet.
7. **Confirm.** Click "Confirm: dismiss 7 student(s)": the dialog says "This cannot be undone here" and Discard is focused. Confirm. The result card says 7 dismissed, 3 skipped; "Last 10 uploads" has a new row.
8. **The student left the list.** As CGS, the Dismissal List now has 7 candidates (the ones not in the Registry's file stay: "still pending").
9. **The e-mail.** Open Mailpit (http://localhost:8025): each dismissed student has "Study Candidacy: Candidacy Ended" with the Handbook 4.3 / 4.4 wording, sent at once. Nothing went to CGS.
10. **Workstation.** For a real student who holds a seat, the next 07:30 refresh (`php artisan workstation:refresh-daily`) starts a termination with the reason "Dismissal". (The demo students hold no seat, by design.)
11. **Reminders stopped.** `php artisan candidacy:send-reminders --dry-run -v` lists the dismissed students as "none (dismissed)" and the Coursework students as "none (coursework)".
12. **Start again.** `php artisan candidacy:demo-clear` then `candidacy:demo-seed` and `candidacy:demo-registry-file` restore everything, including the dismissed students.

> Test status of this round: full suite 1040 passed; pinned to Friday 6 Nov and Saturday 7 Nov 2026 also 1040 passed. The Sunday / first-of-month / quarter-end date sweep is deferred to the end of the assistant round.
