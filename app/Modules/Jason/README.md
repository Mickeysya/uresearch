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
Nomination (2), CGS Pack Preparation (3), Add an Examiner (2).

**My Signature is deliberately not one.** One file input; a wizard would make
it "Step 1 of 2" where step 2 reviews a filename. It got a layout pass
instead: the upload is a full-width drop target (a `<label>` wrapping a
hidden input — still works with script off), and a picked file previews on
the same white plate the Confirmation prints it onto, so you can see the scan
is the right way up before saving. The preview reads as a **`data:` URI, not
a `blob:` one** — `img-src` is `'self' data:`, so `URL.createObjectURL` would
be refused by the CSP and the preview would silently never appear.

Two things to keep in mind when you add a field:

- Give every control an `id` and its label a matching `for`. The review step
  reads labels off the form, and a control without one is listed by its raw
  `name` attribute.
- If your form does **not** file an application, say so with
  `data-stepper-review="..."` on the `<form>`. The generated review's default
  line is "Once submitted it goes to the first approver and you cannot edit
  it", which is true of every application form here and false on Examiner
  List, which only adds a row to a list this module keeps for itself.
- Any inline `<script>` must be `<script @cspNonce>`, and anything it loads
  must come from `public/js`, never a CDN. `script-src` is `'self'` plus a
  nonce with no `unsafe-inline`, so a bare tag is silently refused and the
  page still returns 200. `ContentSecurityPolicyTest` scans every Blade file
  for this now.

## Appointment Letters: the two examiner screens

*Nominate Examiner Panel* and *Examiner List* are both in the Chair's sidebar
and they are not the same job. The list is the only screen that can add,
remove or reinstate a `PoolExaminer`; the nomination form only picks from it.

The list itself is three pages' worth of behaviour across two routes, and
the split is deliberate:

| Route | What it is |
|---|---|
| `appointment-letter.examiners` | the list, full width, with an "Add an examiner" button |
| `appointment-letter.examiners.create` | the add form, a 2-step wizard on its own page |

**Not tabs, and not one stacked page.** Adding is an action, not a second
view of the list, so a tab strip would be labelling it wrong. A tab holding a
form also throws away your typing the moment you look at the list. And one
stacked page does not work at all: `form-stepper` re-casts the whole `.card`
it finds the form in, so the page heading and "Step 1 of 3" end up hoisted
above the full table with the wizard appended underneath. Same shape as
Hani's examiner pool, `/examiners` and `/examiners/new`.

The link between them is the part worth knowing about. "Add the examiner
first" points straight at the *create* page (you already know they are not on
the list — that is why you clicked) and carries `?return=nominate`.
`ExaminerPoolController::store()` honours it by redirecting back to the
nomination form instead of the list, re-checking the role first, because CGS
keep the same list and cannot open a Chair-only route. The half-picked panel
is held in `sessionStorage` by the script at the foot of
`appointment_letter/form.blade.php` and restored once on the way back in. No
drafts table, no session key, no resume route.

Note that `appointment_examiner_pool` is **not** Hani's `examiners`. Two
lists on purpose (`TODO.md`, "Dependencies") — nominations snapshot the rows
they pick, so a column change on her side can never rewrite a letter already
issued. The price: an examiner added here gets no eligibility check. Her
on-gap / assigned / unavailable states do not reach this list.

## House style

Two rules from `docs/conventions.md` that this module is the reference for,
because both landed here first:

- **An empty state ends in a button, not a hyperlink.** `.btn` filled for the
  one real action, `.btn-secondary` outlined for an alternative. Both already
  work on an `<a>`; no new CSS. A link inside a sentence stays a link.
- **No em dash as a sentence connector.** Use a comma, a semicolon or a full
  stop. The en dash in a range and the bare `'—'` in an empty table cell are
  fine. `tests/Feature/Core/ProseTest.php` fails the build otherwise.

## The three rules

1. **Only edit files inside this folder.** If you think you need to change
   something in `Core/`, raise it with the team first — it affects all six of us.
2. **Your `module_type` key must be unique.** Check `docs/module-keys.md`
   before claiming one. The registry will throw on a duplicate.
3. **Never write `status` or `current_stage` yourself.** Call
   `WorkflowEngine::submit()` and `::decide()`. That is the whole point of the
   engine — it is the one place those columns change.

See `docs/adding-a-module.md` for the full walkthrough.
