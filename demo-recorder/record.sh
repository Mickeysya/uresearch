#!/usr/bin/env bash
# Records the demo video in Docker, so nothing has to be installed in WSL:
# the image has Node, Playwright's Chromium (with every library it needs),
# ffmpeg and poppler.
#
#   ./demo-recorder/record.sh             # all clips
#   ./demo-recorder/record.sh --clip 03   # one clip (repeat --clip for more)
#
# Inside the container this runs `npm run record -- <your arguments>`.
# The demo data is reset here first, on the host, where ./vendor/bin/sail
# works: nureen:demo-fresh --reset, then nureen:demo-fresh.
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
APP_ROOT="$(dirname "$HERE")"
IMAGE="uresearch-demo-recorder:1.65.1"

BASE_URL="$(grep -E '^BASE_URL=' "$HERE/.env" 2>/dev/null | cut -d= -f2- || true)"
BASE_URL="${BASE_URL:-http://localhost:8000}"
MAILPIT_URL="$(grep -E '^MAILPIT_URL=' "$HERE/.env" 2>/dev/null | cut -d= -f2- || true)"
MAILPIT_URL="${MAILPIT_URL:-http://localhost:8025}"

if ! curl -s -o /dev/null --max-time 5 "$BASE_URL/login"; then
  echo "The app is not reachable at $BASE_URL. Start it with:  ./vendor/bin/sail up -d  then run this again." >&2
  exit 2
fi
if ! curl -s -o /dev/null --max-time 5 "$MAILPIT_URL"; then
  echo "Mailpit is not reachable at $MAILPIT_URL. Start it with:  ./vendor/bin/sail up -d  then run this again." >&2
  exit 2
fi

[ -f "$HERE/.env" ] || cp "$HERE/.env.example" "$HERE/.env"

if ! docker image inspect "$IMAGE" >/dev/null 2>&1; then
  echo "Building the recorder image (first run only)..."
  docker build -t "$IMAGE" "$HERE"
fi

cd "$APP_ROOT"

# Applications the recorder submitted on camera are listed in
# .demo-created.json; registering them as demo data lets --reset remove
# them with the rest (otherwise every run would leave one behind).
register_created() {
  local file="$HERE/.demo-created.json"
  [ -s "$file" ] || return 0
  ./vendor/bin/sail artisan tinker --execute="\App\Modules\Nureen\Models\DemoRecord::remember('applications', json_decode('$(cat "$file")', true)); echo 'registered';" >/dev/null
  rm -f "$file"
}

register_created
echo "Resetting the demo data..."
./vendor/bin/sail artisan nureen:demo-fresh --reset
./vendor/bin/sail artisan nureen:demo-fresh | tee "$HERE/.demo-fresh.txt"
./vendor/bin/sail artisan tinker --execute="$(sed '1{/^<?php/d}' "$HERE/scripts/demo-info.php")" | grep '^DEMOINFO:' >> "$HERE/.demo-fresh.txt"

docker run --rm --network host --ipc=host \
  -u "$(id -u):$(id -g)" -e HOME=/tmp -e SKIP_RESET=1 \
  -v "$HERE":/recorder -w /recorder \
  "$IMAGE" \
  bash -c '[ -d node_modules/playwright ] || npm ci --no-audit --no-fund; npm run record -- "$@"' _ "$@" \
  && status=0 || status=$?

register_created
exit $status
