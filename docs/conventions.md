# Conventions

## Ownership

| Path | Owner | May edit |
|---|---|---|
| `app/Modules/Core/**` | the team | by agreement — it affects all six of us |
| `app/Modules/<Name>/**` | that person | only them |
| `database/migrations/**` | the team | framework tables only; put yours in your module |
| `public/css/uresearch.css` | Norhanis | append below the marked line; do not restyle above it |
| `routes/web.php` | nobody | it only redirects `/` — your routes go in your folder |

If you need something from Core, ask. A five-minute conversation beats a
merge conflict across six branches.

## Naming

| Thing | Convention | Example |
|---|---|---|
| Module folder | `PascalCase`, the person's name | `Norhanis` |
| View namespace | folder name lowercased | `norhanis::travel.form` |
| `module_type` | `snake_case`, unique, permanent | `ga_extension` |
| `Stage::key` | `snake_case`, unique within a chain | `cgs_review` |
| `Stage::label` | as CGS says it | `Non-Executive CGS` |
| Route names | `<module>.<action>` | `travel.queue` |
| Detail table | `<module>_details` | `travel_details` |
| Detail partial | leading underscore | `_detail.blade.php` |

`module_type` and `Stage::key` end up in database rows. Renaming one later
means writing a migration to rewrite existing data. Labels are display-only
and safe to reword.

## Rules

**1. Never write `status` or `current_stage` yourself.**
`WorkflowEngine::submit()` and `::decide()`. This is the single most important
rule — it is what stops the modules drifting apart again.

**2. Never add columns to Core's tables.**
`users`, `applications`, `approval_history`, `application_documents`. Your
module gets its own table keyed by `application_id`.

**3. Every query is scoped.**
A student sees only their own applications. An approver sees only the stage
they own. `queueFor()` and the `role:` middleware do this for you — do not
write a bare `Application::all()`.

**4. Escape everything.**
Blade's `{{ }}` escapes; `{!! !!}` does not. Only ever use `{!! !!}` on markup
you generated yourself, never on anything a user typed.

**5. Validate every input.**
`$request->validate()` at the top of every `store()`. Never read `$_POST`.

**6. Derive, don't trust.**
If a value can be computed from other inputs, compute it server-side. Travel
duration is derived from the dates rather than accepted from the form.

**7. Uploads go through `DocumentStore`.**
Never `move_uploaded_file()`, never write under `public/`.

**8. No secrets in code.**
Credentials belong in `.env`, which is git-ignored. Read them with `config()`.

## Git

One branch per person: `feature/<name>-<module>`, e.g. `feature/nureen-attendance`.

Because you only touch your own folder, conflicts should be rare. If you hit
one in `Core/`, stop and talk to the team rather than resolving it alone.

Do not commit `.env`, `vendor/`, `node_modules/`, or anything under
`storage/app/uploads`.

## Blade

- Extend `core::layouts.app` for signed-in pages, `core::layouts.guest` for auth.
- Reuse the existing classes: `.card`, `.card-wide`, `.app-item`, `.stat-card`,
  `.status-badge`, `.empty-state`, `.stepper`. Norhanis' palette is in `:root`.
- New shared styling goes **below** the marked line at the bottom of
  `uresearch.css`, so her original sheet stays intact and reviewable.
