Seat statuses, and the actions that move a seat between them:
• Available -- nobody holds it. -> Occupied (a student selects it, or you Assign a student) or Maintenance (you Set Maintenance).
• Occupied -- confirmed and held by a student. -> Available or Awaiting Key Return (Release/Reassign-away, depending on the key), or a Termination notice state.
• Termination notice -- occupied, but with an active notice and a vacate-by date. -> Available/Awaiting Key Return (vacate-by date passes, or you use "Release now"), or back to a plain Termination-notice-free Occupied state (you Withdraw Termination).
• Awaiting Key Return -- released, but the locker key is still outstanding. -> Available (CGS marks the key Returned, or uses Release Hold).
• Maintenance -- out of use. -> Available (you Restore it; no reason required).

Locker key statuses: the same seven as student_status_glossary (Not Requested, Pending Collection, Collected, Return Pending, Overdue, Returned, Lost) -- Lost is CGS-only, set by Release Hold.
