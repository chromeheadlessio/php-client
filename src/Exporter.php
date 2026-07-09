<?php

namespace chromeheadlessio;

include_once __DIR__ . "/ResourceCache.php";

class Exporter
{
    public $settings;
    public $authentication;
    public $warnings = array();
    static $debug = false;
    static $resourceTimeout = 10;
    static $pageTimeout = 60;

    static function url_get_contents($url, $timeout = null)
    {
        if ($timeout === null) {
            $timeout = self::$resourceTimeout;
        }
        self::echo("url_get_contents url=$url<br>");
        try {
            if (function_exists('file_get_contents')) {
                $ctx = stream_context_create(array('http' => array('timeout' => $timeout)));
                $url_get_contents_data = file_get_contents($url, false, $ctx);
            } elseif (function_exists('fopen') && function_exists('stream_get_contents')) {
                $ctx = stream_context_create(array('http' => array('timeout' => $timeout)));
                $handle = fopen($url, 'r', false, $ctx);
                $url_get_contents_data = stream_get_contents($handle);
            } elseif (function_exists('curl_exec')) {
                $conn = curl_init($url);
                curl_setopt($conn, CURLOPT_SSL_VERIFYPEER, true);
                curl_setopt($conn, CURLOPT_FRESH_CONNECT,  true);
                curl_setopt($conn, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($conn, CURLOPT_TIMEOUT, $timeout);
                curl_setopt($conn, CURLOPT_CONNECTTIMEOUT, min($timeout, 10));
                $url_get_contents_data = (curl_exec($conn));
                curl_close($conn);
            } else {
                $url_get_contents_data = false;
            }
            return $url_get_contents_data;
        } catch (\Exception $e) {
            self::echo('Exception: ' . $e->getMessage());
        }
        return "";
    }

    static function echo($msg)
    {
        if (self::$debug) {
            echo $msg;
        }
    }

    static function get($arr, $keys, $default = null)
    {
        if (!is_array($arr)) {
            return $default;
        }
        if (is_array($keys) and count($keys) > 0) {
            foreach ($keys as $key) {
                $arr = self::get($arr, $key, $default);
            }
            return $arr;
        }
        if (is_string($keys) || is_int($keys)) {
            return isset($arr[$keys]) ? $arr[$keys] : $default;
        }
        return $default;
    }

    // Resolve a (possibly relative) resource URL to an absolute, normalized
    // URL. Handles protocol-relative (//), root-relative (/), and relative
    // references (including ./ and ../), strips backslashes and any #fragment.
    static function resolveUrl($url, $scheme, $httpHost, $baseUrl)
    {
        $url = str_replace('\\', "", (string) $url);
        $hashPos = strpos($url, '#');
        if ($hashPos !== false) {
            $url = substr($url, 0, $hashPos);
        }
        if ($url === '') {
            return '';
        }
        if (substr($url, 0, 2) === '//') {
            $url = $scheme . ":" . $url;
        } else if (substr($url, 0, 1) === '/') {
            $url = $httpHost . $url;
        } else if (substr($url, 0, 4) !== 'http') {
            $url = $baseUrl . '/' . $url;
        }
        return self::normalizeUrlPath($url);
    }

    // Collapse "." and ".." segments in the path of an absolute http(s) URL,
    // leaving scheme/host and any query string untouched.
    static function normalizeUrlPath($url)
    {
        if (!preg_match('~^([a-zA-Z][a-zA-Z0-9+.\-]*://[^/]*)(/[^?#]*)?(\?.*)?$~', $url, $m)) {
            return $url;
        }
        $origin = $m[1];
        $path = isset($m[2]) ? $m[2] : '';
        $query = isset($m[3]) ? $m[3] : '';
        if ($path === '') {
            return $origin . $query;
        }
        $out = [];
        foreach (explode('/', $path) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                array_pop($out);
                continue;
            }
            $out[] = $seg;
        }
        return $origin . '/' . implode('/', $out) . $query;
    }

    // CSS-level resource references. Applied both at the document level (for
    // inline <style> / style="") AND recursively inside every downloaded .css
    // file. Covers url(...) and the string form of @import; the url() form of
    // @import is already covered by the url() pattern.
    static function cssResourcePatterns()
    {
        return [
            [
                "regex" => '~url\(["\']*([^"\'\)]+)["\']*\)~',
                "replace" => "url('{group1}')",
                "urlGroup" => "{group1}"
            ],
            [
                "regex" => '~@import\s+["\']([^"\']+)["\']~',
                "replace" => "@import '{group1}'",
                "urlGroup" => "{group1}"
            ],
        ];
    }

    function __construct($authentication = null)
    {
        $this->authentication = $authentication;
    }

