<?php
// Mock export service for the resource-cache client tests. Run as a php -S
// router:  MOCK_CONTROL=/tmp/ctl.json MOCK_LAST=/tmp/last.json \
//          php -S 127.0.0.1:PORT tests/service-mock.php
//
// Behavior is driven by a JSON control file (env MOCK_CONTROL) the test rewrites
// per case; the router records the last /api/export it saw to env MOCK_LAST so
// the test can assert what the client actually sent.
//
// Control file shape (all optional):
//   { "capability": true, "known": ["<hash>", ...],
//     "added": ["<hash>", ...], "cursor": 0, "full": true, "syncFail": false }

function ctl()
{
    $p = getenv('MOCK_CONTROL');
    if ($p && is_file($p)) {
        $d = json_decode(file_get_contents($p), true);
        if (is_array($d)) return $d;
    }
    return array();
}

function ctlGet($k, $default = null)
{
    $c = ctl();
    return isset($c[$k]) ? $c[$k] : $default;
}

function recordLast($rec)
{
    $p = getenv('MOCK_LAST');
    if ($p) {
        @file_put_contents($p, json_encode($rec));
    }
}

function sendJson($status, $arr)
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($arr);
}

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

if ($uri === '/api/capabilities' && $method === 'GET') {
    if (ctlGet('capability', false)) {
        sendJson(200, array('resourceCache' => true));
    } else {
        sendJson(200, new stdClass());
    }
    return true;
}

if ($uri === '/api/cache/manifest' && $method === 'GET') {
    if (ctlGet('syncFail', false)) {
        http_response_code(500);
        echo 'sync boom';
        return true;
    }
    sendJson(200, array(
        'algo'   => 'sha256',
        'added'  => array_values((array) ctlGet('added', array())),
        'cursor' => (int) ctlGet('cursor', 0),
        'full'   => (bool) ctlGet('full', true),
    ));
    return true;
}

if ($uri === '/api/export' && $method === 'POST') {
    $known = array_flip((array) ctlGet('known', array()));

    // List the entries actually present in the uploaded zip.
    $zipEntries = array();
    if (isset($_FILES['fileToExport']['tmp_name']) && is_file($_FILES['fileToExport']['tmp_name'])) {
        $zip = new ZipArchive();
        if ($zip->open($_FILES['fileToExport']['tmp_name']) === true) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $zipEntries[$zip->getNameIndex($i)] = true;
            }
            $zip->close();
        }
    }

    $manifestRaw = isset($_POST['resourceManifest']) ? $_POST['resourceManifest'] : null;
    $hadManifest = ($manifestRaw !== null);
    $assets = array();
    if ($hadManifest) {
        $m = json_decode($manifestRaw, true);
        if (is_array($m) && isset($m['assets']) && is_array($m['assets'])) {
            $assets = $m['assets'];
        }
    }

    $missing = array();
    $allHashes = array();
    foreach ($assets as $a) {
        if (!isset($a['path'], $a['hash'])) continue;
        $allHashes[] = $a['hash'];
        $shipped = isset($zipEntries[$a['path']]);
        if (!$shipped && !isset($known[$a['hash']])) {
            $missing[] = $a['hash'];
        }
    }

    if (!empty($missing)) {
        recordLast(array('hadManifest' => $hadManifest, 'assetCount' => count($assets),
            'zipEntries' => array_keys($zipEntries), 'status' => 409, 'missing' => $missing));
        sendJson(409, array('missing' => $missing));
        return true;
    }

    recordLast(array('hadManifest' => $hadManifest, 'assetCount' => count($assets),
        'zipEntries' => array_keys($zipEntries), 'status' => 200, 'missing' => array()));
    if (!empty($allHashes)) {
        header('X-Resource-Cached: ' . implode(',', $allHashes));
    }
    header('Content-Type: application/pdf');
    echo "%PDF-1.4 mock-export\n";
    return true;
}

http_response_code(404);
echo 'not found';
return true;
