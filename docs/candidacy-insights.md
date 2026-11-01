# Candidacy Insights

Candidacy Management > Insights (`/cgs/candidacy/insights`, CGS roles only; everyone else gets 403). **Operational monitoring**, not Business Intelligence: it shows what CGS can still act on.

> **What to say about the forecast:** it is a *projection from known expiry dates*, not a prediction model. It counts the expiry dates on record today. It does not guess who will appeal, submit the Softbound or leave.
> An approved appeal moves a date and the chart follows on the next update.

One source of numbers: `Support\CandidacyStatistics`. The page embeds its flat rows and the browser only tallies them, so filters and highlights recompute every card consistently. The same class answers the
live endpoints (`/cgs/candidacy/insights/live/version` and `/stats`, CGS only, throttled, `Cache-Control: no-store`). "Appeals in progress" is the Operations "In progress" card
(`CandidacyOperationsQuery`), and the dismissal numbers are the Dismissal List's own counts (`CandidacyDismissalList`); tests prove the three agree.

## Definitions

- **Population**: research candidacies (Coursework excluded, the same setting as the reminders) with a current expiry date that has **not passed** (a student past the expiry date is on the Dismissal List), who are not
  dismissed, completed or inactive/deferred (`StudentStatusReader`).
- **Status category**, exclusive, in this order: **Softbound submitted** > **Appeal in review** (with Supervisor, Chair, CGS or Dean) > **Appeal returned** (waiting for the student) > **No action yet**. "High risk" = No action yet.
- **Days left** = expiry date minus today, as Asia/Kuala_Lumpur calendar dates (the expiry day is 0).
- **Bands** use the reminder milestones' month arithmetic (no month overflow): up to 1 month, 1 to 2 months, 2 to 3 months from today. "Expiring within 3 months" = on or before today + 3 months.

## The page, top to bottom

| Part | What it shows | Click / filter behaviour | How to demo it |
|---|---|---|---|
| Filter bar | Department, Programme level (Master/PhD), Study mode (Full-Time/Part-Time), Status category, Clear | Narrows every number, chart and the list | Pick Computer & Information Sciences + PhD: the KPI cards and charts shrink together |
| KPI cards | Expiring within 3 months; No action yet (high risk); Appeals in progress; Dismissal candidates | The first three highlight their students everywhere (the third also links to Operations); the fourth opens the Dismissal List | Click "No action yet": the list shows only those students and the forecast fades the rest |
| Chart 1: Candidacy expiry forecast | Bars per period stacked by status category; line = running total; Year / Quarter / Month (default Month, the next 12 months) with drill-down (Year > Quarter > Month) | A bar highlights its period; legend items highlight a category; "Drill down into ..." appears when one period is highlighted | Switch to Year, click 2027, "Drill down", then a quarter |
| Chart 2: Expiring within 3 months | Three bands stacked by status category | A bar highlights its band | Click "2 to 3 months" |
| Student list | Students expiring within 3 months: name and ID, department, level, expiry date, days left, status badge, appeal number (link to the appeal page), reminders sent (e.g. "3 mo, 2 mo"), last reminder; search; 10 per page with the shared pagination; closest expiry first, then "No action yet" first | Follows the filters and every highlight above | Type a name; highlight a band; page through |
| Chart 3: Reminders sent | The last 6 months plus this month, bars by milestone (3, 2, 1 month); line = students who submitted the Softbound or an appeal within 30 days after a reminder; response rate | A bar highlights a month, a legend item a milestone; the list shows the students reminded | Click "2-month reminder": only those reminders and students remain |
| Chart 4: Dismissal pipeline | "Candidates not yet exported", "Exported, awaiting Registry" (links to the Dismissal List) and students dismissed per month, last 12 months | A bar highlights a month inside the chart | Upload the Registry sample (see `docs/candidacy-demo-script.md`) and watch it update |

Common behaviour (the same as Workstation Insights): Ctrl+click selects several; chips under the filter bar show every highlight, "Clear all" removes them; "Live · updated HH:MM:SS" with **Pause updates**; the page polls every 15 s, pauses
while the tab is hidden and checks at once when it comes back; filters, highlight, drill level, search, page and scroll position stay across updates; every mark is keyboard-operable and shows its tooltip on focus. At 390 px the cards stack,
year labels shrink and nothing scrolls sideways.

## Demo data

`candidacy:demo-seed` adds candidacy-only demo students (they have no seat or key and Workstation never sees them): 21 active ones expiring in the next 5 months (7 / 6 / 8 per band; 86 expiring in the next 12 months), plus the
dismissal-round students, and six months of reminder history for them (only milestones the engine would not send again, so `candidacy:send-reminders --dry-run` is unchanged). `candidacy:demo-clear` removes all of it.
Workstation numbers (315 / 152 / 157 / 3 / 3), the key counts and every existing demo student's expiry date are unchanged (proved by a test and by the before/after files in `../_backup-before-insights/`).

## Where it needs a shared-file edit

The shared CGS sidebar (`app/Modules/Core/Resources/views/partials/sidebar-cgs-nav.blade.php`, the "Candidacy Management" tree, around line 133) lists Operations and Dismissal List by hand. Until a line is added
`<a href="{{ route('candidacy.cgs.insights') }}" class="nav-subitem @if(request()->routeIs('candidacy.cgs.insights')) active @endif">Insights</a>` between them, Insights appears as "Candidacy Insights" under Actions (our `ProvidesLinks`)
and as the Insights button in the Operations header.
