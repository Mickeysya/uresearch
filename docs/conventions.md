# Conventions

## Ownership

| Path | Owner | May edit |
|---|---|---|
| `app/Modules/Core/**` | the team | by agreement — it affects all six of us |
| `app/Modules/<Name>/**` | that person | only them |
| `database/migrations/**` | the team | framework tables only; put yours in your module |
| `public/css/uresearch.css` | Norhanis | do not edit — her original sheet |
| `public/css/dashboard.css` | the team | put new shared styling here |
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

**2. `stages(null)` must return every stage you can ever use.**
The registry calls it that way to build the sidebar and gate the approval
queues. If a conditional branch adds stages, the null case must include them,
or the roles that own those stages get no queue at all.

**3. Never add columns to Core's tables.**
`users`, `applications`, `approval_history`, `application_documents`. Your
module gets its own table keyed by `application_id`.

**4. Every query is scoped.**
A student sees only their own applications. An approver sees only the stage
they own. `queueFor()` and the `role:` middleware do this for you — do not
write a bare `Application::all()`.

**5. Escape everything.**
Blade's `{{ }}` escapes; `{!! !!}` does not. Only ever use `{!! !!}` on markup
you generated yourself, never on anything a user typed.

**6. Validate every input.**
`$request->validate()` at the top of every `store()`. Never read `$_POST`.

**7. Derive, don't trust.**
If a value can be computed from other inputs, compute it server-side. Travel
duration is derived from the dates rather than accepted from the form.

**8. Uploads go through `DocumentStore`.**
Never `move_uploaded_file()`, never write under `public/`.

**9. No secrets in code.**
Credentials belong in `.env`, which is git-ignored. Read them with `config()`.

## Git

One branch per person: `feature/<name>-<module>`, e.g. `feature/nureen-attendance`.

**After every pull or branch switch, run `./sync.sh`.** A pull can leave your
checkout in a state the app cannot run in, and none of it is obvious:
`composer.lock` may have changed, a teammate may have added a key to
`.env.example` that your git-ignored `.env` does not have, there may be pending
migrations, compiled Blade views from the previous branch are still being
served, and `queue:work` holds the app in memory so the queue worker is still
running pre-pull code. `./sync.sh --check` reports all of that without changing
anything.

Because you only touch your own folder, conflicts should be rare. If you hit
one in `Core/`, stop and talk to the team rather than resolving it alone.

Do not commit `.env`, `vendor/`, `node_modules/`, or anything under
`storage/app/uploads`.

## Blade

- Extend `core::layouts.app` for signed-in pages, `core::layouts.guest` for auth.
- Reuse the existing classes: `.card`, `.card-wide`, `.app-item`, `.stat-card`,
  `.status-badge`, `.empty-state`, `.stepper`. Norhanis' palette is in `:root`.
- New shared styling goes at the bottom of **`public/css/dashboard.css`**.
  `uresearch.css` is Norhanis' original and is not edited at all now, so it
  stays reviewable. To override a rule she wrote, append a new one — and
  scope it with at least two classes, because `uresearch.css` contains broad
  element rules like `.sidebar a` (0,1,1) that outrank a single class.
- The four sheets load in a fixed order: `uresearch` → `layout` → `sidebar` →
  `dashboard`. Do not reorder the `<link>` tags; the cascade depends on it.
- Charts are Chart.js. Include `core::dashboard.partials.chartjs` and the
  shared tooltip, defaults and data-label plugin come with it.
- Blade's directive regex is `\B`-anchored, so two directives written back to
  back as `@endif@if` silently fail to compile the second one. Put a newline or
  a non-word character between them — `}}@if` and `>@endif` are both fine.
- **Check tag balance after editing a Blade partial.** A single stray `</div>`
  leaks the rest of the panel out of its card and out of its grid row, which
  looks like a CSS bug and is not one. The same applies to CSS: removing one
  selector from a comma-separated group takes the declaration block with it
  and silently kills the whole rule.
