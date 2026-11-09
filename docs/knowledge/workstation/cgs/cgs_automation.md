What runs on its own, daily, and exactly what each job does. All times are Malaysia time (Asia/Kuala_Lumpur). Everything else in this topic is manual-only.

**workstation:refresh-daily, 7:30 AM daily.** Three things, in order:
1. Starts a termination notice for an occupied seat automatically, but only for three triggers: Dismissal (candidacy status Dismissed), Graduated (candidacy status Completed), or candidacy expiry with no open appeal (a candidacy is expired only from the day AFTER its expiry date; the expiry day itself is still valid). Every other termination reason (deferment, a programme/mode change, withdrawal, de-registration, disciplinary action, unapproved absence) is manual-only -- there is no automatic signal for any of them.
2. Releases any seat whose vacate-by date has passed -- silently, no email, since the student was already told the date when the notice was issued.
3. Cancels locker-key requests that were not collected in time.
This job skips demo students entirely for the auto-release step -- a demo seat's notice is refreshed by a separate seed command instead, never released by this job.

**candidacy:close-expired-appeals, 7:30 AM daily.** Closes an open appeal whose candidacy expiry date has passed (status Closed Expired).

**workstation:send-reminders, 8:00 AM daily.** The locker-key collection reminder (day {{key_collection_reminder_days}}) and the locker-key return reminder, plus other workstation reminder e-mails.

**candidacy:send-reminders, 8:00 AM daily.** The 3, 2 and 1 month candidacy-expiry reminders to students. This is the only candidacy reminder job; the system never dismisses a student automatically.

`candidacy:remind` and `candidacy:generate-dismissals` exist as commands but are placeholders (stubs) and are not scheduled; they do nothing.

**Never automatic:** assigning, reassigning, releasing for a manual reason, setting maintenance, marking a key collected/returned, releasing a hold, or withdrawing a termination notice -- all of these are CGS actions only, on a specific seat, chosen by a person.
