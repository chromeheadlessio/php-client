<?php
// Service-level tests for the resource-cache client (task-038) against the mock
// export service (tests/service-mock.php). Exercises capability gating, the
// 409 { missing } re-send, X-Resource-Cached -> SELF, delta sync, and the
// hard-off default.
//
//   argv[1] = fixture base URL (rc page + assets)
//   argv[2] = mock service base URL
//   env MOCK_CONTROL / MOCK_LAST = JSON control / last-request files
// Run via tests/run-tests.sh.

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

$base = isset($argv[1]) ? rtrim($argv[1], '/') : 'http://127.0.0.1:8771';
$svc  = isset($argv[2]) ? rtrim($argv[2], '/') : 'http://127.0.0.1:8772';
$CONTROL = getenv('MOCK_CONTROL');
$LAST    = getenv('MOCK_LAST');
check($CONTROL && $LAST, 'mock control/last env present');

$_SERVER['HTTP_HOST']   = '127.0.0.1';
$_SERVER['HTTPS']       = '0';
$_SERVER['REQUEST_URI'] = '/';

$html = file_get_contents($base . '/rc/page.html');
check($html !== false, 'fixture rc/page.html fetched');

// Discover the stored js filename + hash (as the client will store it).
$probe = new Exporter(array('secretToken' => 't'));
$probe->settings = array('baseUrl' => $base . '/rc/');
$pr = $probe->saveTempContent($html);
$jsName = null; $jsHash = null;
foreach (scandir($pr[4]) as $f) {
    if (substr($f, -3) === '.js') { $jsName = $f; $jsHash = hash('sha256', file_get_contents($pr[4] . '/' . $f)); }
}
$probe->cleanupTempArtifacts($pr[1]);
check($jsName && $jsHash, 'discovered stored js name + hash');

function setControl($arr)
{
    global $CONTROL;
    file_put_contents($CONTROL, json_encode($arr));
}
function lastReq()
{
    global $LAST;
    return json_decode(file_get_contents($LAST), true);
}
function storeFor($cacheDir, $endpoint, $token)
{
    return rtrim($cacheDir, '/') . '/koolreport-cache-' . sha1($endpoint . '|' . $token) . '.json';
}
function freshDir()
{
    $d = sys_get_temp_dir() . '/rc-svc-' . uniqid();
    mkdir($d, 0777, true);
    return $d;
}
function writeBundled($dir, $hashes)
{
    $p = $dir . '/bundled.json';
    file_put_contents($p, json_encode(array('algo' => 'sha256', 'hashes' => $hashes)));
    return $p;
}

$endpoint = $svc . '/api/export';

// --- Case 1: WARM HIT + X-Resource-Cached -> SELF -------------------------
ResourceCache::resetStaticCaches();
$dir = freshDir();
setControl(array('capability' => true, 'known' => array($jsHash)));
$ex = new Exporter(array('secretToken' => 't'));
$ex->settings = array(
    'baseUrl' => $base . '/rc/', 'html' => $html, 'serviceHost' => $svc, 'verifySsl' => false,
    'resourceCache' => array('enabled' => true, 'sync' => false,
        'bundledHashSetPath' => writeBundled($dir, array($jsHash)), 'cacheDir' => $dir),
);
$body = $ex->cloudRequest('pdf', array());
check(strpos($body, '%PDF') === 0, 'hit: got a PDF body');
$last = lastReq();
check($last['hadManifest'] === true, 'hit: server saw a manifest');
check(!in_array($jsName, $last['zipEntries']), 'hit: js was OMITTED from the upload');
$store = json_decode(file_get_contents(storeFor($dir, $endpoint, 't')), true);
check(isset($store['self'][$jsHash]), 'hit: X-Resource-Cached merged into SELF (persisted)');

// --- Case 2: MISS -> 409 { missing } -> re-send -> 200 --------------------
ResourceCache::resetStaticCaches();
$dir = freshDir();
setControl(array('capability' => true, 'known' => array())); // server has nothing
$ex = new Exporter(array('secretToken' => 't'));
$ex->settings = array(
    'baseUrl' => $base . '/rc/', 'html' => $html, 'serviceHost' => $svc, 'verifySsl' => false,
    'resourceCache' => array('enabled' => true, 'sync' => false,
        'bundledHashSetPath' => writeBundled($dir, array($jsHash)), 'cacheDir' => $dir),
);
$body = $ex->cloudRequest('pdf', array());
check(strpos($body, '%PDF') === 0, 'miss: recovered to a PDF after 409 re-send');
$last = lastReq();
check($last['status'] === 200, 'miss: final request was 200');
check($last['hadManifest'] === true, 'miss: manifest still present on re-send');
check(in_array($jsName, $last['zipEntries']), 'miss: js was ADDED back into the re-sent zip');

