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

# Read the module list once. Piping `php -m` into `grep -q` per extension is a
# trap under `set -o pipefail`: grep exits on first match, php can take SIGPIPE,
# and the pipeline then reports failure even though the extension is present.
php_modules="$(php -m)"
missing=""
for ext in pdo_mysql mbstring openssl tokenizer xml ctype fileinfo curl; do
    printf '%s\n' "$php_modules" | grep -qix "$ext" || missing="${missing} ${ext}"
done
if [ -n "$missing" ]; then
    printf '\n\033[1;31mERROR:\033[0m missing PHP extension(s):%s\n\n' "$missing" >&2
    printf 'Install them with:\n\n    sudo apt install -y' >&2
    for ext in $missing; do
        case "$ext" in
            pdo_mysql) printf ' php8.3-mysql' >&2 ;;
            mbstring|xml|curl) printf ' php8.3-%s' "$ext" >&2 ;;
            *)         printf ' php8.3-common' >&2 ;;
        esac
    done
    printf '\n\nIf you just installed PHP, apt may still have been enabling\n' >&2
    printf 'extensions when this ran -- simply re-run ./setup.sh.\n\n' >&2
    exit 1
fi
printf '    php %s, composer, docker — all present\n' "$(php -r 'echo PHP_VERSION;')"

say "Fetching container images"
# Pulled as a separate step on purpose: extracting the MySQL image while it is
# also initialising its data directory can spike memory hard enough for the
# kernel to kill mysqld (exit 137) on a laptop or a default WSL2 VM.
docker compose pull --quiet

say "Starting MySQL, phpMyAdmin and Mailpit"
start_stack() {
    docker compose up -d --wait --wait-timeout 180 2>&1 | tail -5
}

if ! start_stack; then
    printf '\n    first start failed; retrying once with the images already cached\n'
    docker compose down --remove-orphans >/dev/null 2>&1 || true
    start_stack || fail "the containers would not start. Run 'docker compose logs mysql' to see why.
       If mysql exited with 137 it was killed for memory: close other apps, or
       raise the WSL2 memory limit in %USERPROFILE%\\.wslconfig, e.g.
           [wsl2]
           memory=6GB
       then run 'wsl --shutdown' from PowerShell and try again."
fi

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
