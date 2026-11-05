#!/usr/bin/env bash
# Puts the running stack in a known state before recording the demo:
# three seeded days plus an older one (2026-10-30), an empty token cache,
# and an empty bucket, SFTP inbox, Mailpit inbox and WireMock journal.
#
# It only uses `docker compose exec`: the stack must already be running
# (`docker compose up -d --wait`, from the repository on the host).
set -euo pipefail

if ! docker compose exec -T app true 2>/dev/null; then
    echo "Start the stack first: docker compose up -d --wait" >&2
    exit 1
fi

app() { docker compose exec -T app "$@"; }

app php artisan migrate:fresh --seed --force >/dev/null
app php artisan tinker --execute 'Database\Seeders\SyntheticOrders::insertForDay(Carbon\CarbonImmutable::parse("2026-10-30"), config("reports.timezone"), 1800);' >/dev/null
app php artisan cache:clear >/dev/null
app php artisan tinker --execute 'Illuminate\Support\Facades\Storage::disk("reports")->deleteDirectory("daily-orders");' >/dev/null
docker compose exec -T sftp sh -c 'chmod 755 /home/partner/inbound && rm -f /home/partner/inbound/*'
app curl -fsS -X DELETE http://mailpit:8025/api/v1/messages >/dev/null
app curl -fsS -X DELETE http://partner-api:8080/__admin/requests >/dev/null
