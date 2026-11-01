The whole CGS side of the workstation system, in order. See cgs_status_glossary for every status and transition, cgs_reasons for which reason to pick for each action, cgs_automation for what runs on its own, and cgs_insights_definitions for how the Insights page's numbers are calculated.

**1. Landing page.** A stats bar (total, available, occupied, maintenance, awaiting key return seats, keys pending collection, keys overdue for return -- each a live count, never a snapshot), a search box (student name, student ID, email, seat number or room code), and filters (block, room, Seat Status, gender, Key Status) that combine with the search.

**2. Drilling down.** Block -> Room -> Seat Details, or straight to a seat from search results. Seat Details shows the seat's own status, its occupant (if any), its key status, and the Actions available for its current status.

**3. Actions, by seat status** (see cgs_reasons for the exact reason lists):
• Available: Assign Student (only a student with no seat of their own), or Set Maintenance.
• Occupied: Reassign (to a different seat, same gender, in one step) or Release (Termination reasons give {{vacate_working_days}} working days' notice; Immediate correction reasons release it at once).
• In a termination notice: "Release now" (ends it immediately) or "Withdraw Termination" (cancels the notice, student keeps the seat).
• Awaiting Key Return: "Mark as Returned" (the key physically came back) or "Release Hold" (it is not coming back -- lost or the lock was replaced).
• Maintenance: Restore (back to Available, no reason needed).

**4. Locker keys.** "Mark as Collected" moves a Pending Collection key to Collected; "Mark as Returned" moves a Return Pending key to Returned, which also frees an Awaiting Key Return seat if that is why it was waiting. Both happen in person at the CGS counter.

**5. History and exports.** Every action is recorded in History (actor, action, reason, remark, before/after state). "Export Results" downloads the currently filtered seat list; "Export History" downloads the full History -- both live Excel files, never a cached snapshot.

**6. Insights.** A separate page (/cgs/workstation/insights) of the numbers the stats bar does not already show -- key highlights, room occupancy, release forecasts, candidacy/length-of-stay/programme breakdowns, and the locker key overview. See cgs_insights_definitions for exactly how each number is worked out.
