# Proposed changes to shared files

Jason's modules — Hardbound Submission, Appeal Hardbound Submission and
Appointment Letter — do not edit shared files (`Core/`, `routes/console.php`,
`bootstrap/app.php`, `composer.json`, shared CSS, sidebar partials, `docs/`,
other members' modules). Anything that would need one is written here
instead, for the team to review and apply. Every module works without them.

---

## 1. The Dean approves twice for the same panel

**Status:** a question for CGS, not a code change. Nothing is blocked on it.

**What happens now.** The Dean of PGR holds a stage in two chains for the
same set of examiners:

| Chain | Stage | What the Dean is approving |
|---|---|---|
| Examiner Nomination (Hani) | `dean` | the proposed list of examiners |
| Appointment Letter (Jason) | `dean` | the letters written to those examiners |

So for one candidate the Dean approves the panel, the Senior Executive
finalises and releases it, CGS prepares the letters, and the Dean approves
again. The second approval is not redundant on paper — it is what puts the
Dean's signature onto each letter, and the letters carry terms the list
does not (the six-week deadline, the honorarium, travel entitlements) — but
nobody has confirmed that CGS actually wants two separate approvals rather
than one.

**Why it is written here rather than fixed.** Removing either stage changes
a module's `stages()`, and `Stage::key` values are stored in
`approval_history` rows and are permanent. It is also not one person's call:
the stage being dropped would be in one folder and the consequence in
another.

**What is needed.** A decision from CGS:

- **Keep both** — no change. Worth a line in each queue saying what this
  particular approval covers, so the Dean knows which one they are looking
  at.
- **One approval only** — then the appointment letters need the Dean's
  signature without the Dean holding a stage, and the question becomes
  which approval the signature is taken from.

**Impact either way.** None on anyone else's module. Jason's `dean` stage is
unchanged until CGS answers.

---

## 2. Core contracts this module reads and implements

**Status: applied.** Both interfaces are now in `app/Modules/Core/Contracts/`,
proposed in `app/Modules/Hani/SHARED_CHANGES.md` items 3 and 4. Nothing further
is needed from the team; this entry stays as the record of what was agreed.

- **`SuppliesReleasedExaminers`** — this module *reads* it, through
  `Jason\Support\ReleasedPanels`. It replaces the spreadsheet emailed from the
  Senior Executive's desk to the Non-Executive's. The spreadsheet import remains
  as the manual fallback, for a panel released before the hand-off existed and
  for a checkout where the nomination module is not installed.

- **`SuppliesAppointmentDispatch`** — this module *implements* it, as
  `Jason\Support\AppointmentDispatch`, bound in `ModuleProvider::register()`.

**One difference from Hani's item 3, still deliberate.** Her write-up has the
reader gate on `interface_exists(...)` *and* the container binding. This module
gates on the **binding alone**:

```php
public static function available(): bool
{
    return app()->bound(self::CONTRACT);
}
```

Core having the interface says only that the contract exists. It is the
nomination chain *binding an implementation* that means there is something to
read — a checkout without that module has the file and nothing behind it. So
the binding is the question worth asking, and it was also what made the live
path testable before the file landed.

Both sides type-hint the interface properly now that it exists.

## 3. The examiner list export's column layout

**Status:** no change needed, recorded so neither side breaks the other.

The appointment letter importer reads the Examiner Report export by column
name, case- and spacing-insensitively, in any order, ignoring anything it
does not recognise. Its key column is **"Application No."** — the full stop
is part of the real heading, and `AppointmentSheet::normalise()` now strips
trailing punctuation so it matches either way.

`tests/Feature/Jason/NominationExportHeadingsTest.php` holds that export's
heading row as literal strings, copied rather than imported, and fails if
either side renames a column without telling the other. Columns added at the
end are safe and need no change here.
