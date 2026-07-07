<?php
// Tests for opt-in parallel resource downloads (curl_multi).
// Runs saveTempContent twice — once sequential, once parallel — and asserts
// the produced temp directories are byte-for-byte identical. Also tests
// the CSS @import recursion chain and dead-URL warning recording.
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

function compareTempOutput($exSeq, $exPar, $label)
{
    list($htmlPathSeq, $zipPathSeq, $zipNameSeq) = $exSeq;
    list($htmlPathPar, $zipPathPar, $zipNamePar) = $exPar;

    $dirSeq = dirname($htmlPathSeq);
    $dirPar = dirname($htmlPathPar);

    // List resource files (exclude export.html).
    $filesSeq = array_values(array_filter(scandir($dirSeq), function ($f) {
        return $f !== '.' && $f !== '..' && $f !== 'export.html';
    }));
    $filesPar = array_values(array_filter(scandir($dirPar), function ($f) {
        return $f !== '.' && $f !== '..' && $f !== 'export.html';
    }));

    sort($filesSeq);
    sort($filesPar);

    check($filesSeq === $filesPar, "$label: same set of resource filenames");

    foreach ($filesSeq as $i => $f) {
        $contentSeq = file_get_contents($dirSeq . '/' . $f);
        $contentPar = file_get_contents($dirPar . '/' . $filesPar[$i]);
        check($contentSeq === $contentPar, "$label: file '$f' content matches");
    }

    $htmlSeq = file_get_contents($htmlPathSeq);
    $htmlPar = file_get_contents($htmlPathPar);
    check($htmlSeq === $htmlPar, "$label: export.html bodies match");

    return array($zipPathSeq, $zipPathPar);
}

$base = isset($argv[1]) ? rtrim($argv[1], '/') : 'http://127.0.0.1:8771';

$_SERVER['HTTP_HOST']   = '127.0.0.1';
$_SERVER['HTTPS']       = '0';
$_SERVER['REQUEST_URI'] = '/';

// ---- Part 1: collision fixture (page.html) ----
$html = file_get_contents($base . '/page.html');
check($html !== false && strpos($html, 'logo.png') !== false, 'fixture page.html fetched');

$exSeq = new \chromeheadlessio\Exporter(array('secretToken' => 'test'));
$exSeq->settings = array('baseUrl' => $base . '/', 'parallelDownloads' => false);
list($htmlPathSeq, $zipPathSeq, $zipNameSeq) = $exSeq->saveTempContent($html);

$exPar = new \chromeheadlessio\Exporter(array('secretToken' => 'test'));
$exPar->settings = array('baseUrl' => $base . '/', 'parallelDownloads' => true);
list($htmlPathPar, $zipPathPar, $zipNamePar) = $exPar->saveTempContent($html);

list($z1, $z2) = compareTempOutput(
    array($htmlPathSeq, $zipPathSeq, $zipNameSeq),
    array($htmlPathPar, $zipPathPar, $zipNamePar),
    'collision page'
);
$exSeq->cleanupTempArtifacts($z1);
$exPar->cleanupTempArtifacts($z2);

// ---- Part 2: @import chain fixture (import.html) ----
$html2 = file_get_contents($base . '/import.html');
check($html2 !== false && strpos($html2, 'main.css') !== false, 'fixture import.html fetched');

$exSeq2 = new \chromeheadlessio\Exporter(array('secretToken' => 'test'));
$exSeq2->settings = array('baseUrl' => $base . '/', 'parallelDownloads' => false);
list($htmlPathSeq2, $zipPathSeq2, $zipNameSeq2) = $exSeq2->saveTempContent($html2);

$exPar2 = new \chromeheadlessio\Exporter(array('secretToken' => 'test'));
$exPar2->settings = array('baseUrl' => $base . '/', 'parallelDownloads' => true);
list($htmlPathPar2, $zipPathPar2, $zipNamePar2) = $exPar2->saveTempContent($html2);

list($z3, $z4) = compareTempOutput(
    array($htmlPathSeq2, $zipPathSeq2, $zipNameSeq2),
    array($htmlPathPar2, $zipPathPar2, $zipNamePar2),
    '@import chain'
);
$exSeq2->cleanupTempArtifacts($z3);
$exPar2->cleanupTempArtifacts($z4);

// ---- Part 2b: chunking regression — concurrency smaller than the URL count
// forces multiple curl_multi chunks (and CSS-depth batches). Every resource
// must still be prefetched and the output must still match sequential mode.
$exPar2b = new \chromeheadlessio\Exporter(array('secretToken' => 'test'));
$exPar2b->settings = array(
    'baseUrl' => $base . '/',
    'parallelDownloads' => true,
    'parallelConcurrency' => 2,
);
list($htmlPathPar2b, $zipPathPar2b, $zipNamePar2b) = $exPar2b->saveTempContent($html2);

$exSeq2b = new \chromeheadlessio\Exporter(array('secretToken' => 'test'));
$exSeq2b->settings = array('baseUrl' => $base . '/', 'parallelDownloads' => false);
list($htmlPathSeq2b, $zipPathSeq2b, $zipNameSeq2b) = $exSeq2b->saveTempContent($html2);

list($z5, $z6) = compareTempOutput(
    array($htmlPathSeq2b, $zipPathSeq2b, $zipNameSeq2b),
    array($htmlPathPar2b, $zipPathPar2b, $zipNamePar2b),
    '@import chain, concurrency=2'
);
$exSeq2b->cleanupTempArtifacts($z5);
$exPar2b->cleanupTempArtifacts($z6);

// ---- Part 3: dead URL — parallel mode must record a warning ----
$deadHtml = '<html><head>'
    . '<link rel="stylesheet" href="' . $base . '/a/definitely-missing-9f7.png">'
    . '</head><body>x</body></html>';

$exDead = new \chromeheadlessio\Exporter(array('secretToken' => 'test'));
$exDead->settings = array('baseUrl' => $base . '/', 'parallelDownloads' => true);
list($htmlPathDead, $zipPathDead, $zipNameDead) = $exDead->saveTempContent($deadHtml);

$warnings = $exDead->getWarnings();
check(count($warnings) > 0, 'parallel mode: at least one warning for dead URL');
$found = false;
foreach ($warnings as $w) {
    if (strpos($w['url'], 'definitely-missing-9f7.png') !== false) {
        $found = true;
        check($w['reason'] === 'download_failed',
            'parallel mode: dead URL reason is download_failed');
    }
}
check($found, 'parallel mode: warning contains the dead URL');
$exDead->cleanupTempArtifacts($zipPathDead);

echo "parallel_test PASSED\n";
