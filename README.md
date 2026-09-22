# UResearch 2.0

Centralized postgraduate administrative portal for the **Centre for Graduate
Studies (CGS)**, Universiti Teknologi PETRONAS.

Laravel 12 · PHP 8.3 · MySQL 8.4 (Docker) · Blade · Chart.js · Dompdf

Final Year Project · six-member team
Supervisor: Dr. Savita K. Sugathan · Examiner: Dr. Helmi B Mohd Rais

---

## Contents

- [First-time setup](#first-time-setup)
- [Running it day to day](#running-it-day-to-day)
- [Every password and port](#every-password-and-port)
- [Test accounts](#test-accounts)
- [Try it in five minutes](#try-it-in-five-minutes)
- [Troubleshooting](#troubleshooting)
- [Layout](#layout)
- [Who owns what](#who-owns-what)
- [The workflow engine](#the-workflow-engine)
- [Adding your module](#adding-your-module)
- [Commands](#commands)
- [Documentation](#documentation)
- [Working with Claude Code](#working-with-claude-code)

---

## First-time setup

You only need Docker to run this project! The web server, queue workers, database, and all other services run entirely in containers.

### 1. Install Docker

**Windows + WSL2:** install [Docker Desktop](https://docs.docker.com/desktop/),
then **Settings → Resources → WSL Integration → enable your distro → Apply &
Restart**. Close and reopen your terminal.

**macOS / Linux:** Docker Desktop, or Docker Engine + the compose plugin.

Check it worked:

```bash
docker info     # must print without error
```

### 2. Run setup

```bash
git clone git@github.com:Mickeysya/uresearch.git
cd uresearch
./setup.sh
```

That takes a few minutes the first time, mostly pulling images, building the
application image and downloading Composer packages. It:

1. checks that Docker is installed and running
2. runs `composer install` inside a throwaway PHP container
3. creates `.env` from `.env.example` and generates `APP_KEY`
4. pulls the MySQL, phpMyAdmin and Mailpit images, then builds the app image
5. starts all five containers and waits for MySQL to accept connections
6. runs every migration and seeds the test accounts

It is safe to re-run at any time: it will not overwrite an existing `.env`,
and it will not wipe a database that already has data. A re-run applies any
new migrations and leaves your records alone. Use `./reset.sh` when you
actually want to start over.

### 3. Start the app

There is nothing to start. `./setup.sh` already brought the containers up in
the background, the web server and the queue worker included.

Open **http://localhost:8000** and log in as `student@utp.edu.my` with the
password `password`.

> **Nothing else may be holding port 8000.** The `laravel.test` container binds
> it, so a `php artisan serve` left running from before this change will stop
> the stack from starting. Stop it, or set `APP_PORT=8001` in `.env`.

---

## Running it day to day

Starting work:

```bash
./vendor/bin/sail up -d     # Starts web server, queue worker, DB, etc
```

Stopping:

```bash
./vendor/bin/sail down      # stops the containers; your data survives
```

Pulling teammates' work:

```bash
git pull
./sync.sh
```

`sync.sh` does everything a pull or a branch switch can require, and is safe
to re-run: installs dependencies if `composer.lock` changed, copies across any
new setting a teammate added to `.env.example` (your own `.env` is git-ignored,
so a pull never updates it), runs pending migrations, re-seeds the reference
data a migration cannot fill in for itself (the department list, the test
accounts — whose passwords go back to `password`), clears compiled Blade views
and config left over from the previous branch, and restarts the queue worker,
which holds the app in memory and otherwise keeps running pre-pull code.

To see what it would do without changing anything:

```bash
./sync.sh --check
```

### If the containers are too heavy on your machine

The app and queue containers cost roughly 1 GB of RAM on top of MySQL. If that
is too much, you can still run Laravel on the host the old way. MySQL, Mailpit
and phpMyAdmin stay in Docker either way and their ports are published, so
`.env` needs no changes:

```bash
docker compose up -d mysql phpmyadmin mailpit   # skip laravel.test and queue
composer install                                # needs PHP 8.2+ on the host
php artisan serve                               # terminal 1
php artisan queue:work                          # terminal 2, for emails
```

That path needs PHP 8.3 plus the `pdo_mysql`, `mbstring`, `openssl`,
`tokenizer`, `xml`, `ctype`, `fileinfo` and `curl` extensions and Composer,
which is exactly the install everyone used to do, and exactly why the
containerised path is the default now. Pick one or the other; running both at
once means two things fighting over port 8000.

---

## Every password and port

Nothing here is a secret. It is all local-only development configuration,
which is exactly why it can live in `.env.example` and be committed.

### Logging into the app

| | |
|---|---|
| **Password for every seeded account** | `password` |
| Emails | see [Test accounts](#test-accounts) below |

### Services

| Service | URL | Credentials |
|---|---|---|
| **The app** | http://localhost:8000 | any account below · password `password` |
| **phpMyAdmin** | http://localhost:8080 | user `root` · password `secret` |
| **Mailpit**, every email the app sends | http://localhost:8025 | none |

### Database

| | |
|---|---|
| Host / port | `127.0.0.1:3306` |
| Database | `uresearch` |
| App user | `uresearch` / `secret` |
| Root user | `root` / `secret` |

Those match `docker-compose.yml` and `.env.example`, so there is nothing to
configure. To connect from the CLI:

```bash
docker compose exec mysql mysql -uroot -psecret uresearch
```

### Ports in use

| Port | What |
|---|---|
| 8000 | Laravel (the `laravel.test` container) |
| 8080 | phpMyAdmin |
| 8025 | Mailpit web UI |
| 1025 | Mailpit SMTP |
| 3306 | MySQL |

If one clashes with something already on your machine, change it in
`docker-compose.yml` (and `DB_PORT` in `.env` for MySQL).

---

## Test accounts

Created by the seeder. **Every one uses the password `password`.**

| Email | Role | What they can do |
|---|---|---|
| `student@utp.edu.my` | Student | submit Travel and GA Extension applications, track them |
| `student2@utp.edu.my` | Student | a second candidate, for examiner nominations |
| `supervisor@utp.edu.my` | Supervisor | first endorsement on every chain; files examiner nominations |
| `chair@utp.edu.my` | Chair of Department | second endorsement, and **final approval for local travel** |
| `cgs@utp.edu.my` | Non-Executive CGS | M Syahmi Ifwat M Jafri — reviews international travel, verifies GA extensions, releases the final examiner list; **reads** the departments screen (who covers each AE queue) but changes nothing on it |
| `dean@utp.edu.my` | Dean of PGR | **final approval for international travel** |
| `director@utp.edu.my` | Senior Director CGS | final approval for GA extensions |
| `ae@utp.edu.my` | Academic Executive | approves examiner nominations, for Computing |
| `ae.chemical@utp.edu.my` … | Academic Executive | one per department — `ae.<department>@utp.edu.my`, e.g. `ae.civil`, `ae.petroleum`, `ae.management`. Each sees only their own department's queue; the departments screen lists them all |
| `seniorexec@utp.edu.my` | Senior Executive CGS | Puan Waheeda — compiles the faculty examiner list and holds the finalised report, and rules on hardbound appeals |
| `manager@utp.edu.my` | Manager CGS | approves claims |
| `faculty@utp.edu.my` | Faculty | signs off an RPD dismissal before the Registry |
| `registry@utp.edu.my` | Registry | closes a dismissed candidacy and sends the termination email |
| `admin@utp.edu.my` | Admin | the dashboard, audit log, reports, **Departments** (add, rename, retire) and **Users and Roles** (create staff accounts, set role and department). Accounts and roles are this login's alone — ask here for another Academic Executive |

Both students are supervised by `supervisor@utp.edu.my`, so the supervisee
relationship can be exercised.

To get back to a clean database at any time:

```bash
./reset.sh                                          # asks for confirmation
./vendor/bin/sail artisan migrate:fresh --seed      # same thing, no prompt
```

---

## Try it in five minutes

This walks the conditional routing, which is the most interesting part of the
system: the same form produces a different approval chain depending on what
the student ticks.

**1. Submit an international trip.** Log in as `student@utp.edu.my` →
*New Application → Travel*. Fill it in and **tick "International Travel"**.
Submit.

**2. Look at the tracker.** *Track My Applications*. The stepper shows
**four** steps: Supervisor → Chair → Non-Executive CGS → Dean of PGR.

**3. Walk the chain.** Log out and back in as each of these, click Travel in
the sidebar, and approve:

`supervisor@utp.edu.my` → `chair@utp.edu.my` → `cgs@utp.edu.my` → `dean@utp.edu.my`

**4. Back as the student.** Every step is green and the badge reads
*approved*.

**5. Now do a local trip.** Same form, **leave "International Travel"
unticked**. The stepper now shows only **two** steps, and `chair@utp.edu.my`
gives final approval. CGS and the Dean are never involved.

**6. Check the email.** http://localhost:8025 has a notification for every
decision. (The `queue` container sends them; `./vendor/bin/sail logs queue`
if they have not appeared.)

Also worth trying: as `supervisor@utp.edu.my`, use *Nominate Examiners*. A
panel is four seats — internal main and backup, external main and backup — and
two rules are visible as you fill it in. The internal side only offers
examiners from **the candidate's own department**: pick a different candidate
and the list changes under you. Every dropdown disables examiners who are
assigned, unavailable, or still inside the 90-day cooling-off period, and tells
you when each becomes eligible again. Both rules are re-checked on submit.

**Then walk the examiner chain**, which is the longest one in the system and
the one that goes *backwards* as well as forwards:

`ae.<department>@utp.edu.my` (or `ae@utp.edu.my` for Computing) →
`seniorexec@utp.edu.my` → `director@utp.edu.my` → `dean@utp.edu.my` →
`seniorexec@utp.edu.my` → `cgs@utp.edu.my`

At the Senior Director's or the Dean's desk, open a row and use **Send back to
the department** instead of approving. The list does not die: it drops to the
Academic Executive of *that* department, still pending, with the reason
recorded — and both the candidate and that department's Academic Executives
get an email. Everyone from the Academic Executive up also has *Examiner
Report*: the compiled list, grouped faculty → department, with cross-department
clashes flagged, and the same rows downloadable as Excel or CSV. An Academic
Executive sees only their own department there; CGS and above see the lot.

**Worth seeing: the dashboards.** Each role gets a different one, because they
answer different questions. `student@utp.edu.my` sees their own attendance and
applications; `cgs@utp.edu.my` sees the portal-wide desk; `chair@utp.edu.my`
and `supervisor@utp.edu.my` get approver screens built around *how long things
have been waiting*, and they deliberately draw different charts — the Chair's
is a bar over four age bands, the Supervisor's a doughnut of their candidates'
attendance. `dean@utp.edu.my` and `ae@utp.edu.my` share one approver screen
with the Registry and Faculty, since what each of them owns is a set of
stages. On a desktop all of these fit one screen and the panels scroll, not
the page.

And as `chair@utp.edu.my`, *Nominate Examiner Panel* → "Add the examiner
first". You land on the add-examiner form, fill it in, and come back to the
nomination with the candidate and the rows you had already picked still
there. *Examiner List* is the list itself, with its own "+ Add an examiner". Note these are **two different examiner lists**: the Chair's panel
list (`appointment_examiner_pool`) is not the pool the supervisor nominates
from (`examiners`), which is the one with the 90-day gap. Two lists on
purpose — see the "Dependencies" note in `TODO.md`.

---

## Troubleshooting

**`missing PHP extension` when running host PHP**
Only applies if you chose the host fallback above. `./setup.sh` needs no PHP
at all. apt enables extensions a moment after the install command returns, so
wait a few seconds and try again.

**`dependency failed to start: container uresearch-mysql exited (137)`**
137 means the kernel killed MySQL, almost always memory, on a first run while
Docker is still extracting image layers. `./setup.sh` now pulls images first
and retries once, so try it again. If it keeps happening, give WSL2 more
memory: create `C:\Users\<you>\.wslconfig` with

```ini
[wsl2]
memory=6GB
```

then run `wsl --shutdown` in PowerShell and start again.

**`The bootstrap/cache directory must be present and writable`**

```bash
mkdir -p bootstrap/cache && chmod -R 775 bootstrap/cache storage
```

**`SQLSTATE[HY000] [2002] Connection refused`**
MySQL is not up yet. Run `./vendor/bin/sail ps` and wait for `uresearch-mysql` to read
`(healthy)`, then retry. First start takes ~15 seconds while it builds the
data directory.

**Port already in use**
`ss -ltn | grep 8000` to find the culprit. Either stop it, or change the port:
set `APP_PORT=8001` in `.env` for the app, and edit `docker-compose.yml` for
the others.

**No emails arriving**
They are queued, and the `queue` container sends them. Check it is alive with
`./vendor/bin/sail ps` and `./vendor/bin/sail logs queue`. As a last resort set
`QUEUE_CONNECTION=sync` in `.env` to send inline instead.

**Changed `.env` and nothing happened**

```bash
./vendor/bin/sail artisan optimize:clear
```

If you changed `APP_PORT` or anything in `docker-compose.yml`, recreate the
containers instead: `./vendor/bin/sail down && ./vendor/bin/sail up -d`.

**Starting completely over**

```bash
./vendor/bin/sail down -v   # deletes the database volume too
rm -rf vendor
./setup.sh
```

---

## Layout

```
.
├── app/
│   ├── Modules/
│   │   ├── Core/        shared foundation: auth, engine, UI, models
│   │   ├── Norhanis/    Travel · Publication · Claims · RPD
│   │   ├── Nureen/      Attendance · GA Extension · Supervision · Certification
│   │   ├── Hani/        Examiner Nomination · Conflict Detection · Re-viva
│   │   ├── Jason/       Hardbound · Appeal Hardbound · Appointment Letters
│   │   ├── Chloe/       (scaffold only: Workstation · Candidacy)
│   │   └── Haziq/       (scaffold only: GRA · GA · Stage Gates · Allowance)
│   ├── Console/         artisan commands (demo:seed)
│   └── Providers/       module auto-discovery
├── database/migrations/ framework tables (users, sessions, jobs, cache) + activity log
├── database/seeders/    DatabaseSeeder (accounts) · DemoDataSeeder (demo:seed)
├── docs/                architecture, conventions, how to add a module
│   └── scope/           the FYP scope documents
├── public/css/          tokens.css is the design system; see docs/conventions.md
├── public/js/           Chart.js and its datalabels plugin, served self-hosted
├── .claude/             agents, and the hook that stops Claude pushing
├── CLAUDE.md            project context loaded by Claude Code
├── TODO.md              status: done / not done / next
├── docker-compose.yml   MySQL · phpMyAdmin · Mailpit
└── legacy-archive.tar.gz  the pre-rewrite raw-PHP app (see LEGACY.md)
```

## Who owns what

**Everyone owns exactly one folder under `app/Modules/`.** You build entirely
inside it: your own migrations, models, controllers, routes and views. Nothing
central lists the modules, so adding one causes no merge conflict.

| Folder | Owner | Modules |
|---|---|---|
| `Core/` | the team | shared foundation, changes by agreement |
| `Norhanis/` | Norhanis Erna Natasha (22006318) | Travel · Publication · Claims · RPD |
| `Nureen/` | Nureen Nellysha (22006973) | Attendance · GA Extension · Supervision · Certification |
| `Hani/` | Nur Hani Sofia (22001418) | Examiner Nomination · Conflict Detection · Re-viva |
| `Jason/` | Jason | Hardbound Submission · Appeal Hardbound Submission · Appointment Letters |
| `Chloe/` | Chloe Ching Qing En (22011629) | Workstation · Study Candidacy Reminder / Appeal / Dismissal |
| `Haziq/` | Abdul Haziq bin Abdul Farouk (22007428) | GRA · GA · Stage Gates · Allowance Eligibility |

`Core/` is shared. Changing it affects all six of us, so raise it with the team
first.

## The workflow engine

Instead of writing an approval page per stage, you declare your chain once:

```php
public function stages(?Application $application = null): array
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
`if`. See `Norhanis\Workflows\TravelWorkflow`, where international travel
returns a four-stage chain and local travel a two-stage one. When it is called
with `null` it must return the **superset**: every stage the module can ever
use, which is what the sidebar and the approval queues are built from.

**Never write `status` or `current_stage` yourself.** Call
`WorkflowEngine::submit()` and `WorkflowEngine::decide()`.

## Adding your module

Copy `app/Modules/Norhanis`. It is a complete worked example, and the full
walkthrough is in [`docs/adding-a-module.md`](docs/adding-a-module.md).

## Commands

The three project scripts, all safe to re-run:

```bash
./setup.sh                                 # first-time setup, from a clean clone (safe to re-run)
./sync.sh                                  # after a git pull or branch switch
./sync.sh --check                          # report what sync.sh would do, change nothing
./reset.sh                                 # wipe and reseed the database (prompts first)
```

Everything else is Sail:

```bash
./vendor/bin/sail up -d                    # start everything in background
./vendor/bin/sail down                     # stop everything
./vendor/bin/sail build                    # rebuild the app image
./vendor/bin/sail shell                    # a shell inside the app container
./vendor/bin/sail artisan migrate          # apply new migrations
./vendor/bin/sail artisan route:list       # every route, including all modules
./vendor/bin/sail artisan tinker           # REPL against the app (dev only,
                                           #   laravel/tinker is in require-dev,
                                           #   so it is absent after a
                                           #   `composer install --no-dev`)
./vendor/bin/sail artisan optimize:clear   # clear config/route/view caches
./vendor/bin/sail artisan test             # the test suite (SQLite in memory,
                                           #   never touches your database)
                                           #   Run it through Sail: the app
                                           #   container ships pdo_sqlite. A bare
                                           #   `php artisan test` on the host
                                           #   needs it installed there too
                                           #   (sudo apt install php8.3-sqlite3)

./vendor/bin/sail logs                     # view all logs
./vendor/bin/sail logs mysql               # view just MySQL logs

./vendor/bin/sail down -v                  # stop AND delete the database
```

## Documentation

| Document | What it covers |
|---|---|
| [`TODO.md`](TODO.md) | what's done, what isn't, and what to do next |
| [`docs/architecture.md`](docs/architecture.md) | layers, the engine, the data model |
| [`docs/adding-a-module.md`](docs/adding-a-module.md) | step-by-step, with code |
| [`docs/conventions.md`](docs/conventions.md) | naming, ownership, the nine rules, where tests go |
| [`docs/module-keys.md`](docs/module-keys.md) | the `module_type` registry, claim yours |
| [`docs/migration-from-legacy.md`](docs/migration-from-legacy.md) | what changed from the raw-PHP version and why |
| [`docs/email-service-integration.md`](docs/email-service-integration.md) | Mailpit in development, and what real SMTP needs |
| [`docs/tech-stack-and-architecture-report.md`](docs/tech-stack-and-architecture-report.md) | the stack and architecture write-up for the FYP report |
| [`docs/scope/`](docs/scope/) | the FYP scope documents |
| [`LEGACY.md`](LEGACY.md) | the archived pre-rewrite app, and how to restore it |

## Working with Claude Code

`CLAUDE.md` and `.claude/agents/` are set up for this project. Four agents:

| Agent | Use it for |
|---|---|
| `module-builder` | scaffolding a new module in your folder |
| `legacy-porter` | moving an old raw-PHP page onto the module pattern |
| `core-guard` | checking a change before you commit: ownership and workflow rules |
| `security-reviewer` | auditing for the vulnerability classes the legacy app had |

Claude cannot push to GitHub on this repo.
`.claude/hooks/block-remote-writes.py` rejects `git push`, `gh pr create`,
releases and remote changes as a PreToolUse hook, so publishing stays a human
decision. It commits locally and hands you the command to run. Verify the hook
with:

```bash
./.claude/hooks/test-block-remote-writes.sh
```
