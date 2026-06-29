<?php

namespace chromeheadlessio;

include_once __DIR__ . "/Exporter.php";

class Service
{
    protected $authentication;
    protected $exportContent;
    protected $Exporter;

    function __construct($authentication)
    {
        // error_reporting(E_ERROR | E_PARSE);
        
        if (is_string($authentication)) {
            $secretToken = $authentication;
            $this->authentication = [
                'secretToken' => $secretToken
            ];
        } else {
            $this->authentication = $authentication;
        }

        $this->Exporter = new Exporter($this->authentication);
    }

    public function export($settings)
    {
        $this->Exporter->settings = $settings;
        return $this;
    }

    public function pdf($options = [])
    {
        $this->exportContent = $this->Exporter->cloudRequest('pdf', $options);
        return $this;
    }

    public function jpg($options = [])
    {
        $this->exportContent = $this->Exporter->cloudRequest('jpeg', $options);
        return $this;
    }

    public function png($options = [])
    {
        $this->exportContent = $this->Exporter->cloudRequest('png', $options);
        return $this;
    }

    public function save($filePath = 'export.pdf')
    {
        // file_put_contents returns 0 (falsy) for empty content but still
        // succeeds; only false means a real write failure.
        if(file_put_contents($filePath, $this->exportContent) !== false) {
            return $this;
        } else {
            throw new \Exception("Could not save file $filePath");
        }
    }

    public function toString()
    {
        return $this->exportContent;
    }

    public function toBase64()
    {
        return base64_encode($this->exportContent);
    }

    protected function mimeType($filename)
    {
        $dotpos =strrpos($filename, ".");
        $ext = strtolower(substr($filename, $dotpos+1));
        $map =array(
            "pdf"=>"application/pdf",
            "png"=>"image/png",
            "jpg"=>"image/jpeg",
            "bmp"=>"image/bmp",
            "tiff"=>"image/tiff",
            "gif"=>"image/gif",
            "ppm"=>"image/x-portable-pixmap",
        );
        return isset($map[$ext]) ? $map[$ext] : $ext;
    }

    public function sendToBrowser($filename, $openOnBrowser = 'attachment')
    {
        $disposition = "attachment";
        if(gettype($openOnBrowser)=="string") {
            $disposition = $openOnBrowser;
        } else if($openOnBrowser) {
            $disposition = "inline";
        }
        header("Pragma: public");
        header("Expires: 0");
        header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
        header("Cache-Control: public");
        header("Content-Description: File Transfer");
        header("Content-Type: ".$this->mimeType($filename));
        header("Content-Disposition: $disposition; filename=\"$filename\"");
        header("Content-Transfer-Encoding: binary");
        header("Content-Length: " . strlen($this->exportContent));
        
        echo $this->exportContent;

        return $this;
    }
    
}