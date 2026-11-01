# To confirm with CGS (living list)

Things the system does today on an assumption. Each has the current behaviour, so it can be changed in one place once CGS answers. Tick items off here when confirmed.

| # | Question | What the system does now | Where to change it |
|---|---|---|---|
| 1 | The Dean's rejection reason list | The six starting reasons: research progress insufficient; completion plan not realistic; supporting documents insufficient or invalid; reason for the delay not acceptable; previous extension not used as planned; Other (remark required) | `config('candidacy.reasons.dean.reject')` |
| 2 | The source of the 12-month cap on extensions | 12 months in total across all appeals of a candidacy (confirmed in requirement gathering; the Handbook sections read so far do not state the number) | `config('candidacy.max_extension_months')` |
| 3 | Dean versus the Graduate Studies Academic Committee (Handbook 2.1.1) | The Dean of Postgraduate and Research decides; there is no committee step | the approval chain in `CandidacyAppealWorkflow` / `CandidacyAppealService` |
| 4 | May Coursework students appeal and receive reminders? Handbook 2.1.1 says "a student" | They receive no reminders (research students only). The appeal form itself does not check the programme type | `config('candidacy.reminders.programme_types')` |
| 5 | Do reminders continue after a Dean rejection, or after the 12 months are used? | Yes: a Softbound-only reminder (variant B), because the student can still submit before the expiry date | `config('candidacy.reminders.send_when_appeal_blocked')` |
| 6 | Urgent reminders (3 days and 1 day before the expiry date) | Not built | see "Improvements to discuss with CGS" in the inventory |
| 7 | The day of the month the dismissal list goes to the Registry | Not decided; the list is generated daily and CGS reviews it | the dismissal job schedule |
| 8 | Is the Softbound status final once submitted, and what exactly makes a student "inactive" or "deferred"? | The reader treats Softbound submitted, completed, inactive/deferred and dismissed as ending the reminders | `Support\StudentStatusReader` |
| 9 | The shared Softbound table: name, student ID, submitted date and status fields (from the module owner, not CGS) | Not read yet; the demo overlay is used for demo students only | `config('candidacy.status_source_table')`, then `candidacy.status_source` |
