# Abdul Haziq bin Abdul Farouk (22007428)

GRA Application · GA Application · Stage Gate Monitoring · Allowance Eligibility

Full scope in `docs/scope/haziq.md`.

## Read this before you start

Three things about this scope do not fit the usual module pattern, and all
three are worth settling with the team first — see `TODO.md`:

1. **GRA/GA overlaps with modules Nureen has already built.** `ga_extension`
   and `ga_certification` exist and work. Agree ownership before writing a
   competing chain.
2. **Stage Gate monitoring is not a `WorkflowModule`.** The engine models a
   chain of approvals that terminates. A stage gate is a recurring deadline
   check with suspend / recover / terminate outcomes against an already
   approved record — closer to Nureen's `supervision:remind-stalled` command
   or Attendance's non-workflow half than to a chain.
3. **Your chains name roles the portal does not have** (`GRS Exec`,
   `Research Centre`). Adding one is a single line in
   `App\Modules\Core\Support\Role` — but map onto the existing roles first
   where they genuinely mean the same person.

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
