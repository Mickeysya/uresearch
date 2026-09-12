#!/usr/bin/env bash
# Shared helpers for setup.sh and sync.sh. Sourced, not run.
#
# Both scripts need the same thing: make sure the developer's own .env has
# every setting .env.example declares. .env is git-ignored, so a pull never
# updates it — when a teammate adds a key, everyone else's app silently falls
# back to a config default and misbehaves in a way that looks nothing like a
# missing variable. That is exactly how REDIS_HOST took down every approval
# in the portal for a week.
#
# Keeping it here rather than in both scripts means a new key is handled by
# whichever one the developer happens to run.

# env_missing_keys — prints the keys in .env.example that .env does not have.
env_missing_keys() {
    [ -f .env ] && [ -f .env.example ] || return 0

    local key
    while IFS= read -r key; do
        grep -q "^${key}=" .env 2>/dev/null || printf '%s\n' "$key"
    done < <(grep -oE '^[A-Z][A-Z0-9_]*=' .env.example | tr -d '=')
}

# env_backfill — appends any missing keys, with .env.example's values.
# Echoes what it added so the caller can report it.
env_backfill() {
    local missing
    mapfile -t missing < <(env_missing_keys)
    [ ${#missing[@]} -eq 0 ] && return 0

    {
        printf '\n# --- added automatically on %s ---\n' "$(date '+%Y-%m-%d %H:%M')"
        local k
        for k in "${missing[@]}"; do
            grep -m1 "^${k}=" .env.example
        done
    } >> .env

    printf '%s\n' "${missing[@]}"
}

# db_is_built — true when migrations have already run against this database.
# The difference between a first install and a re-run, and therefore between
# seeding and not touching the data.
db_is_built() {
    ./vendor/bin/sail artisan migrate:status 2>/dev/null | grep -q 'Ran'
}
