<?php

namespace chromeheadlessio;

include "Exporter.php";

class Service
{
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

    public function save($filepath = 'export.pdf')
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

    public function sendToBrowser($filename, $openOnBrowser = 'attachment')
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
        header("Content-Disposition: $disposition; filename=\"$filename\"");
        header("Content-Transfer-Encoding: binary");
        header("Content-Length: " . strlen($this->exportContent));
        
        echo $this->exportContent;

        return $this;
    }
    
}