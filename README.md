# UResearch 2.0

Centralized postgraduate administrative portal for the **Centre for Graduate
Studies (CGS)**, Universiti Teknologi PETRONAS.

Final Year Project · six-member team
Supervisor: Dr. Savita K. Sugathan · Examiner: Dr. Helmi B Mohd Rais

---

## The application is in [`uresearch-app/`](uresearch-app/)

```bash
cd uresearch-app
./setup.sh          # containers, dependencies, .env, migrate, seed
php artisan serve
```

Then open http://localhost:8000 and log in as `student@utp.edu.my` with the
password `password`. Full setup notes, test accounts and troubleshooting are in
[`uresearch-app/README.md`](uresearch-app/README.md).

## Repository layout

| Path | What it is |
|---|---|
| `uresearch-app/` | **the application** — Laravel 12, MySQL 8.4 in Docker |
| `uresearch-app/docs/` | architecture, conventions, how to add a module |
| `.claude/agents/` | Claude Code agents for this project |
| `CLAUDE.md` | project context loaded by Claude Code |
| `technical.md` | system-wide technical specification |
| `norhanis.md`, `nureen.md`, `hani.md` | per-person scope documents |

## Who owns what

Each person owns one folder under `uresearch-app/app/Modules/` and builds
entirely inside it — their own migrations, models, controllers, routes and
views. Nothing central lists the modules, so adding one causes no merge
conflict.

| Folder | Owner | Modules |
|---|---|---|
| `Core/` | the team | shared foundation — changes by agreement |
| `Norhanis/` | Norhanis Erna Natasha (22006318) | Travel · Publication · Claims · RPD |
| `Nureen/` | Nureen Nellysha (22006973) | Attendance · GA Extension · Supervision · Certification |
| `Hani/` | Nur Hani Sofia (22001418) | Examiner Nomination · Conflict Detection · Re-viva |
| `Jason/` | Jason | to be scoped |
| `Chloe/` | Chloe | to be scoped |
| `Sharvin/` | Sharvin | to be scoped |

Start by reading [`uresearch-app/docs/adding-a-module.md`](uresearch-app/docs/adding-a-module.md)
and copying `app/Modules/Norhanis/`, which is a complete worked example.

## Working with Claude Code

`CLAUDE.md` and `.claude/agents/` are set up for this project. Four agents:

| Agent | Use it for |
|---|---|
| `module-builder` | scaffolding a new module in your folder |
| `legacy-porter` | moving an old raw-PHP page onto the module pattern |
| `core-guard` | checking a change before you commit — ownership and workflow rules |
| `security-reviewer` | auditing for the vulnerability classes the legacy app had |
