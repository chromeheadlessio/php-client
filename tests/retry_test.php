<?php
// Tests for opt-in retry with exponential backoff + Retry-After.
// No fixture server needed — points at 127.0.0.1:1 (connection refused).
//
// Case A: retries=0 — single attempt, fast failure.
// Case B: retries=2, retryDelayMs=200 — 3 attempts with backoff.
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

// Stub $_SERVER as the production pipeline does in CLI context.
$_SERVER['HTTP_HOST']   = '127.0.0.1';
$_SERVER['HTTPS']       = '0';
$_SERVER['REQUEST_URI'] = '/';

// ---- Case A: retries=0 (default) — must throw fast ----
$ex = new \chromeheadlessio\Exporter(array('secretToken' => 'test'));
$ex->settings = array(
    'serviceHost' => 'http://127.0.0.1:1',
    'html'        => '<html><body>x</body></html>',
    'retries'     => 0,
    'debug'       => false,
);

$start = microtime(true);
$threw = false;
try {
    $ex->cloudRequest('pdf', array());
} catch (\Exception $e) {
    $threw = true;
    check(strpos($e->getMessage(), 'Error when sending request') !== false,
        'retries=0: exception message mentions connection error');
}
$elapsed = microtime(true) - $start;

check($threw, 'retries=0: exception thrown');
check($elapsed < 5, 'retries=0: completed in ' . round($elapsed, 2) . 's (expected < 5s)');
echo "  (elapsed: " . round($elapsed, 2) . "s)\n";

// ---- Case B: retries=2, retryDelayMs=200 — 3 attempts with backoff ----
$ex2 = new \chromeheadlessio\Exporter(array('secretToken' => 'test'));
$ex2->settings = array(
    'serviceHost'   => 'http://127.0.0.1:1',
    'html'          => '<html><body>x</body></html>',
    'retries'       => 2,
    'retryDelayMs'  => 200,
    'debug'         => false,
);

$start = microtime(true);
$threw = false;
try {
    $ex2->cloudRequest('pdf', array());
} catch (\Exception $e) {
    $threw = true;
}
$elapsed = microtime(true) - $start;

check($threw, 'retries=2: exception thrown');
check($elapsed >= 0.6, 'retries=2: elapsed >= 0.6s (got ' . round($elapsed, 2) . 's) — confirms backoff ran');
check($elapsed < 20, 'retries=2: elapsed < 20s (got ' . round($elapsed, 2) . 's)');
echo "  (elapsed: " . round($elapsed, 2) . "s)\n";

echo "retry_test PASSED\n";
