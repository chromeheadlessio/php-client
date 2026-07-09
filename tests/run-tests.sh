#!/usr/bin/env bash
# Zero-dependency test runner for the php-client.
#   1. resolveUrl unit test (no network)
#   2. collision integration test against a `php -S` fixture server
set -euo pipefail
cd "$(dirname "$0")/.."

echo "== resolveUrl unit test =="
php tests/resolveUrl_test.php

echo
echo "== retry test =="
php tests/retry_test.php

echo
echo "== collision integration test =="
PORT="${PORT:-8771}"
php -S "127.0.0.1:${PORT}" -t tests/fixtures >/dev/null 2>&1 &
SRV=$!
trap 'kill "$SRV" 2>/dev/null || true' EXIT

# Wait for the fixture server to come up.
for _ in $(seq 1 40); do
    if curl -sf "http://127.0.0.1:${PORT}/page.html" >/dev/null 2>&1; then break; fi
    sleep 0.2
done

php tests/collision_test.php "http://127.0.0.1:${PORT}"

echo
echo "== @import integration test =="
php tests/import_test.php "http://127.0.0.1:${PORT}"

echo
echo "== timeout test =="
php tests/timeout_test.php "http://127.0.0.1:${PORT}"

echo
echo "== warnings test =="
php tests/warnings_test.php "http://127.0.0.1:${PORT}"

echo
echo "== parallel downloads test =="
php tests/parallel_test.php "http://127.0.0.1:${PORT}"

echo
echo "== resource cache unit test =="
php tests/resourceCache_unit_test.php "http://127.0.0.1:${PORT}"

echo
echo "== resource cache service test =="
SVC_PORT="${SVC_PORT:-8772}"
MOCK_CONTROL="$(mktemp -t rc-mock-control.XXXXXX)"
MOCK_LAST="$(mktemp -t rc-mock-last.XXXXXX)"
export MOCK_CONTROL MOCK_LAST
MOCK_CONTROL="$MOCK_CONTROL" MOCK_LAST="$MOCK_LAST" \
    php -S "127.0.0.1:${SVC_PORT}" tests/service-mock.php >/dev/null 2>&1 &
MOCK_SRV=$!
trap 'kill "$SRV" "$MOCK_SRV" 2>/dev/null || true; rm -f "$MOCK_CONTROL" "$MOCK_LAST"' EXIT
for _ in $(seq 1 40); do
    if curl -sf "http://127.0.0.1:${SVC_PORT}/api/capabilities" >/dev/null 2>&1; then break; fi
    sleep 0.2
done
php tests/resourceCache_service_test.php "http://127.0.0.1:${PORT}" "http://127.0.0.1:${SVC_PORT}"

echo
echo "ALL TESTS PASSED"
