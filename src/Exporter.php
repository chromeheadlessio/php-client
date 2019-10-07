<?php

namespace chromeheadlessio;

class Exporter
{
    static function get($arr,$keys,$default=null)
    {
        if(! is_array($arr)) {
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

    function __construct($authentication = null)
    {
        $this->authentication = $authentication;
    }

    public static function create($authentication = null) 
    {
        $exporter = new Exporter($authentication);
        return $exporter;
    }

    function zipWholeFolder($path, $zipName) {
        // Get real path for our folder
        $rootPath = realpath($path);

        // Initialize archive object
        $zip = new \ZipArchive();
        $zip->open($zipName, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        // Create recursive directory iterator
        /** @var SplFileInfo[] $files */
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($rootPath),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $name => $file)
        {
            // Skip directories (they would be added automatically)
            if (!$file->isDir())
            {
                // Get real and relative path for current file
                $filePath = $file->getRealPath();
                $relativePath = substr($filePath, strlen($rootPath) + 1);

                // Add current file to archive
                $zip->addFile($filePath, $relativePath);
            }
        }

        // Zip archive will be created only after closing object
        $zip->close();
    }

    function toFilename($filename)
    {
        return md5($filename);
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
        mkdir($tempPath);

        $scheme = $this->getLocalProtocol();
        $httpHost = self::get($settings, 'httpHost', $this->getLocalHttpHost());
        $baseUrl = self::get($settings, 'url', 
            self::get($settings, 'baseUrl', $this->getLocalUrl()));
        // echo $baseUrl; echo "<br>";
        $parseUrl = parse_url($baseUrl);
        // echo "parseUrl ="; print_r($parseUrl); echo "<br>";
        if (! empty($parseUrl["host"])) {
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
        // echo "httpHost = $httpHost <br>";
        // echo "baseUrl = $baseUrl <br>";
        // exit;

        $resourcePatterns = [
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
            [
                "regex" => '~url\(["\']*([^"\'\)]+)["\']*\)~',
                "replace" => "url('{group1}')",
                "urlGroup" => "{group1}"
            ],
        ];
        $paramRPs = self::get($settings, 'resourcePatterns', []);
        $resourcePatterns = array_merge($resourcePatterns, $paramRPs);
        // print_r($resourcePatterns); 
        // exit();
        $fileList = [
            'saved' => [],
            'hashed' => []
        ];
        function replaceUrls($content, $rp, & $fileList, 
            $scheme, $httpHost, $baseUrl, $tempPath) {
            $numGroup = 0;
            $regex = '~\{group(\d+)\}~';
            preg_match_all($regex, $rp["replace"], $matches);
            // echo "group matches = "; print_r($matches); echo "<br>";
            foreach ($matches[1] as $match) {
                if ((int)$match > $numGroup)
                    $numGroup = (int)$match;
            }
            $urlOrder = 1;
            while (strpos($rp["urlGroup"], "{group$urlOrder}") === false) {
                $urlOrder += 1;
            }
            // echo "numGroup = $numGroup <br>";
            // echo "urlOrder = $urlOrder <br>";
            $loopState = [ 'continue' => true ];
            while ($loopState['continue']) {
                $loopState['continue'] = false;
                $content = preg_replace_callback(
                    $rp["regex"], 
                    function ($matches) use ($rp, & $fileList, 
                        $scheme, $httpHost, $baseUrl, 
                        $tempPath, $numGroup, $urlOrder, & $loopState) {
                        // echo "matches = "; print_r($matches); echo "<br>";
                        $match = $matches[0];
                        // echo "match = $match <br>";
                        $url = $matches[$urlOrder];
                        $urlOffset = strpos($match, $url);
                        $url = str_replace('\\', "", $url);
                        // echo "url = $url <br>";
                        
                        if (substr($url, 0, 2) === '//') {
                            $url = $scheme . ":" . $url;
                        } else if (substr($url, 0, 1) === '/') {
                            $url = $httpHost . $url;
                        } else if (substr($url, 0, 4) !== 'http') {
                            $url = $baseUrl . '/' . $url;
                        }
                        $filename = basename($url);
                        if (! isset($fileList['saved'][$filename])) {
                            // echo "repurl = $url <br>";
                            // echo "filename = $filename <br>";
                            // echo "url = $url <br>";
                            $fileContent = file_get_contents($url);
                            if ($fileContent) {
                                if ($matches[1] === 'link') {
                                    $urlRP = [
                                        "regex" => '~url\(["\']*([^"\'\)]+)["\']*\)~',
                                        "replace" => "url('{group1}')",
                                        "urlGroup" => "{group1}"
                                    ];
                                    $fileContent = replaceUrls($fileContent, $urlRP, 
                                        $fileList, $scheme, $httpHost, $baseUrl, $tempPath);
                                }
                                // echo "url=$url<br>";
                                // echo "filename=$filename<br>";
                                file_put_contents($tempPath . "/" . $filename, $fileContent);
                                if (! file_exists($tempPath . "/" . $filename)) {
                                    $hashedFilename = md5($filename);
                                    if ($matches[1] === 'link' || substr($filename, -4) === '.css') {
                                        $hashedFilename .= '.css';
                                    }
                                    if ($matches[1] === 'script' || substr($filename, -3) === '.js') {
                                        $hashedFilename .= '.js';
                                    }
                                    $fileList['hashed'][$filename] = $hashedFilename;
                                    file_put_contents($tempPath . "/" . $hashedFilename, $fileContent);
                                    // echo "filename = $hashedFilename <br>";
                                }
                                $fileList['saved'][$filename] = true;
                            }
                        }
                        $subMatch = substr($match, 0, $urlOffset);
                        $repSubMatch = replaceUrls($subMatch, $rp, 
                            $fileList, $scheme, $httpHost, $baseUrl, $tempPath);
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
                        for ($j=1; $j<=$numGroup; $j+=1) {
                            $hashedFilename = $filename;
                            if (isset($fileList['hashed'][$filename])) {
                                $hashedFilename = $fileList['hashed'][$filename];
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

        foreach ($resourcePatterns as $rp) {
            $content = replaceUrls($content, $rp, $fileList, 
                $scheme, $httpHost, $baseUrl, $tempPath);
        }

        // echo ($content); 
        // echo htmlentities($content); 
        
        // exit();

        $exportHtmlPath = $tempPath . "/" . "export.html";
        if(file_put_contents($exportHtmlPath, $content)) {
            $this->zipWholeFolder($tempPath, $tempZipPath);
            return [$exportHtmlPath, $tempZipPath, $tempZipName];
        }
        else {
            throw new \Exception("Could not save content to temporary folder");
            return false;
        }
    }

    function getTempFolder()
    {
        $useLocalTempFolder = isset($this->settings['useLocalTempFolder']) ?
            $this->settings['useLocalTempFolder'] : false;
        if($useLocalTempFolder)
        {
            // $path = dirname(__FILE__);
            $path = dirname($_SERVER['SCRIPT_FILENAME']);
            if(!is_dir(realpath($path)."/tmp"))
            {
                mkdir(realpath($path)."/tmp");
            }
            return realpath($path)."/tmp";
        }
        return sys_get_temp_dir();
    }

    function getLocalProtocol()
    {
        $https = self::get($_SERVER, 'HTTPS', '');
        $forwardedProto = self::get($_SERVER, 'HTTP_X_FORWARDED_PROTO', '');
        if ($https==1 ||
            strcasecmp($https,'on')===0  ||
            strcasecmp($forwardedProto,'https')===0)
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
        return $localHttpHost.$uri;
    }

    function cloudRequest($format = 'pdf', $options = []) 
    {
        ob_start();
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

        $file_name_with_full_path = $tempZipPath;
        $postfields = array(
            'exportFormat' => $format, //pdf, png or jpeg
            'waitUntil' => self::get($settings, 'pageWaiting', 'load'), //load, omcontentloaded, networkidle0, networkidle2
            'engine' => self::get($settings, 'engine', 'chromeheadless'), //default null or using chrome headless, "wkhtmltopdf"
            'fileToExport' => curl_file_create($file_name_with_full_path, 'application/zip', $tempZipName),
            // 'htmlContent' => '',
            // 'url' => '',
            // https://github.com/GoogleChrome/puppeteer/blob/master/docs/api.md#pagepdfoptions
            // https://github.com/GoogleChrome/puppeteer/blob/master/docs/api.md#pagescreenshotoptions
            'options' => json_encode($options)
        );
        $ch = curl_init();
        // $CLOUD_EXPORT_SERVICE = "http://localhost:1982/api/export";
        $CLOUD_EXPORT_SERVICE = "https://service.chromeheadless.io/api/export";
        $target_url = self::get($settings, 'serviceHost', $CLOUD_EXPORT_SERVICE);
        
        $curlOptions = array(
            CURLOPT_URL => $target_url,
            CURLOPT_HEADER => false,
            CURLOPT_POST => 1,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $postfields,
            CURLOPT_INFILESIZE => filesize($file_name_with_full_path),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYHOST=>0,
            CURLOPT_SSL_VERIFYPEER=>0,
        ); // cURL options
        curl_setopt_array($ch, $curlOptions);
        $response = curl_exec($ch);
        // print_r($response); exit;
        $cInfo = curl_getinfo($ch);
        // echo "curl info = "; print_r($info); echo "<br>";
        // echo "result = $result <br>";
        // exit();
        if(curl_errno($ch)) {
            $errmsg = curl_error($ch);
            throw new \Exception("Error when sending request: $errmsg");
        }
        else if ($cInfo['http_code'] != 200) {
            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $header = substr($response, 0, $headerSize);
            $body = substr($response, $headerSize);
            echo("Request failed: $body");
            exit();
        }
        curl_close($ch);
        ob_end_clean();
        return $response;
    }

}