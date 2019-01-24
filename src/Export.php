<?php

namespace chromeheadlessio;



class Export
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

    function __construct($params = null)
    {
        $this->params = $params;
    }

    public static function create($params = null) 
    {
        $export = new Export($params);
        return $export;
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

    function saveTempContent($content)
    {
        $params = $this->params;
        $tmpFolder = $this->getTempFolder();
        $tempDirName = uniqid();
        $tempZipName = $tempDirName . ".zip";
        $tempZipPath = $tmpFolder . "/" . $tempZipName;
        $tempPath = $tmpFolder . "/" . $tempDirName;
        mkdir($tempPath);

        $httpHost = self::get($params, 'httpHost', $this->getLocalHttpHost());
        $url = self::get($params, 'url', self::get($params, 'baseUrl', $this->getLocalUrl()));
        // echo $url; echo "<br>";
        $parseUrl = parse_url($url);
        if (! empty($parseUrl["host"])) {
            $scheme   = isset($parseUrl['scheme']) ? 
                $parseUrl['scheme'] : $this->getLocalProtocol();
            $scheme .= "://";
            $host = $parseUrl['host']; 
            $port = isset($parseUrl['port']) ? ':' . $parseUrl['port'] : ''; 
            $user = isset($parseUrl['user']) ? $parseUrl['user'] : ''; 
            $pass = isset($parseUrl['pass']) ? ':' . $parseUrl['pass']  : ''; 
            $pass = ($user || $pass) ? "$pass@" : ''; 
            $path = isset($parseUrl['path']) ? $parseUrl['path'] : ''; 
            $httpHost = "$scheme$user$pass$host$port";
            $url = "$httpHost$path";
        } else {
            $url = substr($url, 0, strrpos($url, "/"));
        }
        // echo $url; exit();
        $content = preg_replace_callback('~<(link)([^>]+)href=["\']([^>]*)["\']~', 
            function($matches) use ($httpHost, $url, $tempPath) {
                $href = $matches[3];
                $href = str_replace("\/", "/", $href);
                if (substr($href, 0, 1) === "/") {
                    $href = $httpHost . $href;
                }
                if (substr($href, 0, 4) !== "http") {
                    $href = $url . "/" . $href;
                }
                // echo "href = $href <br>";
                $fileContent = file_get_contents($href);
                $fileName = basename($href);
                file_put_contents($tempPath . "/" . $fileName, $fileContent);
                return "<{$matches[1]}{$matches[2]}href='$fileName'";
            }
        , $content);

        $content = preg_replace_callback('~<(script|img|iframe)([^>]+)src=["\']([^>]*)["\']~', 
            function($matches) use ($httpHost, $url, $tempPath) {
                $href = $matches[3];
                // echo 'first character of href = ' . substr($href, 0, 1) . '<br>';
                $href = str_replace("\/", "/", $href);
                if (substr($href, 0, 1) === "/") {
                    $href = $httpHost . $href;
                }
                if (substr($href, 0, 4) !== "http") {
                    $href = $url . "/" . $href;
                }
                // echo "href = $href <br>";
                $fileContent = file_get_contents($href);
                $fileName = basename($href);
                file_put_contents($tempPath . "/" . $fileName, $fileContent);
                return "<{$matches[1]}{$matches[2]}src='$fileName'";
            }
        , $content);

        function replaceHref($content, $httpHost, $url, $tempPath)  {
            $content = preg_replace_callback(
                '~((KoolReport.load.resources|KoolReport.widget.init)\([^\(\)]*)"([^,\(\)]+)"~', 
            
                function($matches) use ($httpHost, $url, $tempPath) {
                    // print_r($matches); echo "<br><br>";
                    $href = $matches[3];
                    $matches1 = $matches[1];
                    $matches1 = replaceHref($matches1, $httpHost, $url, $tempPath);
                    if ($href === 'js' || $href === 'css') {
                        return $matches1 . "\"$href\"";
                    }
                    $href = str_replace("\/", "/", $href);
                    if (substr($href, 0, 1) === "/") {
                        $href = $httpHost . $href;
                    }
                    if (substr($href, 0, 4) !== "http") {
                        $href = $url . "/" . $href;
                    }
                    // echo "href = $href <br>";
                    $fileContent = file_get_contents($href);
                    $fileName = basename($href);
                    file_put_contents($tempPath . "/" . $fileName, $fileContent);
                    return $matches1 . "\"$fileName\"";
                },
            
                $content
            );
            return $content;
        }
        $content = replaceHref($content, $httpHost, $url, $tempPath);

        // echo htmlentities($content); 

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
        // if($useLocalTempFolder)
        // {
        //     if(!is_dir(realpath(dirname(__FILE__))."/tmp"))
        //     {
        //         mkdir(realpath(dirname(__FILE__))."/tmp");
        //     }
        //     return realpath(dirname(__FILE__))."/tmp";
        // }
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

    
    function cloudRequest($params = []) {
        // print_r($params);
        // exit();
        $authentication = self::get($params, 'authentication', []);
        $secretToken = self::get($authentication, 'secretToken', '');
        $options = self::get($params, 'options', []);
        $html = self::get($params, 'html', '');
        if (empty($html)) {
            $url = self::get($params, 'url', null);
            $html = file_get_contents($url);
        }

        list($exportHtmlPath, $tempZipPath, $tempZipName) = $this->saveTempContent($html);

        $file_name_with_full_path = $tempZipPath;
        $postfields = array(
            'exportFormat' => self::get($params, 'format', 'pdf'), //pdf, png or jpeg
            'waitUntil' => self::get($params, 'pageWaiting', 'load'), //load, omcontentloaded, networkidle0, networkidle2
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
        $target_url = self::get($params, 'serviceHost', $CLOUD_EXPORT_SERVICE);
        $headers = array(
            "Content-Type:multipart/form-data",
            "Authorization: Bearer $secretToken",
        );
        $curlOptions = array(
            CURLOPT_URL => $target_url,
            CURLOPT_HEADER => true,
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
        return [$response, $cInfo];
    }

    function export($format, $options)
    {
        $params = $this->params;
        ob_start();
        $params["format"] = $format;
        $params["options"] = $options;
        list($this->exportContent, $this->cInfo) = $this->cloudRequest($params);
        ob_end_clean();
        return $this;
    }

    function pdf($options = [])
    {
        $this->export('pdf', $options);
        return $this;
    }

    function jpg($options = [])
    {
        $this->export('jpeg', $options);
        return $this;
    }

    function png($options = [])
    {
        $this->export('png', $options);
        return $this;
    }

    function toString()
    {
        return $this->exportContent;
    }

    function download($downloadName, $openOnBrowser=false)
    {
        $disposition = "attachment";
        if(gettype($openOnBrowser)=="string") {
            $disposition = $openOnBrowser;
        } else if($openOnBrowser) {
            $disposition = "inline";
        }
        $type = "pdf";
        header("Pragma: public");
        header("Expires: 0");
        header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
        header("Cache-Control: public");
        header("Content-Description: File Transfer");
        header("Content-Type: ".$type);
        header("Content-Disposition: $disposition; filename=\"$downloadName\"");
        header("Content-Transfer-Encoding: binary");
        header("Content-Length: " . strlen($this->exportContent));
        
        echo $this->exportContent;

        return $this;
    }

    function save($filePath)
    {
        if(file_put_contents($filePath, $this->exportContent)) {
            return $this;
        } else {
            throw new \Exception("Could not save file $filePath");
            return false;
        }
    }

}