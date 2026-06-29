<?php

namespace chromeheadlessio;

class Exporter
{
    public $settings;
    public $authentication;
    static $debug = false;

    static function url_get_contents($url)
    {
        self::echo("url_get_contents url=$url<br>");
        try {
            if (function_exists('file_get_contents')) {
                // echo "Call file_get_contents<br>";
                $url_get_contents_data = file_get_contents($url);
            } elseif (function_exists('fopen') && function_exists('stream_get_contents')) {
                // echo "Call stream_get_contents<br>";
                $handle = fopen($url, "r");
                $url_get_contents_data = stream_get_contents($handle);
            } elseif (function_exists('curl_exec')) {
                $conn = curl_init($url);
                // echo "Call curl<br>";
                curl_setopt($conn, CURLOPT_SSL_VERIFYPEER, true);
                curl_setopt($conn, CURLOPT_FRESH_CONNECT,  true);
                curl_setopt($conn, CURLOPT_RETURNTRANSFER, 1);
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
                        $fileContent = $this->url_get_contents($url);
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
                        } else {
                            self::echo("Empty file content<br>");
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

    function saveTempContent($content)
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
        $fileList = ['saved' => [], 'hashed' => []];
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
            $this->zipWholeFolder($tempPath, $tempZipPath);
            return [$exportHtmlPath, $tempZipPath, $tempZipName];
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
        $html = self::get($settings, 'html', '');
        if (empty($html)) {
            $url = self::get($settings, 'url', null);
            $html = file_get_contents($url);
        }

        list($exportHtmlPath, $tempZipPath, $tempZipName) = $this->saveTempContent($html);
        $tempFolder = dirname($tempZipPath);
        // echo "tempZipPath=$tempZipPath<br>";
        // echo "tempFolder=$tempFolder<br>";
        // exit;

        $margin = isset($options['margin']) ? $options['margin'] : null;
        if (is_string($margin)) {
            $options['margin'] = [
                'top' => $margin,
                'bottom' => $margin,
                'right' => $margin,
                'left' => $margin,
            ];
        }

        $file_name_with_full_path = $tempZipPath;
        $postfields = array(
            'exportFormat' => $format, //pdf, png or jpeg
            'waitUntil' => self::get($settings, 'pageWaiting', 'load'), //load, omcontentloaded, networkidle0, networkidle2
            'engine' => self::get($settings, 'engine', 'chromeheadless'), //default null or using chrome headless, "wkhtmltopdf"
            'fileToExport' => curl_file_create($file_name_with_full_path, 'application/zip', $tempZipName),
            'options' => ! empty($options) ? json_encode($options) : "{}"
        );
        $ch = curl_init();
        // $CLOUD_EXPORT_SERVICE = "http://localhost:1982";
        // $CLOUD_EXPORT_SERVICE = "http://localhost:8000";
        $CLOUD_EXPORT_SERVICE = "https://service.chromeheadless.io";
        $serviceHost = self::get($settings, 'serviceHost', $CLOUD_EXPORT_SERVICE);
        $serviceHost = rtrim($serviceHost, "/");
        $target_url = self::get($settings, 'serviceUrl', $serviceHost . "/api/export");

        // Verify the service's TLS cert by default — the request carries the
        // Bearer token + report content. Self-signed / private export servers
        // can opt out with settings.verifySsl = false.
        $verifySsl = self::get($settings, 'verifySsl', true);

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
        curl_setopt_array($ch, $curlOptions);
        try {
            $response = curl_exec($ch);
            $cInfo = curl_getinfo($ch);

            if (curl_errno($ch)) {
                throw new \Exception("Error when sending request: " . curl_error($ch));
            } else if ($cInfo['http_code'] != 200) {
                // Response carries no headers (CURLOPT_HEADER is false), so the
                // whole response is the body. Throw instead of exit() so the
                // caller can handle/retry (e.g. back off on 503 + Retry-After)
                // rather than have its entire PHP process terminated.
                throw new \Exception(
                    "Export request failed with HTTP " . $cInfo['http_code'] . ": " . $response
                );
            }
            return $response;
        } finally {
            curl_close($ch);
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
