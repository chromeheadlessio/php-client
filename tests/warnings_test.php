<?php
// Tests for the resource-failure warnings API (getWarnings).
// Builds HTML referencing one real fixture + one dead path, then checks
// that getWarnings() reports only the dead URL with reason download_failed.
//
// Run via tests/run-tests.sh (requires php -S fixture server).

require __DIR__ . '/../src/Exporter.php';

function check($cond, $msg)
{
    if (!$cond) {
        fwrite(STDERR, "FAIL: $msg\n");
        exit(1);
    }
    echo "ok: $msg\n";
}

$base = isset($argv[1]) ? rtrim($argv[1], '/') : 'http://127.0.0.1:8771';

$_SERVER['HTTP_HOST']   = '127.0.0.1';
$_SERVER['HTTPS']       = '0';
$_SERVER['REQUEST_URI'] = '/';

$html = '<html><head>'
    . '<link rel="stylesheet" href="' . $base . '/a/logo.png">'
    . '<link rel="stylesheet" href="' . $base . '/a/definitely-missing-9f7.png">'
    . '</head><body>x</body></html>';

$ex = new \chromeheadlessio\Exporter(array('secretToken' => 'test'));
$ex->settings = array('baseUrl' => $base . '/');

list($exportHtmlPath, $tempZipPath, $tempZipName) = $ex->saveTempContent($html);

$warnings = $ex->getWarnings();

check(count($warnings) === 1, 'exactly one warning (got ' . count($warnings) . ')');

$w = $warnings[0];
check(strpos($w['url'], 'definitely-missing-9f7.png') !== false,
    'warning url contains definitely-missing-9f7.png');
check($w['reason'] === 'download_failed',
    'warning reason is download_failed');

// Verify no warning for the real fixture.
$logoWarnings = array_filter($warnings, function ($w) {
    return strpos($w['url'], 'logo.png') !== false;
});
check(count($logoWarnings) === 0,
    'no warning for a/logo.png');

$ex->cleanupTempArtifacts($tempZipPath);
echo "warnings_test PASSED\n";
