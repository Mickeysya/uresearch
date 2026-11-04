# Study Candidacy pages: parity with Workstation

Workstation is the reference. A candidacy page uses the same shared components (`card`, `card-wide`, `stat-card` with `accent-blue|gold|green|red`, `btn-secondary`, the page header and its actions) and the Workstation page structure: the white card with a search row,
a filter row (labels above the selects, "Apply Filters", "Clear") and the stat cards inside it; the outline button for secondary header actions and the solid one for the primary; a Home button on sub-pages only (`Support\CandidacyHeaderLinks::home()`), none on a landing page.
The values Workstation defines inline (`.cgs-top-card`, `.cgs-search-row`, `.cgs-filters-row`, `.cgs-stats-row` and so on) are copied into `public/css/chloe-candidacy.css`, because the Workstation views must not be edited. Buttons, cards and modals in that file use the shared stylesheet's tokens.

Check it with `/home/chloe/projects/_visual-test/parity/` (`node run.js <label>`; README there): computed styles of the paired components at 1440 and 390 px, with side-by-side screenshots. Not paired (no Workstation equivalent): tabs, waiting-day dots,
the step bar, the appeal page, the memo and the dismissal upload. E-mails use the same `MailMessage` template as the Workstation e-mails, with the same "Dear {name}," greeting and CGS sign-off.
