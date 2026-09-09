#!/usr/bin/env bash
# UResearch 2.0 — one-time local setup.
# Safe to re-run; it will not overwrite an existing .env.
set -euo pipefail

cd "$(dirname "$0")"

say()  { printf '\n\033[1;34m==>\033[0m %s\n' "$1"; }
fail() { printf '\n\033[1;31mERROR:\033[0m %s\n' "$1" >&2; exit 1; }

say "Checking prerequisites"
command -v php >/dev/null      || fail "php not found. Install PHP 8.2+ (see README)."
command -v composer >/dev/null || fail "composer not found (see README)."
command -v docker >/dev/null   || fail "docker not found. Enable Docker Desktop's WSL integration (see README)."
docker info >/dev/null 2>&1    || fail "the Docker daemon is not reachable. Start Docker Desktop."

for ext in pdo_mysql mbstring openssl tokenizer xml ctype fileinfo curl; do
    php -m | grep -qi "^${ext}$" || fail "PHP extension '${ext}' is missing."
done
printf '    php %s, composer, docker — all present\n' "$(php -r 'echo PHP_VERSION;')"

say "Starting MySQL, phpMyAdmin and Mailpit"
docker compose up -d

printf '    waiting for MySQL to accept connections'
for _ in $(seq 1 60); do
    if docker compose exec -T mysql mysqladmin ping -h localhost -psecret >/dev/null 2>&1; then
        printf ' ready\n'; break
    fi
    printf '.'; sleep 2
done

say "Installing PHP dependencies"
composer install --no-interaction --prefer-dist

if [ ! -f .env ]; then
    say "Creating .env"
    cp .env.example .env
    php artisan key:generate
else
    say ".env already exists — leaving it alone"
    grep -q '^APP_KEY=base64:' .env || php artisan key:generate
fi

say "Building the database"
php artisan migrate:fresh --seed

say "Done"
cat <<'MSG'

  Start the app:      php artisan serve
  Then open:

    App           http://localhost:8000
    phpMyAdmin    http://localhost:8080     (root / secret)
    Mailpit       http://localhost:8025     every email the app sends

  Log in with any seeded account — the password is: password

    student@utp.edu.my       submit an application
    supervisor@utp.edu.my    first approval stage
    chair@utp.edu.my         second stage (final for local travel)
    cgs@utp.edu.my           Non-Executive CGS review
    dean@utp.edu.my          final approval for international travel
    ae@utp.edu.my            examiner nominations
    director@utp.edu.my      final approval for GA extensions

MSG
