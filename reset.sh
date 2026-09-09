#!/usr/bin/env bash
# Wipe the database and reseed it. Local only — never point this at anything real.
set -euo pipefail
cd "$(dirname "$0")"

read -rp "This destroys all local data in the 'uresearch' database. Continue? [y/N] " reply
[[ "$reply" =~ ^[Yy]$ ]] || { echo "Cancelled."; exit 0; }

php artisan migrate:fresh --seed
echo "Database reset. Password for every account: password"
