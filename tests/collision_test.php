<?php
// Integration test for the basename-collision fix. Two resources that share a
// basename ("logo.png") in different folders must produce TWO distinct stored
// files and two distinct references in export.html — not collide into one.
//
// Run via tests/run-tests.sh, which serves tests/fixtures/ with `php -S` and
// passes the base URL as argv[1].

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

// php-client reads these eagerly in CLI context.
$_SERVER['HTTP_HOST']   = '127.0.0.1';
$_SERVER['HTTPS']       = '0';
$_SERVER['REQUEST_URI'] = '/';

$html = file_get_contents($base . '/page.html');
check($html !== false && strpos($html, 'logo.png') !== false, 'fixture page.html fetched');

$ex = new \chromeheadlessio\Exporter(array('secretToken' => 'test'));
// baseUrl is the page's directory (with trailing slash), as KoolReport passes it.
$ex->settings = array('baseUrl' => $base . '/');

list($exportHtmlPath, $tempZipPath, $tempZipName) = $ex->saveTempContent($html);
$tempDir    = dirname($exportHtmlPath);
$exportHtml = file_get_contents($exportHtmlPath);

$resourceFiles = array_values(array_filter(scandir($tempDir), function ($f) {
    return $f !== '.' && $f !== '..' && $f !== 'export.html';
}));

check(count($resourceFiles) === 2,
    'two distinct resource files saved (got ' . count($resourceFiles) . ')');
check($resourceFiles[0] !== $resourceFiles[1],
    'the two stored files have distinct names (no collision)');

$values = array();
foreach ($resourceFiles as $f) {
    $values[] = file_get_contents($tempDir . '/' . $f);
}
check($values[0] !== $values[1], 'the two saved files have distinct content');
$joined = implode("\n", $values);
check(strpos($joined, 'logo-from-a') !== false, 'content of a/logo.png preserved');
check(strpos($joined, 'logo-from-b') !== false, 'content of b/logo.png preserved');

foreach ($resourceFiles as $f) {
    check(strpos($exportHtml, $f) !== false, "export.html references stored file $f");
}

$ex->cleanupTempArtifacts($tempZipPath);
echo "collision_test PASSED\n";
