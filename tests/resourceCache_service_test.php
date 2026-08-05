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
$jsName = null; $jsHash = null; $cssName = null; $pngName = null;
foreach (scandir($pr[4]) as $f) {
    if ($f === '.' || $f === '..' || $f === 'export.html') continue;
    if (substr($f, -3) === '.js') { $jsName = $f; $jsHash = hash('sha256', file_get_contents($pr[4] . '/' . $f)); }
    elseif (substr($f, -4) === '.css') { $cssName = $f; }
    else { $pngName = $f; }
}
$probe->cleanupTempArtifacts($pr[1]);
check($jsName && $jsHash, 'discovered stored js name + hash');
check($cssName && $pngName, 'discovered stored css + png names');

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

// --- Case 7: cacheCustom:"tenant" in manifest ------------------------------
ResourceCache::resetStaticCaches();
$dir = freshDir();
setControl(array('capability' => true, 'known' => array($jsHash)));
$ex = new Exporter(array('secretToken' => 't'));
$ex->settings = array(
    'baseUrl' => $base . '/rc/', 'html' => $html, 'serviceHost' => $svc, 'verifySsl' => false,
    'resourceCache' => array('enabled' => true, 'sync' => false,
        'bundledHashSetPath' => writeBundled($dir, array($jsHash)), 'cacheDir' => $dir,
        'cacheCustom' => array('scope' => 'tenant')),
);
$body = $ex->cloudRequest('pdf', array());
check(strpos($body, '%PDF') === 0, 'custom-tenant: got a PDF body');
$last = lastReq();
check($last['hadManifest'] === true, 'custom-tenant: manifest sent');
check($last['cacheCustom'] === 'tenant', 'custom-tenant: cacheCustom===tenant in the manifest');

// --- Case 8: cacheCustom:"global" in manifest -----------------------------
ResourceCache::resetStaticCaches();
$dir = freshDir();
setControl(array('capability' => true, 'known' => array($jsHash)));
$ex = new Exporter(array('secretToken' => 't'));
$ex->settings = array(
    'baseUrl' => $base . '/rc/', 'html' => $html, 'serviceHost' => $svc, 'verifySsl' => false,
    'resourceCache' => array('enabled' => true, 'sync' => false,
        'bundledHashSetPath' => writeBundled($dir, array($jsHash)), 'cacheDir' => $dir,
        'cacheCustom' => array('scope' => 'global')),
);
$body = $ex->cloudRequest('pdf', array());
check(strpos($body, '%PDF') === 0, 'custom-global: got a PDF body');
$last = lastReq();
check($last['hadManifest'] === true, 'custom-global: manifest sent');
check($last['cacheCustom'] === 'global', 'custom-global: cacheCustom===global in the manifest');

// --- Case 9: NO cacheCustom in settings -> manifest carries cacheCustom:'global' --
// Changed in 2.1.0: cacheCustom scope defaults to 'global', so the manifest is
// no longer key-absent when the caller set no cacheCustom. Restoring the old
// "key absent" assertion here would encode the pre-2.1.0 behaviour.
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
check(strpos($body, '%PDF') === 0, 'no-custom: got a PDF body');
$last = lastReq();
check($last['hadManifest'] === true, 'no-custom: manifest sent');
check($last['cacheCustom'] === 'global', 'no-custom: cacheCustom defaults to global in the manifest');

// --- Case 10: invalid scope -> NOT attached (byte-identical default) -------
ResourceCache::resetStaticCaches();
$dir = freshDir();
setControl(array('capability' => true, 'known' => array($jsHash)));
$ex = new Exporter(array('secretToken' => 't'));
$ex->settings = array(
    'baseUrl' => $base . '/rc/', 'html' => $html, 'serviceHost' => $svc, 'verifySsl' => false,
    'resourceCache' => array('enabled' => true, 'sync' => false,
        'bundledHashSetPath' => writeBundled($dir, array($jsHash)), 'cacheDir' => $dir,
        'cacheCustom' => array('scope' => 'public')),
);
$body = $ex->cloudRequest('pdf', array());
check(strpos($body, '%PDF') === 0, 'invalid-scope: got a PDF body');
$last = lastReq();
check($last['hadManifest'] === true, 'invalid-scope: manifest sent');
check($last['cacheCustom'] === null, 'invalid-scope: cacheCustom NOT attached (invalid value)');

// --- Case 11: X-Resource-Cache-Warning -> getWarnings() surfaces it -------
ResourceCache::resetStaticCaches();
$dir = freshDir();
setControl(array('capability' => true, 'known' => array($jsHash),
    'warning' => 'global-share-disabled'));
