# Abdul Haziq bin Abdul Farouk (22007428)

GRA Application (+ Extension) · GA Application · Stage Gate Monitoring · Allowance Eligibility

Full scope in `docs/scope/haziq.md`. Built 2026-09-21 — see `TODO.md`'s Haziq
section for exactly what's done, what's deliberately scoped down (TA hours,
the AI chatbot), and what's still open (no automated tests, the
`stage_gate_termination_triggers` hand-off has no consumer yet, Stage 1 isn't
wired to Norhanis' RPD module because it doesn't exist yet).

**Built to the interim report's logic, not the official UTP forms.** A GRA
requisition/extension form and the Stage Gate infopack surfaced during
design describe a different 3-party approval chain and a forfeit-only (not
terminate) consequence for every stage. Both were reviewed and deliberately
set aside in favour of the report — see git log if reconciling with CGS
later changes that decision.

## How this module differs from the standard pattern

1. **GRA Extension reuses `gra_application`**, not a second `module_type` —
   `application_type = 'extension'` + `parent_application_id` on
   `gra_application_details`. GA extension stays entirely Nureen's
   `ga_extension`; this module never touches it.
2. **Stage Gate monitoring is not a `WorkflowModule`.** It's its own tables
   (`stage_gate_rules`, `stage_gates`) plus a daily scheduled command,
   `Support\StageGateEngine` — the single writer of a Stage Gate's status
   columns, the same discipline `WorkflowEngine` enforces for `applications`.
3. **`Research Centre` is a role Core doesn't know about**, declared as a
   plain string in `Support\Roles::RESEARCH_CENTRE` rather than added to
   `Core\Support\Role` — that class is VARCHAR-backed by design specifically
   so a module never has to edit it just to use a role only that module needs.
4. **This module never decides candidature/enrolment status.** A Stage 2+
   grace expiry raises a `stage_gate_termination_triggers` row and notifies
   Admin; it does not terminate anything itself. See that migration's
   docblock for why.

## Haziq's module folder

Everything you build lives in here. You should never need to edit a file
outside this folder, which is what keeps six people out of each other's way.

## Layout

```
Haziq/
├── ModuleProvider.php          register your workflows here
├── routes.php                  your routes, loaded automatically
├── Workflows/                  one class per application type
├── Models/                     your detail tables
├── Http/Controllers/           your controllers
├── Database/Migrations/        your tables only
└── Resources/views/            view('haziq::your.view')
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
