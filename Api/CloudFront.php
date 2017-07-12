<?php

namespace CachePurge\Api;

/**
 * Class CloudFront
 * @package CachePurge\Api
 */
class CloudFront extends Api
{
    protected $access_key;
    protected $secret_key;
    protected $distribution_id;
    protected $timeout = 10;

    public function setAccessKey($value)
    {
        $this->access_key = $value;
    }

    public function setSecretKey($value)
    {
        $this->secret_key = $value;
    }

    public function setDistributionId($value)
    {
        $this->distribution_id = $value;
    }

    public function callApi($xml = '')
    {
        if (!$this->access_key || !$this->secret_key || !$this->distribution_id) {
            $this->emit(Api::ERROR, "CloudFront API is not configured properly");
            return false;
        }

        $len = strlen($xml);
        $date = gmdate('D, d M Y G:i:s T');
        $sig = base64_encode(hash_hmac('sha1', $date, $this->secret_key, true));
        $msg = "POST /2010-11-01/distribution/{$this->distribution_id}/invalidation HTTP/1.0\r\n";
        $msg .= "Host: cloudfront.amazonaws.com\r\n";
        $msg .= "Date: {$date}\r\n";
        $msg .= "Content-Type: text/xml; charset=UTF-8\r\n";
        $msg .= "Authorization: AWS {$this->access_key}:{$sig}\r\n";
        $msg .= "Content-Length: {$len}\r\n\r\n";
        $msg .= $xml;
        $fp = @fsockopen('ssl://cloudfront.amazonaws.com', 443, $errno, $errstr, $this->timeout);
        if ($fp) {
            fwrite($fp, $msg);
            $resp = '';
            while (!feof($fp)) {
                $resp .= fread($fp, 65536);
            }
            fclose($fp);
            if (!preg_match('#^HTTP/1.1 20[01]#', $resp) || !preg_match('#<Id>(.*?)</Id>#m', $resp, $matches)) {
                $this->emit(Api::ERROR, "Failed to create invalidation");
                $this->emit(Api::ERROR, "Request: $msg");
                $this->emit(Api::ERROR, "Response: $resp");
                return false;
            } else {
                $this->emit(Api::DEBUG, "Request: $msg");
                $this->emit(Api::DEBUG, "Response: $resp");
            }
            return $resp;
        }
        $this->emit(Api::ERROR, "Connection to CloudFront API failed: {$errno} {$errstr}");
        return false;
    }

    public function invalidate(array $urls)
    {
        $epoch = date('U');
        $paths = join("", array_map(function ($url) {
            return "<Path>$url</Path>";
        }, $urls));

        return $this->callApi("<InvalidationBatch>{$paths}<CallerReference>{$this->distribution_id}{$epoch}</CallerReference></InvalidationBatch>");
    }
}