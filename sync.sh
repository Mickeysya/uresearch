#!/usr/bin/env bash
# UResearch 2.0 — bring your checkout back into a working state after a
# `git pull`, a branch switch, or a merge.
#
# Everything here is safe to re-run and nothing destroys data. Use ./reset.sh
# if you actually want the database wiped and reseeded.
#
#   ./sync.sh            do it all
#   ./sync.sh --check    report what WOULD change; touch nothing
#   ./sync.sh --update   use `composer update` instead of `composer install`
#
set -euo pipefail

cd "$(dirname "$0")"

CHECK_ONLY=0
COMPOSER_MODE="install"

for arg in "$@"; do
    case "$arg" in
        --check|-n)  CHECK_ONLY=1 ;;
        --update)    COMPOSER_MODE="update" ;;
        -h|--help)
            sed -n '2,11p' "$0" | sed 's/^# \{0,1\}//'
            exit 0 ;;
        *) printf 'Unknown option: %s (try --help)\n' "$arg" >&2; exit 1 ;;
    esac
done

say()  { printf '\n\033[1;34m==>\033[0m %s\n' "$1"; }
ok()   { printf '    \033[1;32m✓\033[0m %s\n' "$1"; }
warn() { printf '    \033[1;33m!\033[0m %s\n' "$1"; }
info() { printf '    %s\n' "$1"; }
fail() { printf '\n\033[1;31mERROR:\033[0m %s\n' "$1" >&2; exit 1; }

WOULD=()                       # things --check found that need doing
note() { WOULD+=("$1"); }

if [ "$CHECK_ONLY" -eq 1 ]; then
    printf '\n\033[1;33mCHECK MODE\033[0m — reporting only, nothing will be changed.\n'
fi

# ---------------------------------------------------------------------------
say "Checking Docker"
# ---------------------------------------------------------------------------
command -v docker >/dev/null || fail "docker not found. Start Docker Desktop (see README)."
docker info >/dev/null 2>&1  || fail "the Docker daemon is not reachable. Start Docker Desktop."
ok "Docker is running"

# vendor/ may be missing entirely on a fresh branch; Sail lives inside it, so
# fall back to a throwaway Composer container exactly as setup.sh does.
composer_run() {
    if [ -x ./vendor/bin/sail ] && docker compose ps --status running 2>/dev/null | grep -q uresearch-app; then
        ./vendor/bin/sail composer "$@"
    else
        docker run --rm \
            -u "$(id -u):$(id -g)" \
            -v "$(pwd):/var/www/html" \
            -w /var/www/html \
            laravelsail/php83-composer:latest \
            composer "$@" --ignore-platform-reqs
    fi
}

artisan() { ./vendor/bin/sail artisan "$@"; }

# ---------------------------------------------------------------------------
say "PHP dependencies"
# ---------------------------------------------------------------------------
if [ ! -d vendor ]; then
    warn "vendor/ is missing"
    if [ "$CHECK_ONLY" -eq 1 ]; then
        note "run composer $COMPOSER_MODE (vendor/ absent)"
    else
        info "running composer $COMPOSER_MODE ..."
        composer_run "$COMPOSER_MODE"
        touch vendor/autoload.php
        ok "dependencies installed"
    fi
else
    # composer.lock newer than the installed tree means someone changed
    # dependencies since you last installed them.
    if [ composer.lock -nt vendor/autoload.php ]; then
        warn "composer.lock is newer than vendor/ — dependencies changed"
        if [ "$CHECK_ONLY" -eq 1 ]; then
            note "run composer $COMPOSER_MODE"
        else
            composer_run "$COMPOSER_MODE"
            # composer only rewrites autoload.php when something changed, so
            # advance the stamp ourselves -- otherwise this warning would
            # fire on every future run even once we are up to date.
            touch vendor/autoload.php
            ok "dependencies updated"
        fi
    elif [ "$COMPOSER_MODE" = "update" ] && [ "$CHECK_ONLY" -eq 0 ]; then
        info "running composer update as asked ..."
        composer_run update
        ok "dependencies updated"
    else
        ok "dependencies already match composer.lock"
    fi
fi

if [ "$COMPOSER_MODE" = "update" ]; then
    warn "composer update rewrites composer.lock — commit it, or discard it before you push"
fi

# ---------------------------------------------------------------------------
say "Environment file"
# ---------------------------------------------------------------------------
# The failure this catches: a teammate adds a new setting to .env.example,
# you pull, and your own .env (git-ignored, so never updated by a pull) has
# no such key. The app then silently falls back to a config default and
# misbehaves in a way that looks nothing like a missing variable.
if [ ! -f .env ]; then
    warn ".env is missing"
    if [ "$CHECK_ONLY" -eq 1 ]; then
        note "create .env from .env.example and generate APP_KEY"
    else
        cp .env.example .env
        ok "created .env from .env.example"
    fi
fi

MISSING_KEYS=()
while IFS= read -r key; do
    grep -q "^${key}=" .env 2>/dev/null || MISSING_KEYS+=("$key")
done < <(grep -oE '^[A-Z][A-Z0-9_]*(?==)' .env.example 2>/dev/null || grep -oE '^[A-Z][A-Z0-9_]*=' .env.example | tr -d '=')

