#!/usr/bin/env bash
# Produces docs/demo/mailpit-signed-link.png: delivers a real 300,000-order
# day, whose file is too big to attach under the default 10 MB limit, and
# screenshots the resulting email in Mailpit with the VHS image's Chromium.
#
# Run from the repository root on the host, after demo.tape, with the stack
# running and the demo image built (see demo.tape).
set -euo pipefail

app() { docker compose exec -T "$@"; }

app app php artisan tinker --execute 'Database\Seeders\SyntheticOrders::insertForDay(Carbon\CarbonImmutable::parse("2026-10-29"), config("reports.timezone"), 300000);' >/dev/null
app -e APP_URL=http://localhost:8000 app php artisan report:deliver --date=2026-10-29 --sync

docker run --rm --network integrations-kit_default -v "$PWD:/vhs" --entrypoint chromium integrations-kit-vhs \
    --headless --no-sandbox --disable-gpu --hide-scrollbars \
    --window-size=1280,900 --virtual-time-budget=10000 \
    --screenshot=/vhs/docs/demo/mailpit-signed-link.png \
    http://mailpit:8025/view/latest
