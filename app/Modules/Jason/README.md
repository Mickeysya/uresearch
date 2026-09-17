# Jason's module folder

Everything you build lives in here. You should never need to edit a file
outside this folder, which is what keeps six people out of each other's way.

## Layout

```
Jason/
├── ModuleProvider.php          register your workflows here
├── routes.php                  your routes, loaded automatically
├── Workflows/                  one class per application type
├── Models/                     your detail tables
├── Http/Controllers/           your controllers
├── Database/Migrations/        your tables only
└── Resources/views/            view('jason::your.view')
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

## Forms

Every screen here that a human fills in is a stepper: the form carries
`data-stepper`, each group of fields is a `<fieldset class="fstep"
data-label="...">`, and `@include('core::partials.form-stepper')` goes after
the form. That is the whole contract. The rail, the Back/Continue buttons,
the counter and the generated review step are all built by the partial, and
no controller knows about any of it: the form still posts once, to the same
route, with the same fields.

Converted 2026-09-17: Hardbound Submission (3 steps), Appeal (3), Panel
Nomination (2), CGS Pack Preparation (3).

Two things to keep in mind when you add a field:

- Give every control an `id` and its label a matching `for`. The review step
  reads labels off the form, and a control without one is listed by its raw
  `name` attribute.
- Any inline `<script>` must be `<script @cspNonce>`, and anything it loads
  must come from `public/js`, never a CDN. `script-src` is `'self'` plus a
  nonce with no `unsafe-inline`, so a bare tag is silently refused and the
  page still returns 200. `ContentSecurityPolicyTest` scans every Blade file
  for this now.

## The three rules

1. **Only edit files inside this folder.** If you think you need to change
   something in `Core/`, raise it with the team first — it affects all six of us.
2. **Your `module_type` key must be unique.** Check `docs/module-keys.md`
   before claiming one. The registry will throw on a duplicate.
3. **Never write `status` or `current_stage` yourself.** Call
   `WorkflowEngine::submit()` and `::decide()`. That is the whole point of the
   engine — it is the one place those columns change.

See `docs/adding-a-module.md` for the full walkthrough.
