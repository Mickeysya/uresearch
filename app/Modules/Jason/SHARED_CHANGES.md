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

**Status:** both proposed by Hani, in `app/Modules/Hani/SHARED_CHANGES.md`
items 3 and 4. Not duplicated here — hers is the proposal of record, and the
exact file contents live there.

- **`SuppliesReleasedExaminers`** — this module *reads* it, through
  `Jason\Support\ReleasedPanels`. It replaces the spreadsheet emailed from
  the Senior Executive's desk to the Non-Executive's. Until the file exists
  nothing is bound, the import screen says the hand-off is not switched on,
  and the spreadsheet remains the way in.

- **`SuppliesAppointmentDispatch`** — this module *implements* it, as
  `Jason\Support\AppointmentDispatch`, and `ModuleProvider::register()` binds
  it only once `interface_exists()` says the file is there. Binding a class
  to a missing interface is a fatal error on every request in the
  application, not just this module's pages.

**One difference from Hani's item 3, deliberate.** Her write-up has the
reader gate on `interface_exists(...)` *and* the container binding. This
module gates on the **binding alone**:

```php
public static function available(): bool
{
    return app()->bound(self::CONTRACT);
}
```

A container key is just a string, so asking whether anything is bound
answers the only question that matters — is there something to read? — and
answers it *before* the Core file exists, which is what makes the live path
testable today rather than after Core changes. Nothing in this module
type-hints the contract; it resolves an object and calls one method on it.
Adding `interface_exists()` would gate this module's own behaviour on a file
in a folder it does not own, for no safety in return.

The rule is not the same on the implementing side, which is why
`ModuleProvider::register()` *does* check `interface_exists()`: there the
interface name is the bind target, so a missing file really is fatal.

---

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