    function zipWholeFolder($path, $zipName)
    {
        // Get real path for our folder
        $realPath = realpath($path);

        // Initialize archive object
        $zip = new \ZipArchive();
        $zip->open($zipName, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        // Create recursive directory iterator
        /** @var SplFileInfo[] $files */
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($realPath),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $name => $file) {
            // Skip directories (they would be added automatically)
            if (!$file->isDir()) {
                // Get real and relative path for current file
                $filePath = $file->getRealPath();
                $relativePath = substr((string) $filePath, strlen((string) $realPath) + 1);

                // Add current file to archive
                $zip->addFile($filePath, $relativePath);
            }
        }

        // Zip archive will be created only after closing object
        $zip->close();
    }

    // Zip a folder like zipWholeFolder, but SKIP any file whose zip-relative
    // path is a key in $omit. Used by the resource cache to omit server-cached
    // assets from the upload (and, on a 409 miss, to add just the missing ones
    // back). The omitted files stay on disk so a miss/fallback can re-zip them.
    function zipFolderExcept($path, $zipName, $omit = array())
    {
        $realPath = realpath($path);
        $zip = new \ZipArchive();
        $zip->open($zipName, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($realPath),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($files as $name => $file) {
            if (!$file->isDir()) {
                $filePath = $file->getRealPath();
                $relativePath = substr((string) $filePath, strlen((string) $realPath) + 1);
                if (isset($omit[$relativePath])) {
                    continue;
                }
                $zip->addFile($filePath, $relativePath);
            }
        }
        $zip->close();
    }

    // Build the resource-cache manifest for a prepared temp folder: for every
    // stored resource file (everything except export.html), hash its ACTUAL
    // bytes and, if that hash is in the client's belief set, record a manifest
    // entry { path, hash } and mark the file for omission from the upload zip.
    // Fidelity-safe: a locally modified asset hashes to something NOT in the set
    // and is therefore uploaded normally — a hit can never swap in wrong bytes.
    private function buildResourceManifest($tempPath, $rc, &$manifest, &$manifestMap, &$omit)
    {
        $realPath = realpath($tempPath);
        if ($realPath === false) {
            return;
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($realPath),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($files as $name => $file) {
            if ($file->isDir()) {
                continue;
            }
            $filePath = $file->getRealPath();
            $rel = substr((string) $filePath, strlen((string) $realPath) + 1);
            if ($rel === '' || $rel === 'export.html') {
                continue;
            }
            $bytes = @file_get_contents($filePath);
            if ($bytes === false) {
                continue;
            }
            $h = hash('sha256', $bytes);
            if ($rc->beliefHas($h)) {
                $manifest[] = array('path' => $rel, 'hash' => $h);
                $manifestMap[$h] = $rel;
                $omit[$rel] = true;
            }
        }
    }

    function replaceUrls(
        $content,
        $rp,
        &$fileList,
        $scheme,
        $httpHost,
        $baseUrl,
        $tempPath
    ) {
        $numGroup = 0;
        $regex = '~\{group(\d+)\}~';
        preg_match_all($regex, $rp["replace"], $matches);
        // echo "group matches = "; print_r($matches); echo "<br>";
        foreach ($matches[1] as $match) {
            if ((int)$match > $numGroup)
                $numGroup = (int)$match;
        }
        $urlOrder = 1;
        while (strpos((string) $rp["urlGroup"], "{group$urlOrder}") === false) {
            $urlOrder += 1;
        }
        // echo "numGroup = $numGroup <br>";
        // echo "urlOrder = $urlOrder <br>";
        $loopState = ['continue' => true];
        while ($loopState['continue']) {
            $loopState['continue'] = false;
            $content = preg_replace_callback(
                $rp["regex"],
                function ($matches) use (
                    $rp,
                    &$fileList,
                    $scheme,
                    $httpHost,
                    $baseUrl,
                    $tempPath,
                    $numGroup,
                    $urlOrder,
                    &$loopState
                ) {
                    // echo "matches = "; print_r($matches); echo "<br>";
                    $match = $matches[0];
                    // echo "match = $match <br>";
                    $rawUrl = $matches[$urlOrder];
                    // Locate the raw URL inside the match BEFORE resolving it, so
                    // the prefix-recursion below peels off the correct substring.
                    $urlOffset = strpos((string) $match, (string) $rawUrl);
                    // Resolve to an absolute, normalized URL. This absolute URL is
                    // the collision-free key used to download/store the resource.
                    $url = self::resolveUrl($rawUrl, $scheme, $httpHost, $baseUrl);
                    // Basename for extension detection only — strip the query.
                    $pathForName = $url;
                    $qPos = strpos($pathForName, '?');
                    if ($qPos !== false) {
                        $pathForName = substr($pathForName, 0, $qPos);
                    }
                    $filename = (string) basename($pathForName);
                    // print_r($fileList); echo "<br>";
                    if (!isset($fileList['saved'][$url])) {
                        // echo "url = $url <br>";
                        // echo "filename = $filename <br>";
                        // echo "url2 = $url <br><br>";
                        // $fileContent = file_get_contents($url);
                        if (array_key_exists($url, $fileList['content'])) {
                            $fileContent = $fileList['content'][$url];
                        } else {
                            $fileContent = $this->url_get_contents($url);
                        }
                        if ($fileContent) {
                            self::echo("Has file content<br>");
                            $endStr = ".css";
                            if ($matches[1] === 'link' ||
                                substr($filename, -strlen($endStr)) === $endStr) {
                                $thisfileBaseUrl = dirname($url);
                                // Resolve every CSS-level reference (url() AND
                                // @import) inside this stylesheet, recursively.
                                foreach (self::cssResourcePatterns() as $cssRP) {
                                    $fileContent = $this->replaceUrls(
                                        $fileContent,
                                        $cssRP,
                                        $fileList,
                                        $scheme,
                                        $httpHost,
                                        $thisfileBaseUrl,
                                        $tempPath
                                    );
                                }
                            }

                            // echo "url=$url<br>";
                            // echo "filename=$filename<br>";
                            // file_put_contents($tempPath . "/" . $filename, $fileContent);
                            // if (! file_exists($tempPath . "/" . $filename)) {
                            // Hash the ABSOLUTE url (not the basename) so two
                            // resources that share a basename in different folders
                            // get distinct files instead of overwriting each other.
                            $hashedFilename = md5($url);
                            if ($matches[1] === 'link' || substr($filename, -4) === '.css') {
                                $hashedFilename .= '.css';
                            }
                            if ($matches[1] === 'script' || substr($filename, -3) === '.js') {
                                $hashedFilename .= '.js';
                            }
                            $fileList['hashed'][$url] = $hashedFilename;
                            if (! empty($fileContent)) {
                                $result = file_put_contents($tempPath . "/" . $hashedFilename, $fileContent);
                                if ($result === false) {
                                    self::echo("Could not save $filename to temporary folder<br><br>");
                                }
                            }
                            // echo "filename = $hashedFilename <br>";
                            $fileList['saved'][$url] = true;
                            $fileList['saved'][$hashedFilename] = true;
                            // } else 
                            //     $fileList['saved'][$filename] = true;
                        } else if ($fileContent === false) {
                            self::echo("Failed to get file content<br>");
                            $this->addWarning($url, 'download_failed');
                        } else {
                            self::echo("Empty file content<br>");
                            $this->addWarning($url, 'empty_content');
                        }
                    }
                    $subMatch = substr($match, 0, $urlOffset);
                    $repSubMatch = $this->replaceUrls(
                        $subMatch,
                        $rp,
                        $fileList,
                        $scheme,
                        $httpHost,
                        $baseUrl,
                        $tempPath
                    );
                    if ($repSubMatch !== $subMatch) {
                        // echo "subMatch = $subMatch <br>";
                        // echo "repSubMatch = $repSubMatch <br>";
                        $loopState['continue'] = true;
                        $replaceStr = $repSubMatch
                            . substr($match, $urlOffset, strlen($match));
                        // echo "recursive replaceStr = $replaceStr <br>";
                        return $replaceStr;
                    }
                    $replaceStr = $rp["replace"];
                    for ($j = 1; $j <= $numGroup; $j += 1) {
                        $hashedFilename = $filename;
                        if (isset($fileList['hashed'][$url])) {
                            $hashedFilename = $fileList['hashed'][$url];
                        }
                        $groupStr = $j === $urlOrder ? $hashedFilename : $matches[$j];
                        $replaceStr = str_replace("{group$j}", $groupStr, $replaceStr);
                    }
                    // echo "regex replaceStr = $replaceStr <br>";
                    return $replaceStr;
                },
                $content
            );
        }
        return $content;
    }

    private function prefetchResources(&$fileList, $content, $resourcePatterns, $scheme, $httpHost, $baseUrl, $concurrency)
    {
        // Collect all unique http(s) URLs from every pattern.
        $seen = array();
        $queue = array();
        foreach ($resourcePatterns as $rp) {
            if (!preg_match_all($rp["regex"], $content, $matches)) {
                continue;
            }
            $urlOrder = 1;
            while (strpos((string) $rp["urlGroup"], "{group$urlOrder}") === false) {
                $urlOrder += 1;
            }
            foreach ($matches[$urlOrder] as $rawUrl) {
                $url = self::resolveUrl($rawUrl, $scheme, $httpHost, $baseUrl);
                if ($url === '' || isset($seen[$url]) || isset($fileList['content'][$url])) {
                    continue;
                }
                if (substr($url, 0, 4) !== 'http') {
                    continue;
                }
                $seen[$url] = true;
                $queue[$url] = true;
            }
        }

        if (empty($queue)) {
            return;
        }

        // Download with curl_multi, in chunks of up to $concurrency handles,
        // so EVERY queued URL (including CSS-discovered ones) gets prefetched
        // — a truncated first window would silently push the remainder onto
        // the slow sequential path.
        $maxDepth = 5;
        for ($depth = 0; $depth < $maxDepth && !empty($queue); $depth++) {
            $batchUrls = array_keys($queue);
            $queue = array();

            foreach (array_chunk($batchUrls, max(1, (int) $concurrency)) as $chunkUrls) {
                $handles = array();
                $multi = curl_multi_init();

                $active = 0;
                foreach ($chunkUrls as $chunkUrl) {
                    $ch = curl_init($chunkUrl);
                    curl_setopt_array($ch, array(
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_TIMEOUT => self::$resourceTimeout,
                        CURLOPT_CONNECTTIMEOUT => min(self::$resourceTimeout, 10),
                        CURLOPT_FOLLOWLOCATION => true,
                        CURLOPT_MAXREDIRS => 5,
                        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                        CURLOPT_SSL_VERIFYPEER => true,
                    ));
                    curl_multi_add_handle($multi, $ch);
                    $handles[(int) $ch] = array('ch' => $ch, 'url' => $chunkUrl);
                }

                // Execute the multi handle.
                do {
                    $status = curl_multi_exec($multi, $active);
                } while ($status === CURLM_CALL_MULTI_PERFORM);

                while ($active && $status === CURLM_OK) {
                    if (curl_multi_select($multi) === -1) {
                        // select can transiently fail; avoid a busy spin.
                        usleep(1000);
                    }
                    do {
                        $status = curl_multi_exec($multi, $active);
                    } while ($status === CURLM_CALL_MULTI_PERFORM);
                }

                // Collect results.
                foreach ($handles as $id => $h) {
                    $ch = $h['ch'];
                    $url = $h['url'];
                    $response = curl_multi_getcontent($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    $errno = curl_errno($ch);

                    if ($errno === 0 && $httpCode >= 200 && $httpCode < 300 && $response !== false) {
                        $fileList['content'][$url] = $response;
                        // Recurse into CSS for new resource URLs.
                        $pathForName = $url;
                        $qPos = strpos($pathForName, '?');
                        if ($qPos !== false) {
                            $pathForName = substr($pathForName, 0, $qPos);
                        }
                        $basename = basename($pathForName);
                        if (substr($basename, -4) === '.css' && $response !== '') {
                            $cssBaseUrl = dirname($url);
                            foreach (self::cssResourcePatterns() as $cssRP) {
                                if (preg_match_all($cssRP["regex"], $response, $cssMatches)) {
                                    $cssUrlOrder = 1;
                                    while (strpos((string) $cssRP["urlGroup"], "{group$cssUrlOrder}") === false) {
                                        $cssUrlOrder += 1;
                                    }
                                    foreach ($cssMatches[$cssUrlOrder] as $rawCssUrl) {
                                        $cssUrl = self::resolveUrl($rawCssUrl, $scheme, $httpHost, $cssBaseUrl);
                                        if ($cssUrl !== '' && !isset($seen[$cssUrl]) && !isset($fileList['content'][$cssUrl]) && substr($cssUrl, 0, 4) === 'http') {
                                            $seen[$cssUrl] = true;
                                            $queue[$cssUrl] = true;
                                        }
                                    }
                                }
                            }
                        }
                    } else {
                        $fileList['content'][$url] = false;
                        $this->addWarning($url, 'download_failed');
                    }
                    curl_multi_remove_handle($multi, $ch);
                    curl_close($ch);
                }
                curl_multi_close($multi);
            }
        }
    }

    function saveTempContent($content, $rc = null)
    {
        $settings = $this->settings;
        $tmpFolder = $this->getTempFolder();
        // echo "tmpFolder=$tmpFolder"; exit;
        $tempDirName = uniqid();
        $tempZipName = $tempDirName . ".zip";
        $tempZipPath = $tmpFolder . "/" . $tempZipName;
        $tempPath = $tmpFolder . "/" . $tempDirName;
        if (!is_dir($tempPath)) {
            mkdir($tempPath);
        }

        $scheme = $this->getLocalProtocol();
        $httpHost = self::get($settings, 'httpHost', $this->getLocalHttpHost());
        self::echo("httpHost: $httpHost<br>");
        $baseUrl = self::get(
            $settings,
            'url',
            self::get($settings, 'baseUrl', $this->getLocalUrl())
        );
        // echo $baseUrl; echo "<br>";
        // exit;
        $parseUrl = parse_url($baseUrl);
        // echo "parseUrl ="; print_r($parseUrl); echo "<br>";
        if (!empty($parseUrl["host"])) {
            $scheme   = isset($parseUrl['scheme']) ?
                $parseUrl['scheme'] : $this->getLocalProtocol();
            // $scheme .= "://";
            $host = $parseUrl['host'];
            $port = isset($parseUrl['port']) ? ':' . $parseUrl['port'] : '';
            $user = isset($parseUrl['user']) ? $parseUrl['user'] : '';
            $pass = isset($parseUrl['pass']) ? ':' . $parseUrl['pass']  : '';
            $pass = ($user || $pass) ? "$pass@" : '';
            $path = isset($parseUrl['path']) ? $parseUrl['path'] : '';
            if (substr($path, -4) === '.php') {
                $path = explode("/", $path);
                array_pop($path);
                $path = implode("/", $path);
            }
            $httpHost = "$scheme://$user$pass$host$port";
            $baseUrl = "$httpHost$path";
        } else {
            $baseUrl = substr($baseUrl, 0, strrpos($baseUrl, "/"));
        }
        while (substr($baseUrl, -1) === "/") {
            $baseUrl = substr($baseUrl, 0, strlen($baseUrl) - 1);
        }
        self::echo("baseUrl: $baseUrl<br>");
        // exit;

        $resourcePatterns = array_merge(
            [
                [
                    "regex" => '~<(link)([^>]+)href=["\']([^"\'>]*)["\']~',
                    "replace" => "<{group1}{group2}href='{group3}'",
                    "urlGroup" => "{group3}"
                ],
                [
                    "regex" => '~<(script|img|iframe)([^>]+)src=["\']((?!data)[^"\'>]*)["\']~',
                    "replace" => "<{group1}{group2}src='{group3}'",
                    "urlGroup" => "{group3}"
                ],
            ],
            self::cssResourcePatterns()
        );
        $paramRPs = self::get($settings, 'resourcePatterns', []);
        $resourcePatterns = array_merge($resourcePatterns, $paramRPs);
        $fileList = ['saved' => [], 'hashed' => [], 'content' => []];
        $parallel = (bool) self::get($settings, 'parallelDownloads', false);
        $parallelConcurrency = (int) self::get($settings, 'parallelConcurrency', 8);
        if ($parallel && function_exists('curl_multi_init')) {
            $this->prefetchResources($fileList, $content, $resourcePatterns, $scheme, $httpHost, $baseUrl, $parallelConcurrency);
        }
        foreach ($resourcePatterns as $rp) {
            $content = $this->replaceUrls(
                $content,
                $rp,
                $fileList,
                $scheme,
                $httpHost,
                $baseUrl,
                $tempPath
            );
        }

        self::echo("tempPath: $tempPath<br><br>");
        if (!is_writable($tempPath)) {
            throw new \Exception("$tempPath is not writable!<br><br>");
        }

        self::echo("content: " . htmlentities($content) . "<br><br>"); 
        // exit();
        $exportHtmlPath = $tempPath . "/" . "export.html";
        if (empty($content)) {
            throw new \Exception("Empty export content");
            return false;
        }
        if (file_put_contents($exportHtmlPath, $content) !== false) {
            // Resource cache (opt-in): when a ResourceCache is passed, omit any
            // stored asset whose bytes are already believed server-cached and
            // record it in the manifest instead. Otherwise behave exactly as
            // before — zip the whole folder, empty manifest.
            $manifest = array();
            $manifestMap = array();
            $omit = array();
            if ($rc !== null) {
                $this->buildResourceManifest($tempPath, $rc, $manifest, $manifestMap, $omit);
            }
            if (!empty($omit)) {
                $this->zipFolderExcept($tempPath, $tempZipPath, $omit);
            } else {
                $this->zipWholeFolder($tempPath, $tempZipPath);
            }
            // Extra return values are ignored by existing `list($a,$b,$c)=` callers.
            return array($exportHtmlPath, $tempZipPath, $tempZipName, $manifest, $tempPath, $manifestMap);
        } else {
            throw new \Exception("Could not save content to temporary folder");
            return false;
        }
    }

    function getTempFolder()
    {
        $useLocalTempFolder = isset($this->settings['useLocalTempFolder']) ?
            $this->settings['useLocalTempFolder'] : false;
        if ($useLocalTempFolder) {
            // $path = dirname(__FILE__);
            $path = dirname($_SERVER['SCRIPT_FILENAME']);
            if (!is_dir(realpath($path) . "/tmp")) {
                mkdir(realpath($path) . "/tmp");
            }
            return realpath($path) . "/tmp";
        }
        return sys_get_temp_dir();
    }

    function getLocalProtocol()
    {
        $https = self::get($_SERVER, 'HTTPS', '');
        $forwardedProto = self::get($_SERVER, 'HTTP_X_FORWARDED_PROTO', '');
        if (
            $https == 1 ||
            strcasecmp($https, 'on') === 0  ||
            strcasecmp($forwardedProto, 'https') === 0
        )
            return 'https';
        return 'http';
    }

    function getLocalHttpHost()
    {
        $localProtocal = $this->getLocalProtocol();
        $localHost = self::get($_SERVER, 'HTTP_HOST', '127.0.0.1');
        $localHttpHost = "$localProtocal://$localHost";
        return $localHttpHost;
    }

    function getLocalUrl()
    {
        $localHttpHost = $this->getLocalHttpHost();
        $uri = $_SERVER["REQUEST_URI"];
        return $localHttpHost . $uri;
    }

    // Remove only THIS request's own temp artifacts: the uniqid extract dir
    // and its zip. Never touches the parent temp folder, so it is safe even
    // when tmp is the shared system temp dir (sys_get_temp_dir()).
    function cleanupTempArtifacts($tempZipPath)
    {
        $tempExtractDir = substr($tempZipPath, 0, -strlen('.zip'));
        if (is_dir($tempExtractDir)) {
            $di = new \RecursiveDirectoryIterator($tempExtractDir, \FilesystemIterator::SKIP_DOTS);
            $ri = new \RecursiveIteratorIterator($di, \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($ri as $file) {
                $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
            }
            @rmdir($tempExtractDir);
        }
        if (is_file($tempZipPath)) {
            @unlink($tempZipPath);
        }
    }

    function addWarning($url, $reason)
    {
        foreach ($this->warnings as $w) {
            if ($w['url'] === $url && $w['reason'] === $reason) {
                return;
            }
        }
        $this->warnings[] = array('url' => $url, 'reason' => $reason);
    }

    public function getWarnings()
    {
        return $this->warnings;
    }

    // One HTTP POST. Returns array(errno,error,code,body,headers). Headers are
    // captured via HEADERFUNCTION so Retry-After / X-Resource-Cached are readable
    // while the body stays clean (CURLOPT_HEADER off), matching prior behavior.
    private function httpSendOnce($curlOptions)
    {
        $ch = curl_init();
        curl_setopt_array($ch, $curlOptions);
        $responseHeaders = array();
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $header) use (&$responseHeaders) {
            $responseHeaders[] = $header;
            return strlen($header);
        });
        $body = curl_exec($ch);
        $info = curl_getinfo($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);
        return array(
            'errno'   => $errno,
            'error'   => $error,
            'code'    => isset($info['http_code']) ? (int) $info['http_code'] : 0,
            'body'    => $body,
            'headers' => $responseHeaders,
        );
    }

    // Exponential backoff (Retry-After honored) before the next transient retry.
    // $nextAttempt is 1-based, so 2^($nextAttempt-1) reproduces the prior schedule.
    private function backoff($nextAttempt, $retryDelayMs, $headers)
    {
        $delayMs = $retryDelayMs * pow(2, $nextAttempt - 1);
        foreach ($headers as $headerLine) {
            if (preg_match('/^retry-after:\s*(\d+)/i', $headerLine, $m)) {
                $delayMs = max($delayMs, (int) $m[1] * 1000);
                break;
            }
        }
        $delayMs = min($delayMs, 30000);
        usleep($delayMs * 1000);
    }

    // Parse a 409 body into its missing-hash list, or null if it is not a
    // well-formed { missing:[...] } response (which triggers a hard fallback).
    private function parseMissingHashes($body)
    {
        if (!is_string($body) || $body === '') {
            return null;
        }
        $data = json_decode($body, true);
        if (!is_array($data) || !isset($data['missing']) || !is_array($data['missing'])) {
            return null;
        }
        $out = array();
        foreach ($data['missing'] as $h) {
            if (is_string($h) && $h !== '') {
                $out[] = $h;
            }
        }
        return $out;
    }

    // Extract the server's X-Resource-Cached confirmation hashes (comma list).
    private function parseResourceCachedHeader($headers)
    {
        $out = array();
        foreach ($headers as $headerLine) {
            if (preg_match('/^x-resource-cached:\s*(.+?)\s*$/i', $headerLine, $m)) {
                foreach (explode(',', $m[1]) as $h) {
                    $h = trim($h);
                    if ($h !== '') {
                        $out[] = $h;
                    }
                }
            }
        }
        return $out;
    }

    // Send the export with transient-retry backoff (as before) PLUS, when the
    // cache is active: a single 409 { missing } re-send (adding just the missing
    // assets back into the zip) and merging the server's confirmation into SELF.
    // On the cache path, an unrecoverable outcome throws ResourceCacheFallback so
    // the caller can retry as a plain full-zip export.
    private function runSendLoop($curlOptions, $postfields, $ctx)
    {
        $cacheActive  = !empty($ctx['cacheActive']);
        $retries      = (int) $ctx['retries'];
        $retryDelayMs = (int) $ctx['retryDelayMs'];

        $baseOmit = array();
        foreach ($ctx['manifestMap'] as $hash => $rel) {
            $baseOmit[$rel] = true;
        }

        $curlOptions[CURLOPT_POSTFIELDS] = $postfields;
        $missRetried = false;
        $attempt = 0;

        while (true) {
            $r = $this->httpSendOnce($curlOptions);

            if ($r['errno'] !== 0) {
                if ($retries > 0 && $attempt < $retries && in_array($r['errno'], array(6, 7, 28, 35, 52, 56))) {
                    $attempt++;
                    $this->backoff($attempt, $retryDelayMs, $r['headers']);
                    continue;
                }
                if ($cacheActive) {
                    throw new ResourceCacheFallback("send error: " . $r['error']);
                }
                throw new \Exception("Error when sending request: " . $r['error']);
            }

            $code = $r['code'];

            if ($code === 200) {
                if ($cacheActive && $ctx['rc'] !== null) {
                    $confirmed = $this->parseResourceCachedHeader($r['headers']);
                    // Only ever grow SELF with hashes we actually sent and the
                    // server confirmed — never trust arbitrary server data.
                    $confirmed = array_values(array_intersect($confirmed, array_keys($ctx['manifestMap'])));
                    if (!empty($confirmed)) {
                        $ctx['rc']->noteConfirmed($confirmed);
                    }
                }
                return $r['body'];
            }

            if ($code === 409 && $cacheActive && !$missRetried) {
                $missing = $this->parseMissingHashes($r['body']);
                if ($missing === null) {
                    throw new ResourceCacheFallback("malformed 409 response");
                }
                $reOmit = $baseOmit;
                foreach ($missing as $mh) {
                    if (isset($ctx['manifestMap'][$mh])) {
                        unset($reOmit[$ctx['manifestMap'][$mh]]);
                    }
                }
                $this->zipFolderExcept($ctx['tempPath'], $ctx['tempZipPath'], $reOmit);
                $postfields['fileToExport'] = curl_file_create($ctx['tempZipPath'], 'application/zip', $ctx['tempZipName']);
                $curlOptions[CURLOPT_POSTFIELDS] = $postfields;
                $curlOptions[CURLOPT_INFILESIZE] = filesize($ctx['tempZipPath']);
                $missRetried = true;
                continue; // resend does not consume a transient-retry attempt
            }

            if (in_array($code, array(502, 503, 504)) && $retries > 0 && $attempt < $retries) {
                $attempt++;
                $this->backoff($attempt, $retryDelayMs, $r['headers']);
                continue;
            }

            if ($cacheActive) {
                throw new ResourceCacheFallback("http " . $code);
            }
            throw new \Exception("Export request failed with HTTP " . $code . ": " . $r['body']);
        }
    }

    function cloudRequest($format = 'pdf', $options = [])
    {
        self::$debug = self::get($this->settings, 'debug');
        if (!self::$debug) {
            // echo "ob_start called<br><br>";
            ob_start();
        }
        $secretToken = self::get($this->authentication, 'secretToken', '');
        $headers = array(
            "Content-Type:multipart/form-data",
            "Authorization: Bearer $secretToken",
        );

        $settings = $this->settings;
        self::$resourceTimeout = (int) self::get($settings, 'resourceTimeout', 10);
        self::$pageTimeout = (int) self::get($settings, 'pageTimeout', 60);
        // Clamp: a negative value would skip the attempt loop entirely and
        // reach `throw $lastException` with null — a PHP fatal, not an Exception.
        $retries = max(0, (int) self::get($settings, 'retries', 0));
        $retryDelayMs = (int) self::get($settings, 'retryDelayMs', 1000);
        $this->warnings = array();
        $html = self::get($settings, 'html', '');
        if (empty($html)) {
            $url = self::get($settings, 'url', null);
            $html = self::url_get_contents($url, self::$pageTimeout);
        }

        // Resolve the target service + TLS policy up front: the resource-cache
        // capability probe and delta-sync need them BEFORE we assemble the zip.
        $CLOUD_EXPORT_SERVICE = "https://service.chromeheadless.io";
        $serviceHost = rtrim(self::get($settings, 'serviceHost', $CLOUD_EXPORT_SERVICE), "/");
        $target_url = self::get($settings, 'serviceUrl', $serviceHost . "/api/export");
        // Verify the service's TLS cert by default — the request carries the
        // Bearer token + report content. Self-signed / private export servers
        // can opt out with settings.verifySsl = false.
        $verifySsl = self::get($settings, 'verifySsl', true);

        // Resource cache (opt-in, additive). Detect server support once (cached);
        // if supported, opportunistically delta-sync the shared hash set (gated,
        // failure-safe). Any problem here just leaves caching inactive.
        $rc = null;
        $cacheActive = false;
        $rcConfig = self::get($settings, 'resourceCache', null);
        if (is_array($rcConfig) && self::get($rcConfig, 'enabled', false) === true) {
            $rc = new ResourceCache($rcConfig, $target_url, $secretToken);
            try {
                $cacheActive = $rc->capabilitySupported($serviceHost, $verifySsl);
                if ($cacheActive) {
                    $rc->maybeSync($serviceHost, $verifySsl);
                }
            } catch (\Exception $e) {
                $cacheActive = false;
            }
        }

        list($exportHtmlPath, $tempZipPath, $tempZipName, $manifest, $tempPath, $manifestMap)
            = $this->saveTempContent($html, $cacheActive ? $rc : null);

        $margin = isset($options['margin']) ? $options['margin'] : null;
        if (is_string($margin)) {
            $options['margin'] = [
                'top' => $margin,
                'bottom' => $margin,
                'right' => $margin,
                'left' => $margin,
            ];
        }

        // logTiming / returnTiming are diagnostic flags the service reads from the
        // options payload. They are conceptually request settings, so allow them in
        // settings too (in addition to the pdf options); an explicit pdf option wins.
        foreach (['logTiming', 'returnTiming'] as $diagFlag) {
            if (!isset($options[$diagFlag]) && isset($settings[$diagFlag])) {
                $options[$diagFlag] = $settings[$diagFlag];
            }
        }

        $file_name_with_full_path = $tempZipPath;
        $postfields = array(
            'exportFormat' => $format, //pdf, png or jpeg
            'waitUntil' => self::get($settings, 'pageWaiting', 'load'), //load, omcontentloaded, networkidle0, networkidle2
            'engine' => self::get($settings, 'engine', 'chromeheadless'), //default null or using chrome headless, "wkhtmltopdf"
            'fileToExport' => curl_file_create($file_name_with_full_path, 'application/zip', $tempZipName),
            'options' => ! empty($options) ? json_encode($options) : "{}"
        );
        // Attach the resource manifest only when caching is active and something
        // is actually omitted; otherwise this stays a plain full-zip export and
        // the wire is byte-identical to a non-cache client.
        if ($cacheActive && !empty($manifest)) {
            $postfields['resourceManifest'] = json_encode(array('algo' => 'sha256', 'assets' => $manifest));
        }

        $curlOptions = array(
            CURLOPT_URL => $target_url,
            CURLOPT_HEADER => false, //don't include header in response
            CURLOPT_POST => 1,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $postfields,
            CURLOPT_INFILESIZE => filesize($file_name_with_full_path),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            // Bound the request so a stalled service can't hang the host script
            // indefinitely. 140s > service JOB_DEADLINE_MS (120s) + margin, and
            // matches the nginx proxy_read_timeout. Override via settings.timeout.
            CURLOPT_TIMEOUT => self::get($settings, 'timeout', 140),
            CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
            CURLOPT_SSL_VERIFYPEER => $verifySsl,
            // CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4
        ); // cURL options

        $sendCtx = array(
            'rc'           => $rc,
            'manifestMap'  => $manifestMap,
            'tempPath'     => $tempPath,
            'tempZipPath'  => $tempZipPath,
            'tempZipName'  => $tempZipName,
            'retries'      => $retries,
            'retryDelayMs' => $retryDelayMs,
        );

        try {
            try {
                $ctx = $sendCtx;
                $ctx['cacheActive'] = $cacheActive;
                return $this->runSendLoop($curlOptions, $postfields, $ctx);
            } catch (ResourceCacheFallback $fb) {
                // A resource-cache problem must NEVER break an export: rebuild a
                // plain full zip (every file is still on disk) and resend once
                // with caching disabled and no manifest.
                $this->addWarning($target_url, 'resource_cache_fallback');
                $this->zipWholeFolder($tempPath, $tempZipPath);
                unset($postfields['resourceManifest']);
                $postfields['fileToExport'] = curl_file_create($tempZipPath, 'application/zip', $tempZipName);
                $ctx = $sendCtx;
                $ctx['cacheActive'] = false;
                $ctx['rc'] = null;
                $ctx['manifestMap'] = array();
                return $this->runSendLoop($curlOptions, $postfields, $ctx);
            }
        } finally {
            if (!self::$debug) {
                ob_end_clean();
            }
            // Always clean up this request's own temp artifacts, on success or
            // failure, so the system temp dir does not accumulate over time.
            $this->cleanupTempArtifacts($tempZipPath);
            // Back-compat: legacy whole-folder sweep for local-temp mode only.
            $useLocalTempFolder = self::get($settings, 'useLocalTempFolder', false);
            $autoDeleteLocalTempFile = self::get($settings, 'autoDeleteLocalTempFile', false);
            if ($useLocalTempFolder && $autoDeleteLocalTempFile) {
                $di = new \RecursiveDirectoryIterator(dirname($tempZipPath), \FilesystemIterator::SKIP_DOTS);
                $ri = new \RecursiveIteratorIterator($di, \RecursiveIteratorIterator::CHILD_FIRST);
                foreach ($ri as $file) {
                    $file->isDir() ?
                        rmdir($file->getPathname()) : unlink($file->getPathname());
                }
            }
        }
    }
}
