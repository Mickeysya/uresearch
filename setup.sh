#!/usr/bin/env bash
# UResearch 2.0 — one-time local setup.
# Safe to re-run; it will not overwrite an existing .env.
set -euo pipefail

cd "$(dirname "$0")"

say()  { printf '\n\033[1;34m==>\033[0m %s\n' "$1"; }
fail() { printf '\n\033[1;31mERROR:\033[0m %s\n' "$1" >&2; exit 1; }

say "Checking prerequisites"
command -v docker >/dev/null   || fail "docker not found. Enable Docker Desktop's WSL integration (see README)."
docker info >/dev/null 2>&1    || fail "the Docker daemon is not reachable. Start Docker Desktop."

say "Installing PHP dependencies (via Docker)"
docker run --rm \
    -u "$(id -u):$(id -g)" \
    -v "$(pwd):/var/www/html" \
    -w /var/www/html \
    laravelsail/php83-composer:latest \
    composer install --ignore-platform-reqs

say "Starting the application stack"
if [ ! -f .env ]; then
    say "Creating .env"
    cp .env.example .env
fi

start_stack() {
    ./vendor/bin/sail up -d --wait 2>&1 | tail -5
}

if ! start_stack; then
    printf '\n    first start failed; retrying once\n'
    ./vendor/bin/sail down -v >/dev/null 2>&1 || true
    start_stack || fail "the containers would not start. Run './vendor/bin/sail logs' to see why."
fi

printf '    waiting for MySQL to accept connections'
for _ in $(seq 1 60); do
    if ./vendor/bin/sail artisan db:monitor >/dev/null 2>&1; then
        printf ' ready\n'; break
    fi
    printf '.'; sleep 2
done

say "Generating app key"
grep -q '^APP_KEY=base64:' .env || ./vendor/bin/sail artisan key:generate

say "Building the database"
./vendor/bin/sail artisan migrate:fresh --seed

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