$ex = new Exporter(array('secretToken' => 't'));
$ex->settings = array(
    'baseUrl' => $base . '/rc/', 'html' => $html, 'serviceHost' => $svc, 'verifySsl' => false,
    'resourceCache' => array('enabled' => true, 'sync' => false,
        'bundledHashSetPath' => writeBundled($dir, array($jsHash)), 'cacheDir' => $dir),
);
$body = $ex->cloudRequest('pdf', array());
check(strpos($body, '%PDF') === 0, 'warning: got a PDF body');
$warnings = $ex->getWarnings();
$found = false;
$want = 'global-share-disabled';
foreach ($warnings as $w) {
    if (strpos($w['reason'], 'resource-cache: ') !== false && strpos($w['reason'], $want) !== false) {
        $found = true;
    }
}
check($found, "warning: getWarnings() includes resource-cache: $want");

// --- Case 12: cacheCustom declare-on-ship warms a COLD client (T2.2) -------
// Empty bundled set + server knows nothing: without declare-on-ship the client
// would send NO manifest and never warm. With cacheCustom set, it must declare
// the SHIPPED asset so the server can cache + confirm it.
ResourceCache::resetStaticCaches();
$dir = freshDir();
setControl(array('capability' => true, 'known' => array()));
$ex = new Exporter(array('secretToken' => 't'));
$ex->settings = array(
    'baseUrl' => $base . '/rc/', 'html' => $html, 'serviceHost' => $svc, 'verifySsl' => false,
    'resourceCache' => array('enabled' => true, 'sync' => false,
        'bundledHashSetPath' => writeBundled($dir, array()), 'cacheDir' => $dir,
        'cacheCustom' => array('scope' => 'tenant')),
);
$body = $ex->cloudRequest('pdf', array());
check(strpos($body, '%PDF') === 0, 'declare-ship: got a PDF body');
$last = lastReq();
check($last['hadManifest'] === true, 'declare-ship: manifest SENT despite empty bundled set');
check($last['cacheCustom'] === 'tenant', 'declare-ship: cacheCustom===tenant on the wire');
check($last['status'] === 200, 'declare-ship: 200, no 409 (declared asset is shipped)');
check(in_array($jsName, $last['zipEntries']), 'declare-ship: js SHIPPED (declared, not omitted)');
check($last['assetCount'] >= 1, 'declare-ship: js declared in the manifest');
$store = json_decode(file_get_contents(storeFor($dir, $endpoint, 't')), true);
check(isset($store['self'][$jsHash]), 'declare-ship: shipped asset confirmed into SELF');

// --- Case 13: the NEXT export now OMITS the warmed asset ------------------
// Fresh process (reset statics); SELF persisted on disk in $dir; server now
// holds the asset. The client should omit it -> smaller upload.
ResourceCache::resetStaticCaches();
setControl(array('capability' => true, 'known' => array($jsHash)));
$ex2 = new Exporter(array('secretToken' => 't'));
$ex2->settings = array(
    'baseUrl' => $base . '/rc/', 'html' => $html, 'serviceHost' => $svc, 'verifySsl' => false,
    'resourceCache' => array('enabled' => true, 'sync' => false,
        'bundledHashSetPath' => writeBundled($dir, array()), 'cacheDir' => $dir,
        'cacheCustom' => array('scope' => 'tenant')),
);
$body2 = $ex2->cloudRequest('pdf', array());
check(strpos($body2, '%PDF') === 0, 'warm-2nd: got a PDF body');
$last2 = lastReq();
check($last2['status'] === 200, 'warm-2nd: 200 (no 409)');
check(!in_array($jsName, $last2['zipEntries']), 'warm-2nd: js now OMITTED (SELF warmed by export 1)');

// --- Case 14: service-base derivation — serviceHost on a /v2 base ----------
// With serviceHost ending in /v2 and no serviceUrl, the capability probe must
// go to <host>/api/capabilities (the mock answers it) and caching activates by
// default (no enabled key needed on a /v2 base).
ResourceCache::resetStaticCaches();
$dir = freshDir();
setControl(array('capability' => true, 'known' => array($jsHash)));
$ex = new Exporter(array('secretToken' => 't'));
$ex->settings = array(
    'baseUrl' => $base . '/rc/', 'html' => $html, 'serviceHost' => $svc . '/v2', 'verifySsl' => false,
    // no 'enabled': the /v2 sniff decides, and it must come on
    'resourceCache' => array('sync' => false,
        'bundledHashSetPath' => writeBundled($dir, array($jsHash)), 'cacheDir' => $dir),
);
$body = $ex->cloudRequest('pdf', array());
check(strpos($body, '%PDF') === 0, 'base-v2: got a PDF body');
$last = lastReq();
check($last['hadManifest'] === true, 'base-v2: capability probe hit the /v2 host, caching active');