if [ ${#MISSING_KEYS[@]} -gt 0 ]; then
    warn "${#MISSING_KEYS[@]} setting(s) in .env.example are missing from your .env:"
    for k in "${MISSING_KEYS[@]}"; do info "  $k"; done

    if [ "$CHECK_ONLY" -eq 1 ]; then
        note "copy the missing keys into .env"
    else
        {
            printf '\n# --- added by sync.sh on %s ---\n' "$(date '+%Y-%m-%d %H:%M')"
            for k in "${MISSING_KEYS[@]}"; do
                grep -m1 "^${k}=" .env.example
            done
        } >> .env
        ok "appended them to .env with the values from .env.example"
        warn "check those values are right for your machine before relying on them"
    fi
else
    ok ".env has every key .env.example does"
fi

if ! grep -q '^APP_KEY=base64:' .env 2>/dev/null; then
    if [ "$CHECK_ONLY" -eq 1 ]; then
        note "generate APP_KEY"
    else
        composer_run >/dev/null 2>&1 || true
        php artisan key:generate 2>/dev/null || docker run --rm \
            -u "$(id -u):$(id -g)" -v "$(pwd):/var/www/html" -w /var/www/html \
            laravelsail/php83-composer:latest php artisan key:generate
        ok "generated APP_KEY"
    fi
fi

# ---------------------------------------------------------------------------
say "Containers"
# ---------------------------------------------------------------------------
if docker compose ps --status running 2>/dev/null | grep -q uresearch-app; then
    ok "already running"
else
    warn "not running"
    if [ "$CHECK_ONLY" -eq 1 ]; then
        note "start the stack (./vendor/bin/sail up -d)"
    else
        info "starting ..."
        ./vendor/bin/sail up -d --wait --wait-timeout 180 2>&1 | tail -3
        ok "started"
    fi
fi

# Everything past here needs a live database.
if [ "$CHECK_ONLY" -eq 0 ] && ! docker compose ps --status running 2>/dev/null | grep -q uresearch-mysql; then
    fail "MySQL is not running. Try ./setup.sh, or './vendor/bin/sail logs mysql' to see why."
fi

# ---------------------------------------------------------------------------
say "Database migrations"
# ---------------------------------------------------------------------------
if [ -x ./vendor/bin/sail ] && docker compose ps --status running 2>/dev/null | grep -q uresearch-mysql; then
    STATUS="$(artisan migrate:status 2>&1 || true)"

    if printf '%s' "$STATUS" | grep -q "Pending"; then
        PENDING="$(printf '%s' "$STATUS" | grep "Pending" | sed 's/\.\{2,\}/ /g' | sed 's/^ *//')"
        COUNT="$(printf '%s\n' "$PENDING" | grep -c . || true)"
        warn "$COUNT migration(s) not yet run:"
        printf '%s\n' "$PENDING" | sed 's/^/      /'

        if [ "$CHECK_ONLY" -eq 1 ]; then
            note "run $COUNT pending migration(s)"
        else
            artisan migrate --force
            ok "migrations applied"
        fi
    elif printf '%s' "$STATUS" | grep -q "Ran"; then
        ok "database is up to date"
    else
        warn "could not read migration status — is the database reachable?"
        printf '%s\n' "$STATUS" | tail -3 | sed 's/^/      /'
    fi
else
    warn "skipped — containers are not up"
fi

# ---------------------------------------------------------------------------
say "Clearing stale caches"
# ---------------------------------------------------------------------------
# Compiled Blade views and cached config survive a pull and will happily keep
# serving the previous branch's templates until they are cleared.
if [ "$CHECK_ONLY" -eq 1 ]; then
    note "clear compiled views, config, routes"
    warn "would clear compiled views, config and route caches"
    info "(Blade templates compiled on the previous branch are still being served)"
else
    if [ -x ./vendor/bin/sail ] && docker compose ps --status running 2>/dev/null | grep -q uresearch-app; then
        artisan view:clear   >/dev/null 2>&1 && ok "compiled views cleared"
        artisan config:clear >/dev/null 2>&1 && ok "config cache cleared"
        artisan route:clear  >/dev/null 2>&1 && ok "route cache cleared"
        composer_run dump-autoload --quiet >/dev/null 2>&1 && ok "autoloader rebuilt"
    else
        warn "skipped — containers are not up"
    fi
fi

# ---------------------------------------------------------------------------
say "Queue worker"
# ---------------------------------------------------------------------------
# `queue:work` loads the app once and keeps it in memory, so it runs the code
# as it was when the worker booted. After a pull it is still executing the
# OLD notification and job classes until it is restarted.
if [ "$CHECK_ONLY" -eq 1 ]; then
    note "restart the queue worker so it picks up pulled code"
    warn "would restart the queue worker"
    info "(queue:work holds the app in memory, so it still runs pre-pull code)"
else
    if docker compose ps --status running 2>/dev/null | grep -q uresearch-queue; then
        artisan queue:restart >/dev/null 2>&1 || true
        docker restart uresearch-queue >/dev/null 2>&1 && ok "queue worker restarted"
    else
        warn "queue worker is not running — emails and notifications will not be delivered"
        info "start it with: ./vendor/bin/sail up -d queue"
    fi
fi

# ---------------------------------------------------------------------------
if [ "$CHECK_ONLY" -eq 1 ]; then
    printf '\n\033[1;34m==>\033[0m Summary\n'
    if [ ${#WOULD[@]} -eq 0 ]; then
        ok "nothing to do — your checkout is already in sync"
    else
        warn "${#WOULD[@]} thing(s) need doing:"
        for w in "${WOULD[@]}"; do info "  - $w"; done
        printf '\n    Run \033[1m./sync.sh\033[0m to apply them.\n'
    fi
    printf '\n'
    exit 0
fi

cat <<'MSG'

  Done — your checkout is in sync.

    App           http://localhost:8000
    phpMyAdmin    http://localhost:8080     (root / secret)
    Mailpit       http://localhost:8025

  If something still looks wrong, ./setup.sh rebuilds from scratch and
  ./reset.sh wipes and reseeds the database.

MSG
