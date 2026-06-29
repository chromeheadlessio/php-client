<?php
// Integration test for @import support (both the string form and the recursion
// into downloaded stylesheets). The fixture chain is:
//   import.html -> css/main.css
//   main.css:  @import "base.css";  @import url('theme.css');  url('../img/bg.png')
//   base.css:  @import "reset.css";   (nested string @import)
// All five resources must be downloaded and every reference rewritten.
//
// Run via tests/run-tests.sh (served by `php -S`, base URL passed as argv[1]).

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

$html = file_get_contents($base . '/import.html');
check($html !== false && strpos($html, 'main.css') !== false, 'fixture import.html fetched');

$ex = new \chromeheadlessio\Exporter(array('secretToken' => 'test'));
$ex->settings = array('baseUrl' => $base . '/');

list($exportHtmlPath, $tempZipPath, $tempZipName) = $ex->saveTempContent($html);
$tempDir = dirname($exportHtmlPath);

$resourceFiles = array_values(array_filter(scandir($tempDir), function ($f) {
    return $f !== '.' && $f !== '..' && $f !== 'export.html';
}));

// main.css, base.css, theme.css, reset.css, bg.png
check(count($resourceFiles) === 5,
    'all 5 resources downloaded (got ' . count($resourceFiles) . ')');

$blob = '';
foreach ($resourceFiles as $f) {
    $blob .= file_get_contents($tempDir . '/' . $f) . "\n";
}

// Every file in the chain was actually fetched (incl. the nested string @import).
foreach (array('MARKER-MAIN', 'MARKER-BASE', 'MARKER-THEME', 'MARKER-RESET', 'MARKER-BG') as $marker) {
    check(strpos($blob, $marker) !== false, "resource fetched: $marker");
}

// Every reference was rewritten — no original filename survives in the stored CSS.
foreach (array('base.css', 'theme.css', 'reset.css', 'bg.png') as $orig) {
    check(strpos($blob, $orig) === false, "reference rewritten (no literal '$orig' remains)");
}

$ex->cleanupTempArtifacts($tempZipPath);
echo "import_test PASSED\n";
