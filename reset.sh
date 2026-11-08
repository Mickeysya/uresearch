#!/usr/bin/env bash
# Wipe the database and rebuild it with every account and all demo data.
# Kept for muscle memory; it is ./sync.sh --fresh. Local only.
exec "$(dirname "$0")/sync.sh" --fresh "$@"
