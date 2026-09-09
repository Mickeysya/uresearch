# UResearch 2.0

Centralized postgraduate administrative portal for the **Centre for Graduate
Studies (CGS)**, Universiti Teknologi PETRONAS.

Laravel 12 · PHP 8.2+ · MySQL 8.4 (Docker) · Blade · Chart.js

Final Year Project · six-member team
Supervisor: Dr. Savita K. Sugathan · Examiner: Dr. Helmi B Mohd Rais

---

## Quick start

```bash
git clone <repo> && cd uresearch
./setup.sh
php artisan serve
```

| What | Where | Credentials |
|---|---|---|
| App | http://localhost:8000 | any seeded account, password `password` |
| phpMyAdmin | http://localhost:8080 | `root` / `secret` |
| Mailpit (all outgoing email) | http://localhost:8025 | — |

`setup.sh` starts the containers, installs dependencies, writes `.env`,
migrates and seeds. It is safe to re-run.

### Prerequisites

You need PHP, Composer and Docker on the machine. On Ubuntu / WSL2:

```bash
sudo apt update
sudo apt install -y php8.3-cli php8.3-mbstring php8.3-xml php8.3-curl \
                    php8.3-mysql php8.3-zip php8.3-bcmath unzip

curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
```

For Docker on WSL2: install Docker Desktop on Windows, then enable
**Settings → Resources → WSL Integration** for this distro. Confirm with
`docker info`.

### Test accounts

Every account's password is `password`.

| Email | Role | Use it to |
|---|---|---|
| `student@utp.edu.my` | Student | submit travel / GA extension applications |
| `student2@utp.edu.my` | Student | a second candidate |
| `supervisor@utp.edu.my` | Supervisor | first endorsement; file examiner nominations |
| `chair@utp.edu.my` | Chair of Department | second endorsement — final for **local** travel |
| `cgs@utp.edu.my` | Non-Executive CGS | review international travel, verify GA extensions |
| `dean@utp.edu.my` | Dean of PGR | final approval for **international** travel |
| `director@utp.edu.my` | Senior Director CGS | final approval for GA extensions |
| `ae@utp.edu.my` | Academic Executive | approve examiner nominations |
| `manager@utp.edu.my` | Manager CGS | claims (module not built yet) |
| `registry@utp.edu.my` | Registry | RPD dismissals (module not built yet) |
| `admin@utp.edu.my` | Admin | — |

**Try the conditional routing:** submit a travel application as the student
with *International Travel* unticked — it finishes at the Chair. Submit
another with it ticked — the same form now routes on to CGS and the Dean, and
the student's progress stepper shows six steps instead of four.

---

## Layout

```
.
├── app/
│   ├── Modules/
│   │   ├── Core/        shared foundation — auth, workflow engine, UI, models
│   │   ├── Norhanis/    Travel · Publication · Claims · RPD
│   │   ├── Nureen/      Attendance · GA Extension · Supervision · Certification
│   │   ├── Hani/        Examiner Nomination · Conflict Detection · Re-viva
│   │   ├── Jason/       (empty)
│   │   └── Chloe/       (empty)
│   └── Providers/       module auto-discovery
├── database/migrations/ framework tables only (users, sessions, jobs, cache)
├── docs/                architecture, conventions, how to add a module
│   └── scope/           the FYP scope documents
├── public/css/          Norhanis' stylesheet, unchanged
├── .claude/agents/      Claude Code agents for this project
├── CLAUDE.md            project context loaded by Claude Code
├── TODO.md              status: done / not done / next
├── docker-compose.yml   MySQL · phpMyAdmin · Mailpit
└── legacy-archive.tar.gz  the pre-rewrite raw-PHP app (see LEGACY.md)
```

## Who owns what

**Everyone owns exactly one folder under `app/Modules/`.** You build entirely
inside it — your own migrations, models, controllers, routes and views. Nothing
central lists the modules, so adding one causes no merge conflict.

| Folder | Owner | Modules |
|---|---|---|
| `Core/` | the team | shared foundation — changes by agreement |
| `Norhanis/` | Norhanis Erna Natasha (22006318) | Travel · Publication · Claims · RPD |
| `Nureen/` | Nureen Nellysha (22006973) | Attendance · GA Extension · Supervision · Certification |
| `Hani/` | Nur Hani Sofia (22001418) | Examiner Nomination · Conflict Detection · Re-viva |
| `Jason/` | Jason | to be scoped |
| `Chloe/` | Chloe | to be scoped |

`Core/` is the shared base. Changing it affects all six of us, so raise it
with the team first.

## The workflow engine

Instead of writing an approval page per stage, you declare your chain once:

```php
public function stages(Application $application): array
{
    return [
        new Stage('supervisor', 'Lecturer/Supervisor', Role::SUPERVISOR, 'endorsed'),
        new Stage('chair',      'Chair of Department', Role::CHAIR,      'approved'),
    ];
}
```

From that one declaration you get routing between approvers, the queue query
for every stage, authorisation, the student's progress stepper, the tracking
page row, the notification email, and the sidebar links.

Because `stages()` receives the application, conditional routing is just an
`if` — see `Norhanis\Workflows\TravelWorkflow`, where international travel
returns a four-stage chain and local travel a two-stage one.

**Never write `status` or `current_stage` yourself.** Call
`WorkflowEngine::submit()` and `WorkflowEngine::decide()`.

## Adding your module

Copy `app/Modules/Norhanis` — it is a complete worked example. The full
walkthrough is in [`docs/adding-a-module.md`](docs/adding-a-module.md).

## Common commands

```bash
php artisan serve                  # run the app
php artisan migrate                # apply new migrations
php artisan migrate:fresh --seed   # wipe and reseed (also ./reset.sh)
php artisan queue:work             # process notification emails
php artisan route:list             # every route, including modules
docker compose up -d               # start MySQL / phpMyAdmin / Mailpit
docker compose down                # stop them (data survives)
docker compose down -v             # stop AND delete the database volume
```

Notification emails are queued. Run `php artisan queue:work` in a second
terminal, or set `QUEUE_CONNECTION=sync` in `.env` while developing.

## Documentation

| Document | What it covers |
|---|---|
| [`docs/architecture.md`](docs/architecture.md) | layers, the engine, the data model |
| [`docs/adding-a-module.md`](docs/adding-a-module.md) | step-by-step, with code |
| [`docs/conventions.md`](docs/conventions.md) | naming, ownership, the rules |
| [`docs/module-keys.md`](docs/module-keys.md) | the `module_type` registry — claim yours |
| [`docs/migration-from-legacy.md`](docs/migration-from-legacy.md) | what changed from the raw-PHP version and why |
| [`TODO.md`](TODO.md) | what's done, what isn't, and what to do next |
| [`docs/scope/`](docs/scope/) | the FYP scope documents |
| [`LEGACY.md`](LEGACY.md) | the archived pre-rewrite app, and how to restore it |

## Working with Claude Code

`CLAUDE.md` and `.claude/agents/` are set up for this project. Four agents:

| Agent | Use it for |
|---|---|
| `module-builder` | scaffolding a new module in your folder |
| `legacy-porter` | moving an old raw-PHP page onto the module pattern |
| `core-guard` | checking a change before you commit — ownership and workflow rules |
| `security-reviewer` | auditing for the vulnerability classes the legacy app had |
