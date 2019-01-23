<?php
/**
 * This file contains class to handle generated file.
 *
 * @author KoolPHP Inc (support@koolphp.net)
 * @link https://www.koolphp.net
 * @copyright KoolPHP Inc
 * @license https://www.koolreport.com/license#regular-license
 * @license https://www.koolreport.com/license#extended-license
 */

namespace chromeheadlessio;

class File
{
    protected $path;
    public function __construct($path)
    {
        $this->path = $path;
    }
    
    protected function mime_type($filename)
    {
        $dotpos =strrpos($filename,".");
        $ext = strtolower(substr($filename,$dotpos+1));
        $map =array(
            "pdf"=>"application/pdf",
            "png"=>"image/png",
            "jpg"=>"image/jpeg",
            "bmp"=>"image/bmp",
            "tiff"=>"image/tiff",
            "gif"=>"image/gif",
            "ppm"=>"image/x-portable-pixmap",
        );
        return $map[$ext] ? $map[$ext] : '';
    }
    
    public function download($filename,$openOnBrowser=false)
    {

        $disposition = "attachment";
        if(gettype($openOnBrowser)=="string")
        {
            $disposition = $openOnBrowser;
        }
        else if($openOnBrowser)
        {
            $disposition = "inline";
        }
        
        $source = realpath($this->path);
        
        header("Pragma: public");
        header("Expires: 0");
        header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
        header("Cache-Control: public");
        header("Content-Description: File Transfer");
        header("Content-Type: ".$this->mime_type($filename));
        header("Content-Disposition: $disposition; filename=\"$filename\"");
        header("Content-Transfer-Encoding: binary");
        header("Content-Length: " . filesize($source));
        
        $file = @fopen($source,"rb");
        if ($file) {
          while(!feof($file)) {
            print(fread($file, 1024*8));
            flush();
            if (connection_status()!=0) {
              @fclose($file);
              die();
            }
          }
          @fclose($file);
        }
        return $this;
    }

    public function toBase64() {
        $source = realpath($this->path);
        if (is_file($source)) {
            return base64_encode(file_get_contents($source));
        }
    }

    public function save($filename)
    {
        if(copy($this->path,$filename))
        {
            return $this;
        }
        else
        {
            throw new \Exception("Could not save file $filename");
            return false;
        }
    }
}
