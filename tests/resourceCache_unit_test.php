<?php
// Unit tests for the resource-cache client (task-038):
//   Part 1 (no network): belief set (BUNDLED u SYNCED u SELF), persistent store
//     (atomic, keyed by endpoint|token, graceful no-writable-dir degradation).
//   Part 2 (fixture server): hash-and-omit manifest building via saveTempContent
//     — a believed-cached asset is OMITTED + manifested; a miss is INCLUDED.
//
// Run via tests/run-tests.sh (passes the fixture base URL as argv[1]).

require __DIR__ . '/../src/Exporter.php';

use chromeheadlessio\Exporter;
use chromeheadlessio\ResourceCache;

function check($cond, $msg)
{
    if (!$cond) {
        fwrite(STDERR, "FAIL: $msg\n");
        exit(1);
    }
    echo "ok: $msg\n";
}

function writeJson($path, $data)
{
    file_put_contents($path, json_encode($data));
}

function rrmdir($dir)
{
    if (!is_dir($dir)) return;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($dir);
}

$base = isset($argv[1]) ? rtrim($argv[1], '/') : 'http://127.0.0.1:8771';

$_SERVER['HTTP_HOST']   = '127.0.0.1';
$_SERVER['HTTPS']       = '0';
$_SERVER['REQUEST_URI'] = '/';

$root = sys_get_temp_dir() . '/rc-unit-' . uniqid();
mkdir($root, 0777, true);

// ---------------------------------------------------------------------------
// Part 1: belief set + persistent store (no network)
// ---------------------------------------------------------------------------
$HB = str_repeat('1', 64); // bundled
$HS = str_repeat('2', 64); // self-confirmed
$HY = str_repeat('3', 64); // synced (other tenant)
$HX = str_repeat('9', 64); // never present

$bundledPath = $root . '/bundled.json';
writeJson($bundledPath, array('algo' => 'sha256', 'hashes' => array($HB)));
$cacheDir = $root . '/cache';
mkdir($cacheDir, 0777, true);

$cfg = array('enabled' => true, 'bundledHashSetPath' => $bundledPath, 'cacheDir' => $cacheDir);
$endpoint = 'https://svc.example/api/export';

ResourceCache::resetStaticCaches();
$rc = new ResourceCache($cfg, $endpoint, 'tokenA');
check($rc->beliefHas($HB), 'belief: bundled hash present');
check(!$rc->beliefHas($HX), 'belief: unknown hash absent');

$rc->noteConfirmed(array($HS));
check($rc->beliefHas($HS), 'belief: self-confirmed hash present in-memory');

// Persisted + reloaded by a fresh instance (same endpoint|token).
ResourceCache::resetStaticCaches();
$rc2 = new ResourceCache($cfg, $endpoint, 'tokenA');
check($rc2->beliefHas($HS), 'store: SELF persisted across instances');
check($rc2->beliefHas($HB), 'store: BUNDLED still present after reload');

// Store is keyed by (endpoint, token): a different token must NOT see tokenA's SELF.
ResourceCache::resetStaticCaches();
$rc3 = new ResourceCache($cfg, $endpoint, 'tokenB');
check(!$rc3->beliefHas($HS), 'store: keyed by token (no cross-tenant bleed)');

// Graceful degradation: an unwritable cacheDir => bundled-only, no persistence.
$notDir = $root . '/not-a-dir';
file_put_contents($notDir, 'x'); // a FILE where a dir would go -> mkdir fails
$cfgBad = array('enabled' => true, 'bundledHashSetPath' => $bundledPath, 'cacheDir' => $notDir);
ResourceCache::resetStaticCaches();
$rcBad = new ResourceCache($cfgBad, $endpoint, 'tokenA');
$st = $rcBad->stats();
check($st['storePath'] === null, 'degrade: no store path when cacheDir is unwritable');
$rcBad->noteConfirmed(array($HY)); // must be a no-op for persistence
ResourceCache::resetStaticCaches();
$rcBad2 = new ResourceCache($cfgBad, $endpoint, 'tokenA');
check(!$rcBad2->beliefHas($HY), 'degrade: SELF not persisted without a writable dir');
check($rcBad2->beliefHas($HB), 'degrade: BUNDLED still works');