// --- Case 3: capability OFF -> full zip, no manifest ----------------------
ResourceCache::resetStaticCaches();
$dir = freshDir();
setControl(array('capability' => false));
$ex = new Exporter(array('secretToken' => 't'));
$ex->settings = array(
    'baseUrl' => $base . '/rc/', 'html' => $html, 'serviceHost' => $svc, 'verifySsl' => false,
    'resourceCache' => array('enabled' => true, 'sync' => false,
        'bundledHashSetPath' => writeBundled($dir, array($jsHash)), 'cacheDir' => $dir),
);
$body = $ex->cloudRequest('pdf', array());
check(strpos($body, '%PDF') === 0, 'cap-off: got a PDF body');
$last = lastReq();
check($last['hadManifest'] === false, 'cap-off: NO manifest sent (server unsupported)');
check(in_array($jsName, $last['zipEntries']), 'cap-off: js shipped in full zip');

// --- Case 4: client caching disabled (default off) -> full zip -----------
ResourceCache::resetStaticCaches();
setControl(array('capability' => true, 'known' => array($jsHash)));
$ex = new Exporter(array('secretToken' => 't'));
$ex->settings = array(
    'baseUrl' => $base . '/rc/', 'html' => $html, 'serviceHost' => $svc, 'verifySsl' => false,
    // no resourceCache config at all
);
$body = $ex->cloudRequest('pdf', array());
check(strpos($body, '%PDF') === 0, 'off: got a PDF body');
$last = lastReq();
check($last['hadManifest'] === false, 'off: no manifest when client has not opted in');

// --- Case 5: delta sync brings another tenant's promoted hash -> omit ----
ResourceCache::resetStaticCaches();
$dir = freshDir();
$privateHash = str_repeat('7', 64);
setControl(array('capability' => true, 'known' => array($jsHash),
    'added' => array($jsHash), 'cursor' => 5, 'full' => true));
$ex = new Exporter(array('secretToken' => 't'));
$ex->settings = array(
    'baseUrl' => $base . '/rc/', 'html' => $html, 'serviceHost' => $svc, 'verifySsl' => false,
    // BUNDLED empty: the only way jsHash is believed cached is via SYNCED.
    'resourceCache' => array('enabled' => true, 'sync' => true,
        'bundledHashSetPath' => writeBundled($dir, array()), 'cacheDir' => $dir),
);
$body = $ex->cloudRequest('pdf', array());
check(strpos($body, '%PDF') === 0, 'sync: got a PDF body');
$last = lastReq();
check(!in_array($jsName, $last['zipEntries']), 'sync: a SYNCED (other-tenant) hash let the client omit');
$store = json_decode(file_get_contents(storeFor($dir, $endpoint, 't')), true);
check(isset($store['synced'][$jsHash]), 'sync: promoted hash stored in SYNCED');
check(!isset($store['synced'][$privateHash]), 'sync: a private (unpromoted) hash never arrives via sync');
check((int)$store['cursor'] === 5, 'sync: cursor advanced');

// --- Case 6: sync FAILURE must not break the export ----------------------
ResourceCache::resetStaticCaches();
$dir = freshDir();
setControl(array('capability' => true, 'known' => array(), 'syncFail' => true));
$ex = new Exporter(array('secretToken' => 't'));
$ex->settings = array(
    'baseUrl' => $base . '/rc/', 'html' => $html, 'serviceHost' => $svc, 'verifySsl' => false,
    'resourceCache' => array('enabled' => true, 'sync' => true,
        'bundledHashSetPath' => writeBundled($dir, array()), 'cacheDir' => $dir),
);
$body = $ex->cloudRequest('pdf', array());
check(strpos($body, '%PDF') === 0, 'sync-fail: export still succeeded despite a failing sync');

echo "resourceCache_service_test PASSED\n";
