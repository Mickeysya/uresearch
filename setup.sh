#!/usr/bin/env bash
# UResearch 2.0 — first-time local setup.
#
# Safe to re-run: it will not overwrite your .env, and it will NOT wipe a
# database that already has data. Re-running applies any new migrations and
# leaves your records alone. To deliberately start over, use ./reset.sh.
set -euo pipefail

cd "$(dirname "$0")"

# shellcheck source=scripts/env.sh
. ./scripts/env.sh

say()  { printf '\n\033[1;34m==>\033[0m %s\n' "$1"; }
fail() { printf '\n\033[1;31mERROR:\033[0m %s\n' "$1" >&2; exit 1; }

say "Checking prerequisites"
command -v docker >/dev/null   || fail "docker not found. Enable Docker Desktop's WSL integration (see README)."
docker info >/dev/null 2>&1    || fail "the Docker daemon is not reachable. Start Docker Desktop."
printf '    docker — present. Everything else runs in containers.\n'

# Composer runs in a throwaway container so nobody needs PHP on the host. This
# has to happen before anything touches docker-compose.yml, because the app
# image is built from vendor/laravel/sail/runtimes/8.3, which does not exist
# until Sail is installed.
say "Installing PHP dependencies (via Docker)"
docker run --rm \
    -u "$(id -u):$(id -g)" \
    -v "$(pwd):/var/www/html" \
    -w /var/www/html \
    laravelsail/php83-composer:latest \
    composer install --ignore-platform-reqs

say "Preparing .env"
if [ ! -f .env ]; then
    cp .env.example .env
    printf '    created .env from .env.example\n'
else
    printf '    .env already exists — leaving it alone\n'
fi

# Sail's own CLI script exports APP_PORT=80 into the shell whenever it isn't
# already set, which overrides docker-compose.yml's default of 8000 for every
# `sail up` call below. .env.example carries this going forward, but anyone
# who ran setup.sh before this line existed has a .env missing it — add it on
# re-run so the app doesn't silently come up on port 80 instead of 8000.
if ! grep -q '^APP_PORT=' .env; then
    printf 'APP_PORT=8000\n' >> .env
    printf '    added APP_PORT=8000 to .env\n'
fi

# An older .env.example had DB_HOST/MAIL_HOST set to 127.0.0.1 (fixed since,
# but anyone whose .env predates that fix still has the stale value). CLI
# commands like `sail artisan` never noticed because they pick up
# docker-compose.yml's environment: override directly, but `php artisan
# serve` spawns its HTTP worker without inheriting that override and falls
# back to whatever is in .env — so every request 500'd on the first query.
if grep -q '^DB_HOST=127.0.0.1' .env; then
    sed -i 's/^DB_HOST=127.0.0.1/DB_HOST=mysql/' .env
    printf '    fixed stale DB_HOST=127.0.0.1 in .env -> mysql\n'
fi
if grep -q '^MAIL_HOST=127.0.0.1' .env; then
    sed -i 's/^MAIL_HOST=127.0.0.1/MAIL_HOST=mailpit/' .env
    printf '    fixed stale MAIL_HOST=127.0.0.1 in .env -> mailpit\n'
fi

# Anything else .env.example declares that this .env has not got. Generic on
# purpose: the two repairs above had to be written by hand after each broke
# something, and REDIS_HOST broke the same way a third time before anyone
# added it. See scripts/env.sh.
added=$(env_backfill)
if [ -n "$added" ]; then
    printf '    added missing .env settings from .env.example:\n'
    printf '      %s\n' $added
fi

# Generated before the stack starts, not after: otherwise the app container
# boots without an APP_KEY and every request 500s until this line runs.
if ! grep -q '^APP_KEY=base64:' .env; then
    docker run --rm \
        -u "$(id -u):$(id -g)" \
        -v "$(pwd):/var/www/html" \
        -w /var/www/html \
        laravelsail/php83-composer:latest \
        php artisan key:generate
fi

# Pulled and built as separate steps on purpose: extracting the MySQL image
# while it is also initialising its data directory can spike memory hard
# enough for the kernel to kill mysqld (exit 137) on a laptop or a default
# WSL2 VM. Building the app image at the same time makes that worse, so get
# both out of the way before anything starts.
say "Fetching container images"
docker compose pull --quiet mysql phpmyadmin mailpit

say "Building the application image"
./vendor/bin/sail build

say "Starting the application, queue worker, MySQL, phpMyAdmin and Mailpit"
start_stack() {
    ./vendor/bin/sail up -d --wait --wait-timeout 180 2>&1 | tail -5 &&
    # Compose doesn't always recreate laravel.test/queue when only .env changed
    # (e.g. APP_PORT), so a stale container can keep an old port binding even
    # after `up` reports success. Force those two to match the current .env.
    ./vendor/bin/sail up -d --force-recreate laravel.test queue 2>&1 | tail -5
}

if ! start_stack; then
    printf '\n    first start failed; retrying once with the images already cached\n'
    # No -v here. That would delete the mysql-data volume, which is fine on a
    # true first run and destroys the database on any re-run after a failure.
    ./vendor/bin/sail down --remove-orphans >/dev/null 2>&1 || true
    start_stack || fail "the containers would not start. Run './vendor/bin/sail logs mysql' to see why.
       If mysql exited with 137 it was killed for memory: close other apps, or
       raise the WSL2 memory limit in %USERPROFILE%\\.wslconfig, e.g.
           [wsl2]
           memory=6GB
       then run 'wsl --shutdown' from PowerShell and try again."
fi

printf '    waiting for MySQL to accept connections'
for _ in $(seq 1 60); do
    if ./vendor/bin/sail artisan db:monitor >/dev/null 2>&1; then
        printf ' ready\n'; break
    fi
    printf '.'; sleep 2
done

# migrate, NOT migrate:fresh. This script used to run `migrate:fresh --seed`
# unconditionally while its own header promised it was safe to re-run —
# meaning a second run silently dropped every table. Seeding happens only
# when the database has no migrations yet, i.e. a genuine first install.
if db_is_built; then
    say "Applying any new migrations (your data is left alone)"
    ./vendor/bin/sail artisan migrate --force
    printf '    to start over from scratch instead, run ./reset.sh\n'
else
    say "Building the database"
    ./vendor/bin/sail artisan migrate --force
    ./vendor/bin/sail artisan db:seed --force
fi

say "Done"
cat <<'MSG'

  The app is now running in the background!

    App           http://localhost:8000
    phpMyAdmin    http://localhost:8080     (root / secret)
    Mailpit       http://localhost:8025     every email the app sends

  To stop the app:  ./vendor/bin/sail down

  Log in with any seeded account — the password is: password

    student@utp.edu.my       submit an application
    supervisor@utp.edu.my    first approval stage
    chair@utp.edu.my         second stage (final for local travel)
    cgs@utp.edu.my           Non-Executive CGS review
    dean@utp.edu.my          final approval for international travel
    ae@utp.edu.my            examiner nominations
    director@utp.edu.my      final approval for GA extensions

MSG
