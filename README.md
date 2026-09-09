# UResearch 2.0

Centralized postgraduate administrative portal for the **Centre for Graduate
Studies (CGS)**, Universiti Teknologi PETRONAS.

Laravel 12 · PHP 8.3 · MySQL 8.4 (Docker) · Blade · Chart.js

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

You do steps 1 and 2 once per machine. Step 3 is one command.

### 1. Install PHP and Composer

**Ubuntu / WSL2:**

```bash
sudo apt update
sudo apt install -y php8.3-cli php8.3-mbstring php8.3-xml php8.3-curl \
                    php8.3-mysql php8.3-zip php8.3-bcmath unzip

curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
```

**macOS:** `brew install php composer`

Check it worked:

```bash
php -v          # 8.3.x  (8.2 or newer is fine)
composer -V     # 2.x
```

> **If `./setup.sh` then says an extension is missing but you just ran the
> install:** apt enables extensions slightly after the command returns. Wait a
> few seconds and run `./setup.sh` again.

### 2. Install Docker

**Windows + WSL2:** install [Docker Desktop](https://docs.docker.com/desktop/),
then **Settings → Resources → WSL Integration → enable your distro → Apply &
Restart**. Close and reopen your terminal.

**macOS / Linux:** Docker Desktop, or Docker Engine + the compose plugin.

Check it worked:

```bash
docker info     # must print without error
```

### 3. Run setup

```bash
git clone git@github.com:Mickeysya/uresearch.git
cd uresearch
./setup.sh
```

That takes a few minutes the first time — mostly pulling images and downloading
Composer packages. It:

1. checks PHP, Composer, Docker and the eight required PHP extensions
2. pulls the MySQL, phpMyAdmin and Mailpit images
3. starts the three containers and waits for MySQL to accept connections
4. runs `composer install`
5. creates `.env` from `.env.example` and generates `APP_KEY`
6. runs every migration and seeds the test accounts

It is safe to re-run at any time. It will not overwrite an existing `.env`.

### 4. Start the app

Two terminals, both in the project root:

```bash
php artisan serve        # terminal 1
php artisan queue:work   # terminal 2
```

Open **http://localhost:8000** and log in as `student@utp.edu.my` with the
password `password`.

> The second terminal sends the notification emails, which are queued. If you
> would rather not keep it open, set `QUEUE_CONNECTION=sync` in `.env` and
> emails send inline instead.

---

## Running it day to day

After the first setup, starting work is:

```bash
docker compose up -d     # MySQL, phpMyAdmin, Mailpit
php artisan serve
php artisan queue:work   # second terminal, if you want emails
```

Stopping:

```bash
docker compose down      # stops the containers; your data survives
```

Pulling teammates' work:

```bash
git pull
composer install         # if composer.json changed
php artisan migrate      # if anyone added a migration
```

---

## Every password and port

Nothing here is a secret — it is all local-only development configuration,
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
| **Mailpit** — every email the app sends | http://localhost:8025 | none |

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
| 8000 | Laravel (`php artisan serve`) |
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
| `chair@utp.edu.my` | Chair of Department | second endorsement — **final approval for local travel** |
| `cgs@utp.edu.my` | Non-Executive CGS | reviews international travel, verifies GA extensions |
| `dean@utp.edu.my` | Dean of PGR | **final approval for international travel** |
| `director@utp.edu.my` | Senior Director CGS | final approval for GA extensions |
| `ae@utp.edu.my` | Academic Executive | approves examiner nominations |
| `manager@utp.edu.my` | Manager CGS | Claims — module not built yet |
| `registry@utp.edu.my` | Registry | RPD dismissals — module not built yet |
| `admin@utp.edu.my` | Admin | no admin screens exist yet |

Both students are supervised by `supervisor@utp.edu.my`, so the supervisee
relationship can be exercised.

To get back to a clean database at any time:

```bash
./reset.sh                        # asks for confirmation
php artisan migrate:fresh --seed  # same thing, no prompt
```

---

## Try it in five minutes

This walks the conditional routing, which is the most interesting part of the
system — the same form produces a different approval chain depending on what
the student ticks.

**1. Submit an international trip.** Log in as `student@utp.edu.my` →
*New Application → Travel*. Fill it in and **tick "International Travel"**.
Submit.

**2. Look at the tracker.** *Track My Applications* — the stepper shows
**four** steps: Supervisor → Chair → Non-Executive CGS → Dean of PGR.

**3. Walk the chain.** Log out and back in as each of these, click Travel in
the sidebar, and approve:

`supervisor@utp.edu.my` → `chair@utp.edu.my` → `cgs@utp.edu.my` → `dean@utp.edu.my`

**4. Back as the student** — every step is green and the badge reads
*approved*.

**5. Now do a local trip.** Same form, **leave "International Travel"
unticked**. The stepper now shows only **two** steps, and `chair@utp.edu.my`
gives final approval — CGS and the Dean are never involved.

**6. Check the email.** http://localhost:8025 — a notification for every
decision. (Run `php artisan queue:work` if they have not appeared.)

Also worth trying: as `supervisor@utp.edu.my`, use *Nominate Examiners*. The
dropdown disables examiners who are assigned, unavailable, or still inside the
90-day cooling-off period, and tells you when each becomes eligible again.

---

## Troubleshooting

**`ERROR: missing PHP extension(s)` right after installing PHP**
apt enables extensions a moment after the install command returns. Wait a few
seconds and re-run `./setup.sh`.

**`dependency failed to start: container uresearch-mysql exited (137)`**
137 means the kernel killed MySQL — almost always memory, on a first run while
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
MySQL is not up yet. `docker compose ps` — wait for `uresearch-mysql` to read
`(healthy)`, then retry. First start takes ~15 seconds while it builds the
data directory.

**Port already in use**
`ss -ltn | grep 8000` to find the culprit. Either stop it, or change the port:
`php artisan serve --port=8001`, and edit `docker-compose.yml` for the others.

**No emails arriving**
They are queued. Run `php artisan queue:work`, or set
`QUEUE_CONNECTION=sync` in `.env`.

**Changed `.env` and nothing happened**

```bash
php artisan optimize:clear
```

**Starting completely over**

```bash
docker compose down -v      # deletes the database volume too
rm -rf vendor
./setup.sh
```

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
├── public/css/          Norhanis' stylesheet
├── .claude/             agents, and the hook that stops Claude pushing
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
`if` — see `Norhanis\Workflows\TravelWorkflow`, where international travel
returns a four-stage chain and local travel a two-stage one. When it is called
with `null` it must return the **superset**: every stage the module can ever
use, which is what the sidebar and the approval queues are built from.

**Never write `status` or `current_stage` yourself.** Call
`WorkflowEngine::submit()` and `WorkflowEngine::decide()`.

## Adding your module

Copy `app/Modules/Norhanis` — it is a complete worked example. The full
walkthrough is in [`docs/adding-a-module.md`](docs/adding-a-module.md).

## Commands

```bash
php artisan serve                  # run the app
php artisan queue:work             # send queued notification emails
php artisan migrate                # apply new migrations
php artisan migrate:fresh --seed   # wipe and reseed (also ./reset.sh)
php artisan route:list             # every route, including all modules
php artisan tinker                 # REPL against the app
php artisan optimize:clear         # clear config/route/view caches

docker compose up -d               # start MySQL / phpMyAdmin / Mailpit
docker compose ps                  # health of each container
docker compose logs mysql          # why MySQL will not start
docker compose down                # stop; data survives
docker compose down -v             # stop AND delete the database
```

## Documentation

| Document | What it covers |
|---|---|
| [`TODO.md`](TODO.md) | what's done, what isn't, and what to do next |
| [`docs/architecture.md`](docs/architecture.md) | layers, the engine, the data model |
| [`docs/adding-a-module.md`](docs/adding-a-module.md) | step-by-step, with code |
| [`docs/conventions.md`](docs/conventions.md) | naming, ownership, the nine rules |
| [`docs/module-keys.md`](docs/module-keys.md) | the `module_type` registry — claim yours |
| [`docs/migration-from-legacy.md`](docs/migration-from-legacy.md) | what changed from the raw-PHP version and why |
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

Claude cannot push to GitHub on this repo.
`.claude/hooks/block-remote-writes.py` rejects `git push`, `gh pr create`,
releases and remote changes as a PreToolUse hook, so publishing stays a human
decision. It commits locally and hands you the command to run. Verify the hook
with:

```bash
./.claude/hooks/test-block-remote-writes.sh
```
