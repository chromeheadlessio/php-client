<?php
// Tests for bounded timeouts on resource/page fetches.
// Part 1: non-routable address — should fail fast (no network dependency).
// Part 2: real fixture from the php -S server (base URL from argv[1]).
//
// Run via tests/run-tests.sh.

require __DIR__ . '/../src/Exporter.php';

function check($cond, $msg)
{
    if (!$cond) {
        fwrite(STDERR, "FAIL: $msg\n");
        exit(1);
    }
    echo "ok: $msg\n";
}

// Part 1 — non-routable address with 2s timeout must finish in < 8s.
$start = microtime(true);
$result = \chromeheadlessio\Exporter::url_get_contents('http://10.255.255.1/x.css', 2);
$elapsed = microtime(true) - $start;

check($result === false || $result === '', 'url_get_contents to non-routable address returns falsy');
check($elapsed < 8, "non-routable fetch completed in " . round($elapsed, 2) . "s (expected < 8s)");

echo "  (elapsed: " . round($elapsed, 2) . "s)\n";

// Part 2 — real local fixture, requires php -S fixture server.
$base = isset($argv[1]) ? rtrim($argv[1], '/') : 'http://127.0.0.1:8771';
$html = \chromeheadlessio\Exporter::url_get_contents($base . '/page.html', 5);
check(is_string($html) && strpos($html, 'logo.png') !== false,
    'url_get_contents of fixture page.html returns content containing logo.png');

echo "timeout_test PASSED\n";
