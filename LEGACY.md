# Legacy archive

The original raw-PHP application was replaced by the Laravel app that now
occupies this repository. Everything that existed before the rewrite is
preserved in `legacy-archive.tar.gz` — nothing was thrown away.

## What's inside

| Path in the archive | What it was |
|---|---|
| `modules/`, `database`, `shared` | the root copy (also in this repo's git history) |
| `Norhanis/uresearch2/` | Norhanis' copy — 12 approval pages, styled forms, the original stylesheet |
| `Nureen/uresearch/` | Nureen's clone, including her uncommitted GA extension work |
| `Hani Sofia/uresearch/` | Hani's copy — examiner nomination form and AE approval |
| `list_users.txt` | the team's role list |

## Restoring it

```bash
tar -xzf legacy-archive.tar.gz            # everything
tar -xzf legacy-archive.tar.gz "Norhanis/uresearch2/travel_endorsement.php"   # one file
tar -tzf legacy-archive.tar.gz            # list without extracting
```

## What was carried across

- **Norhanis' stylesheet** → `public/css/uresearch.css`, unchanged above the
  marked line, with her logos and background in `public/images/`.
- **Travel** → `app/Modules/Norhanis/`, now the reference implementation.
- **GA Extension** → `app/Modules/Nureen/`.
- **Examiner nomination** → `app/Modules/Hani/`, plus the examiner pool and the
  90-day cooling-off rule her scope called for but the code never had.
- **Every approval chain**, as declarative `Stage` lists.

`docs/migration-from-legacy.md` records what changed and why, including the
bugs and vulnerabilities the rewrite fixed. Read that before reintroducing
anything from the archive.
