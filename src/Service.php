<?php

namespace chromeheadlessio;

include "Exporter.php";

class Service
{
    protected $authentication;
    protected $exportContent;
    protected $Exporter;

    function __construct($authentication)
    {
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
        if(file_put_contents($filePath, $this->exportContent)) {
            return $this;
        } else {
            throw new \Exception("Could not save file $filePath");
            return false;
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