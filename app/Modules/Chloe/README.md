# Chloe's module folder

Everything you build lives in here. You should never need to edit a file
outside this folder, which is what keeps six people out of each other's way.

## Layout

```
Chloe/
├── ModuleProvider.php          register your workflows here
├── routes.php                  your routes, loaded automatically
├── Workflows/                  one class per application type
├── Models/                     your detail tables
├── Http/Controllers/           your controllers
├── Database/Migrations/        your tables only
└── Resources/views/            view('chloe::your.view')
```

## Getting started

Copy `app/Modules/Norhanis` — it is a complete, working example of every
piece. Then:

1. Write a `Workflows/YourWorkflow.php` implementing `WorkflowModule`.
   Declare your approval chain as `Stage` objects; the engine does the routing.
2. Register it in `ModuleProvider::boot()`.
3. Add a migration for your **detail table only**. Never touch `users`,
   `applications`, `approval_history` or `application_documents` — those
   belong to Core, and changing them breaks everyone.
4. Add a controller using the `ApprovesApplications` trait. You get
   approve/reject, auditing, authorisation and notification emails for free.
5. Add routes and views.

Run `php artisan migrate` and your links appear in the sidebar by themselves.

## The three rules

1. **Only edit files inside this folder.** If you think you need to change
   something in `Core/`, raise it with the team first — it affects all six of us.
2. **Your `module_type` key must be unique.** Check `docs/module-keys.md`
   before claiming one. The registry will throw on a duplicate.
3. **Never write `status` or `current_stage` yourself.** Call
   `WorkflowEngine::submit()` and `::decide()`. That is the whole point of the
   engine — it is the one place those columns change.

See `docs/adding-a-module.md` for the full walkthrough.

## Workstation Management is the exception

It is not an approval chain, so it does not implement `WorkflowModule` and
never touches `WorkflowEngine` or an `Application` row — it is a real-time
seat booking system with its own tables (`workstation_locations`,
`workstations`, `workstation_requests`, `locker_keys`), guarded against a
double-booking race by `Services\WorkstationAllocator` (a row lock inside a
transaction). See `Http/Controllers/WorkstationController.php` and
`Http/Controllers/Cgs/` for the student and CGS-staff sides.

Because it registers no `WorkflowModule`, it cannot reach the sidebar or the
dashboards through `ModuleRegistry`/`ProvidesLinks` the way every other
module here does — those two links were added by hand to
`sidebar-student-nav.blade.php` and `sidebar-cgs-nav.blade.php` (the only
edits this module makes outside this folder, besides one line in
`routes/console.php` for the locker-key reminder and one seed method in
`database/seeders/DatabaseSeeder.php`, both following the pattern Nureen and
Hani already set for those two files).

## Study Candidacy Reminder / Appeal / Dismiss

Three processes, one connected story around `study_candidacies` (this
module's own table — see the overlap note in `docs/module-keys.md` for why
it isn't a shared Core table with Norhanis' unbuilt RPD trio).

- **Reminder** and **Dismiss** are not `WorkflowModule`s — no `Application`
  rows, just their own tables (`study_candidacy_reminders`,
  `candidacy_dismissals`) and a daily scheduled command each
  (`candidacy:remind`, `candidacy:generate-dismissals`), same shape as
  Workstation's locker-key reminder. `Services\DismissalGenerator` holds the
  one eligibility rule both the command and CGS's on-demand "Refresh List"
  button call.
- **Appeal** (`candidacy_appeal`) *is* a real `WorkflowModule` —
  Student → Supervisor → Programme Chair → CGS Verification → Dean of PGR —
  and reuses the existing stage-key pairings already reserved for exactly
  this chain in `docs/module-keys.md`. "Programme Chair" reuses `Role::CHAIR`
  (see that doc's actor table; CGS confirmation is still outstanding).
  The submission form mirrors CGS's actual paper appeal template — Section A
  (phase + RCS), B (prior GSC/VC extension), C (repeatable publications
  list, its own `candidacy_appeal_publications` table), D (extension
  requested) and a required disclaimer checkbox — added on top of the
  original simpler reason/months shape via an additive migration
  (`..._add_appeal_form_sections_to_candidacy_appeal_details_table`); every
  new column is nullable/defaulted so the one appeal submitted through the
  earlier form still loads. The official appeal form upload is optional, not
  required, per the digitised spec. See `tests/Feature/CandidacyAppealTest.php`.

**Two Core changes, both additive, both covered by `tests/Feature/WorkflowReturnTest.php`:**
`WorkflowEngine::decide()` gained a third `'return'` outcome alongside the
existing approve/reject (`Application::STATUS_RETURNED`, plus a new
`WorkflowEngine::resubmit()` that puts a returned application back on the
*same* stage rather than restarting the chain), and
`<x-core::decision-form>` gained an opt-in `:allow-return="true"` prop.
Every existing call site is unchanged — nobody else passes the new prop or
decision value, so no other module's queue changed shape. This was
TODO.md's own "return with comment" item, needed by Jason's Hardbound review
too; his controller can opt in the same way this one's
`Http/Controllers/CandidacyAppealController.php` does, without waiting on a
second Core change.

This module deliberately does **not** use the shared
`ApprovesApplications::decide()` (it only accepts approve/reject) — the
controller has its own `decide()` that also accepts `return`, and uses only
`queueFor()` from the trait. It also has its own queue view
(`Resources/views/candidacy/appeal/queue.blade.php`) rather than
`core::partials.queue`, because that shared partial doesn't pass
`allow-return` through — same markup and components, so it looks identical.

**Return vs Reject are different outcomes, not two names for the same
thing.** Return reopens the *same* application for editing on the same
stage (`edit()`/`resubmit()`, only while `status === RETURNED`) — the
student can fix what was flagged and it re-enters the queue where it left
off. Reject is final: `applyRejection()` stamps
`study_candidacies.last_rejection_at`, and `StudyCandidacy::canAppeal()`
then permanently blocks every future appeal for that candidacy, regardless
of remaining 12-month allowance — a rejection at *any* stage (Supervisor,
Chair, CGS Verification or Dean), not only the Dean's. `show()` is a third,
separate action: a read-only view of the student's own appeal that's always
reachable no matter the status, so "can I still see what I submitted" never
depends on whether it's editable.

**Route-ordering gotcha, worth restating here because it's bitten this
project before:** `GET /candidacy-appeal/{application}` (the `show()` route)
is registered *after* the static `GET /candidacy-appeal/queue` route, even
though they live in different role-gated `Route::group()` blocks — Laravel
matches routes in registration order regardless of which group they're in,
so a dynamic segment at the same depth must always be added after every
static route at that depth, or it swallows the static one first (a student
route can't reach an approver-only route anyway, but the queue route needs
to bind before the dynamic one gets a chance to).