// --- Case 15: serviceUrl-only derives the same base ------------------------
// serviceUrl set to <v2host>/api/export, serviceHost NEVER set. Before task-231
// the capability probe went to the default (v1) serviceHost and missed; now it
// must reach the same verdict as case 14. Regression guard for that bug.
ResourceCache::resetStaticCaches();
$dir = freshDir();
setControl(array('capability' => true, 'known' => array($jsHash)));
$ex = new Exporter(array('secretToken' => 't'));
$ex->settings = array(
    'baseUrl' => $base . '/rc/', 'html' => $html, 'serviceUrl' => $svc . '/v2/api/export', 'verifySsl' => false,
    // serviceHost deliberately unset
    'resourceCache' => array('sync' => false,
        'bundledHashSetPath' => writeBundled($dir, array($jsHash)), 'cacheDir' => $dir),
);
$body = $ex->cloudRequest('pdf', array());
check(strpos($body, '%PDF') === 0, 'base-urlonly: got a PDF body');
$last = lastReq();
check($last['hadManifest'] === true, 'base-urlonly: serviceUrl-only reaches the same verdict as serviceHost');

// --- Case 16: non-canonical serviceUrl falls back to serviceHost (v1) ------
// A serviceUrl that is not the canonical .../api/export shape falls back to
// serviceHost; with serviceHost unset that is the v1 default, so caching stays
// off. The query string keeps the URL reachable by the mock while breaking the
// canonical-shape match.
ResourceCache::resetStaticCaches();
setControl(array('capability' => true, 'known' => array($jsHash)));
$ex = new Exporter(array('secretToken' => 't'));
$ex->settings = array(
    'baseUrl' => $base . '/rc/', 'html' => $html,
    'serviceUrl' => $svc . '/api/export?noncanonical=1', 'verifySsl' => false,
    // serviceHost deliberately unset -> v1 default applies
    'resourceCache' => array('enabled' => true, 'sync' => false,
        'bundledHashSetPath' => writeBundled($dir, array($jsHash)), 'cacheDir' => $dir),
);
$body = $ex->cloudRequest('pdf', array());
check(strpos($body, '%PDF') === 0, 'base-noncanonical: got a PDF body');
$last = lastReq();
check($last['hadManifest'] === false, 'base-noncanonical: non-canonical serviceUrl falls back to v1, cache off');

// --- Case 17: mixed believed + shipped-declared survives a 409 re-send -----
// Regression guard for the task-231 change-5 $baseOmit fix. With the default
// cacheCustom scope 'global', buildResourceManifest declares BOTH the believed
// js (omitted from the zip) and the shipped-but-not-believed css/png (declared
// but still uploaded). The 409 re-send must re-add ONLY the missing believed
// asset; the shipped-declared ones must never enter the omit-set, and the
// manifest must survive onto the re-sent request.
ResourceCache::resetStaticCaches();
$dir = freshDir();
setControl(array('capability' => true, 'known' => array())); // server believes nothing
$ex = new Exporter(array('secretToken' => 't'));
$ex->settings = array(
    'baseUrl' => $base . '/rc/', 'html' => $html, 'serviceHost' => $svc, 'verifySsl' => false,
    'resourceCache' => array('enabled' => true, 'sync' => false,
        'bundledHashSetPath' => writeBundled($dir, array($jsHash)), 'cacheDir' => $dir),
    // no cacheCustom -> scope defaults to 'global' -> declare-ship on
);
$countFile = getenv('MOCK_COUNT');
$countBefore = $countFile ? (int) @file_get_contents($countFile) : 0;
$body = $ex->cloudRequest('pdf', array());
check(strpos($body, '%PDF') === 0, 'mixed: recovered to a PDF after 409 re-send');
$last = lastReq();
check($last['status'] === 200, 'mixed: final request was 200');
check($last['hadManifest'] === true, 'mixed: manifest still present on re-send');
check(in_array($cssName, $last['zipEntries']), 'mixed: shipped-but-not-believed asset stays in the re-sent zip');
check(in_array($jsName, $last['zipEntries']), 'mixed: believed asset re-added to the re-sent zip');
$countAfter = $countFile ? (int) @file_get_contents($countFile) : 0;
check($countAfter - $countBefore === 2, 'mixed: exactly two /api/export requests (409 + re-send), not three');

// --- Case 18: cacheCustom:'global' rides along by default on the wire -------
// No cacheCustom configured anywhere: on a /v2 base with no explicit enabled key
// the cache comes on by default AND the manifest carries cacheCustom:'global'
// (the 2.1.0 default scope) — the default survives onto the wire, not just in
// the gate.
ResourceCache::resetStaticCaches();
$dir = freshDir();
setControl(array('capability' => true, 'known' => array($jsHash)));
$ex = new Exporter(array('secretToken' => 't'));
$ex->settings = array(
    'baseUrl' => $base . '/rc/', 'html' => $html, 'serviceHost' => $svc . '/v2', 'verifySsl' => false,
    'resourceCache' => array('sync' => false,
        'bundledHashSetPath' => writeBundled($dir, array($jsHash)), 'cacheDir' => $dir),
);
$body = $ex->cloudRequest('pdf', array());
check(strpos($body, '%PDF') === 0, 'default-wire: got a PDF body');
$last = lastReq();
check($last['hadManifest'] === true, 'default-wire: cache on by default against a /v2 base');
check($last['cacheCustom'] === 'global', 'default-wire: cacheCustom defaults to global on the wire');

echo "resourceCache_service_test PASSED\n";
