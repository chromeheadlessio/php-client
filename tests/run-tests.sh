#!/usr/bin/env bash
# Zero-dependency test runner for the php-client.
#   1. resolveUrl unit test (no network)
#   2. collision integration test against a `php -S` fixture server
set -euo pipefail
cd "$(dirname "$0")/.."

echo "== resolveUrl unit test =="
php tests/resolveUrl_test.php

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
echo "ALL TESTS PASSED"
