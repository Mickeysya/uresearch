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

**Gender is a record, not a student preference.** It used to be student
self-service (pick it on first visit, change it any time); there is now no
student-facing route for it at all — `WorkstationController` has no
`setGender()` method and `routes.php` has no `workstation.gender.set`. CGS
sets it, one student at a time, from the "Student Genders" table on
`Cgs\WorkstationOperationsController::index()`; a student with none set yet
gets a "contact CGS" message instead of a picker. See `tests/Feature/
WorkstationTest.php`.

**Room photos.** `workstation_locations.photo_path` is nullable and almost
always null — nobody photographed each of the ten rooms on the reference
seat map — so `WorkstationLocation::photoUrl()` falls back to one shared
illustration (`public/images/workstation/room-placeholder.svg`) whenever
`photo_path` is unset. CGS can attach a real photo per room later by setting
that column; nothing else changes.

**Block photos.** Home (`workstation.select`) shows a photo card per block,
one level up from the room photos above and following the same fallback
shape. `workstation_blocks` is its own small table (`code` unique, `name`,
nullable `photo_path`) seeded with the two real blocks on the reference seat
map, `J2` and `N2`; `WorkstationBlock::photoUrl()` falls back to
`public/images/workstation/block-placeholder.svg` whenever `photo_path` is
unset, same pattern as the room illustration but visually distinct (a
building, not a desk). `WorkstationController::index()` drives the block
list from `WorkstationLocation`'s distinct `block` values, not from which
`WorkstationBlock` rows happen to exist, so a block with rooms but no
`WorkstationBlock` row yet still gets a card (generic name, placeholder
photo) instead of silently vanishing from Home; a `WorkstationBlock` row
with no rooms yet is correspondingly left off Home. See
`tests/Feature/WorkstationTest.php`.

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
  Student → Supervisor → Department Chair → CGS Verification → Dean of PGR —
  and reuses the existing stage-key pairings already reserved for exactly
  this chain in `docs/module-keys.md`. "Department Chair" reuses `Role::CHAIR`
  (see that doc's actor table; CGS confirmation is still outstanding).
  The submission form mirrors CGS's actual paper appeal template — Section A
  (phase + RCS), B (prior GSC/VC extension), C (repeatable publications
  list, its own `candidacy_appeal_publications` table), D (extension
  requested) and a required disclaimer checkbox — added on top of the
  original simpler reason/months shape via an additive migration
  (`..._add_appeal_form_sections_to_candidacy_appeal_details_table`); every
  new column is nullable/defaulted so the one appeal submitted through the
  earlier form still loads. There is no document upload on this form — the
  Section A-D fields are the complete submission. The form also carries
  Department (pre-filled from the student's own profile, editable, stored on
  this appeal's own row rather than written back to `users.department` —
  same reasoning as `supervisor_id`) and a now-mandatory Reason for Appeal,
  matching what UTP's actual appeal memo requires against its signature
  block and letter body. Student Name needs no field since it's already the
  application's own student relation; Student ID is shown too, read-only
  (`users.matric_no`, not submitted — nothing about an appeal form should
  let someone overwrite their own matric number). See
  `tests/Feature/CandidacyAppealTest.php`.
- **On Dean approval**, `CandidacyAppealController::generateApprovalMemo()`
  renders UTP's official "Appeal for Extension of Candidacy" memo as a PDF
  (`Resources/views/candidacy/appeal/memo_pdf.blade.php`, Dompdf, same
  `Pdf::loadView()->output()` + `DocumentStore::storeGenerated()` pattern
  Nureen's GA/GRA certification letter already uses) and attaches it to
  `CandidacyAppealApproved`. Every field on the memo already exists by then:
  the letter body is the student's own Reason for Appeal, the routing lines
  are the selected Supervisor and whoever actually endorsed at the Chair
  stage (read off `approval_history`, not assumed), and the decision line
  only ever prints "Approved" because the method only ever runs from here.
  The saved filename is `{Student Name}_{Student ID}_Memo - Appeal for
  Extension Candidacy.pdf`, exactly, not a doc-type-derived slug.
  The font is Tahoma, not Dompdf's default DejaVu Sans — confirmed by
  reading `w:rFonts` straight out of the source `.docx`'s `document.xml`
  (96 of 97 text runs), not guessed from how the rendered PDF looks. Dompdf
  doesn't ship it, so the two TTFs are committed under this module's own
  `Resources/fonts/` and `registerMemoFont()` registers them into Dompdf's
  font cache (`storage/fonts/`, gitignored, created on demand if missing)
  before every render.
  Caught two real bugs building this. `MailMessage::attachFromStorageDisk()`
  does not exist — that method is on `Mailable`, not the `MailMessage` a
  Notification's `toMail()` returns — so the attachment silently landed in
  `failed_jobs` every time; the fix is `MailMessage::attach(Attachment::
  fromStorageDisk(...))`. Nureen's `CertificationIssued` has the identical
  call and likely the identical bug, not touched here since it's her
  module — flagging it rather than fixing it. Separately,
  `Dompdf\FontMetrics::registerFont()` assumes `font_dir` already exists and
  fails with an unhelpful `TypeError` (not a clear "directory missing") when
  it doesn't — `registerMemoFont()` now creates it first.

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

## Study Candidacy Appeal (rebuilt)

The appeal no longer runs on the shared WorkflowEngine: it has its own tables, state machine (`Services/CandidacyAppealService`), policy
(`Policies/CandidacyAppealPolicy`), memo (`Services/CandidacyMemoService`) and Approval Portal. See `docs/candidacy-appeal-inventory.md`.
The older sections of this README that mention `CandidacyAppealController` describe the replaced flow.

All appeal pages (student, Approval Portal, Operations) share one appeal page, `/candidacy/appeals/{id}`; see the inventory for the tabs, Operations cards, return path and live updates.

Handbook alignment (2025): the chair step is displayed as "Department Chair"; the expiry day is still a valid day to appeal; the Dean's rejection needs reasons; Operations has four cards. The planned (not built) items are in `docs/candidacy-appeal-inventory.md` section 13.

Pagination: every candidacy list uses one bar (`candidacy/wca/_pagination.blade.php`, `Support/CandidacyPagination`), the Workstation seat-list look with a 7-slot window; rules in `docs/candidacy-appeal-inventory.md` section 15.

Departments, roster, department auto-fill, the single Dean and the one My Signature page are described in `docs/candidacy-appeal-inventory.md` section 16.

Reminders (`candidacy:send-reminders`, 08:00), the student status reader and the Dean's two-step rejection are described in `docs/candidacy-appeal-inventory.md` section 17; `candidacy:remind` is deprecated and does nothing.

## Daily schedule (Asia/Kuala_Lumpur)

Two moments each morning. **07:30 REFRESH** changes data only; **08:00 REMINDERS** sends e-mail and bell notices. Every notice that a scheduled job owes a student goes out at 08:00:
the state change is made at 07:30 and its notice is queued with a delay until 08:00 the same day (`Support\ScheduledDelivery`). A run after 08:00 (manual, or a late scheduler) delivers at once;
page loads and people's own actions are never delayed. Running a job twice sends nothing twice (`reminder_sent_at`, `return_reminder_sent_at`, `candidacy_reminder_log`).

| Time | Command (defined in `ModuleProvider`) | What it does |
|---|---|---|
| 07:30 | `candidacy:close-expired-appeals` | closes returned appeals whose candidacy expired (student notice held until 08:00) |
| 07:30 | `workstation:refresh-daily` | key collection auto-cancel (day 7), vacate-period auto-release, candidacy-expiry / dismissal termination triggers (termination notice held until 08:00) |
| 08:00 | `workstation:send-reminders` | key collection reminder (day 3) and key return reminder |
| 08:00 | `candidacy:send-reminders` | the 3 / 2 / 1 month candidacy expiry reminders |

Older names still work: `workstation:process-releases` and `workstation:remind-locker-keys` run the refresh part and then the reminders part (inside the refresh context, so nothing they send
before 08:00 goes out early); `candidacy:remind` is a deprecated no-op. `routes/console.php` is shared and still lists the older entries (07:00 `candidacy:remind`, 07:30
`workstation:process-releases`, 08:00 `workstation:remind-locker-keys`); they are harmless duplicates and the owner of that file can delete them. Other modules' own jobs (e.g. `rpd:remind`,
`supervision:remind-stalled`) are not ours.

## Programme catalogue

`config/candidacy.php` → `programmes` is the single list of programme names, each with a level (`coursework`, `master_research`, `phd`) and a department. `CandidacyDepartmentResolver` maps an exact
catalogue name to its department first; text that is not in the catalogue (real students) falls back to the keyword patterns and then a hash. Department names: Chemical Engineering; Civil &
Environmental Engineering; Electrical & Electronic Engineering; Mechanical Engineering; Petroleum Engineering; Geoscience; Computer & Information Sciences; Fundamental & Applied Sciences;
Management & Humanities. **Demo programme text comes from the seeders** (`workstation:demo-seed`), never typed by hand, so the demo-clear commands and re-seeding never bring old text back.

## Dismissal process

The Dismissal List (Candidacy Management) is live: students whose candidacy has expired (rule and Handbook references in `docs/candidacy-appeal-inventory.md` section 18). CGS exports it to Excel; the Registry
processes it elsewhere; the Registry or CGS uploads the result on **Dismissal Upload** (preview, then a confirmation dialog) and those students are marked dismissed. No keep status, no batch lock. Demo:
`candidacy:demo-seed`, `candidacy:demo-registry-file`, `docs/candidacy-demo-script.md`. Candidacy-only demo students (`demo_student_profiles.candidacy_only`) are invisible to Workstation.

## Candidacy Insights

`/cgs/candidacy/insights` (CGS only): operational monitoring of the known expiry dates (a projection, not a prediction). One statistics class (`Support\CandidacyStatistics`), the Workstation Insights look and mechanisms,
live update. See `docs/candidacy-insights.md`.