// ---------------------------------------------------------------------------
// Part 2: hash-and-omit manifest building via saveTempContent (fixture server)
// ---------------------------------------------------------------------------
$html = file_get_contents($base . '/rc/page.html');
check($html !== false, 'fixture rc/page.html fetched');

// Phase 1: run WITHOUT a cache -> discover the stored resource filenames + hashes.
$ex = new Exporter(array('secretToken' => 't'));
$ex->settings = array('baseUrl' => $base . '/rc/');
$r = $ex->saveTempContent($html);
$tempPath = $r[4];

$jsName = $cssName = $pngName = null;
$jsHash = null;
foreach (scandir($tempPath) as $f) {
    if ($f === '.' || $f === '..' || $f === 'export.html') continue;
    if (substr($f, -3) === '.js') { $jsName = $f; $jsHash = hash('sha256', file_get_contents($tempPath . '/' . $f)); }
    elseif (substr($f, -4) === '.css') { $cssName = $f; }
    else { $pngName = $f; }
}
check($jsName && $cssName && $pngName, 'phase1: js/css/png all stored');
check($jsHash !== null, 'phase1: js hash computed');
$ex->cleanupTempArtifacts($r[1]);

// Phase 2 (HIT): bundled set contains the js hash -> js OMITTED + manifested.
$bundledHit = $root . '/bundled-hit.json';
writeJson($bundledHit, array('algo' => 'sha256', 'hashes' => array($jsHash)));
ResourceCache::resetStaticCaches();
$rcHit = new ResourceCache(
    array('enabled' => true, 'bundledHashSetPath' => $bundledHit, 'cacheDir' => $cacheDir),
    $endpoint, 'tHit'
);
$exHit = new Exporter(array('secretToken' => 't'));
$exHit->settings = array('baseUrl' => $base . '/rc/');
$rHit = $exHit->saveTempContent($html, $rcHit);
$manifestHit = $rHit[3];
check(count($manifestHit) === 1, 'hit: exactly one manifest entry (got ' . count($manifestHit) . ')');
check($manifestHit[0]['hash'] === $jsHash, 'hit: manifest entry hash == js hash');
check($manifestHit[0]['path'] === $jsName, 'hit: manifest entry path == js filename');

$zip = new ZipArchive();
$zip->open($rHit[1]);
check($zip->locateName($jsName) === false, 'hit: js OMITTED from the zip');
check($zip->locateName($cssName) !== false, 'hit: css still IN the zip');
check($zip->locateName($pngName) !== false, 'hit: png still IN the zip');
check($zip->locateName('export.html') !== false, 'hit: export.html always in the zip');
$zip->close();
$exHit->cleanupTempArtifacts($rHit[1]);

// Phase 3 (MISS / patched): bundled set does NOT contain the js hash -> INCLUDED.
$bundledMiss = $root . '/bundled-miss.json';
writeJson($bundledMiss, array('algo' => 'sha256', 'hashes' => array(hash('sha256', 'unrelated-bytes'))));
ResourceCache::resetStaticCaches();
$rcMiss = new ResourceCache(
    array('enabled' => true, 'bundledHashSetPath' => $bundledMiss, 'cacheDir' => $cacheDir),
    $endpoint, 'tMiss'
);
$exMiss = new Exporter(array('secretToken' => 't'));
$exMiss->settings = array('baseUrl' => $base . '/rc/');
$rMiss = $exMiss->saveTempContent($html, $rcMiss);
check(count($rMiss[3]) === 0, 'miss: empty manifest (nothing believed cached)');
$zip = new ZipArchive();
$zip->open($rMiss[1]);
check($zip->locateName($jsName) !== false, 'miss: js INCLUDED in the zip (uploaded, not omitted)');
$zip->close();
$exMiss->cleanupTempArtifacts($rMiss[1]);

rrmdir($root);
echo "resourceCache_unit_test PASSED\n";
