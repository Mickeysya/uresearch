What runs on its own, daily, and exactly what each job does. Everything else in this topic is manual-only.

**workstation:process-releases, 7:30 AM daily.** Three things, in order:
1. Starts a termination notice for an occupied seat automatically, but only for three triggers: Dismissal (candidacy status Dismissed), Graduated (candidacy status Completed), or candidacy expiry with no open appeal. Every other termination reason (deferment, a programme/mode change, withdrawal, de-registration, disciplinary action, unapproved absence) is manual-only -- there is no automatic signal for any of them.
2. Releases any seat whose vacate-by date has passed -- silently, no email, since the student was already told the date when the notice was issued.
3. Sends locker-key return reminders.
This job skips demo students entirely for the auto-release step -- a demo seat's notice is refreshed by a separate seed command instead, never released by this job.

**workstation:remind-locker-keys, 8:00 AM daily.** The locker-key collection reminder (day {{key_collection_reminder_days}}) and automatic cancellation (day {{key_collection_days}}) for Pending Collection keys.

**candidacy:remind, 7:00 AM daily**, and **candidacy:generate-dismissals, 7:15 AM daily** -- candidacy-side jobs that run before workstation:process-releases, so a dismissal or expiry they create the same morning is already visible to it.

**Never automatic:** assigning, reassigning, releasing for a manual reason, setting maintenance, marking a key collected/returned, releasing a hold, or withdrawing a termination notice -- all of these are CGS actions only, on a specific seat, chosen by a person.
