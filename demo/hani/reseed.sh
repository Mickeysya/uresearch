#!/usr/bin/env bash
# Puts the demo data back to the recording's starting point.
#   ./reseed.sh          full: ./sync.sh --fresh --yes, the UAT seeder, re-viva:monitor
#   ./reseed.sh --fast   only Hani's two idempotent seeders + re-viva:monitor
#                        (development; a full recording always uses the full reseed)
# Mailpit is emptied at the end, then re-viva:monitor runs so its panel
# reminders are the only mail waiting when recording starts.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
APP=uresearch-app
# timeout: a seeder once spun for 20 min with no output; fail loudly instead.
art() { docker exec "$APP" timeout 300 php artisan "$@"; }

if [ "${1:-}" = "--fast" ]; then
  art db:seed --force --class="App\\Modules\\Hani\\Database\\Seeders\\HaniDemoSeeder" >/dev/null
else
  (cd "$ROOT" && ./sync.sh --fresh --yes) | tail -4
fi
art db:seed --force --class="App\\Modules\\Hani\\Database\\Seeders\\ExaminerNominationUatSeeder"

curl -s -X DELETE http://localhost:8025/api/v1/messages >/dev/null
art re-viva:monitor | tail -5
# Queued notifications: give the worker a moment to deliver them.
sleep 5
echo "Mailpit: $(curl -s 'http://localhost:8025/api/v1/messages?limit=1' | python3 -c 'import json,sys; print(json.load(sys.stdin)["total"])') message(s)"
