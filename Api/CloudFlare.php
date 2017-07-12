<?php

namespace CachePurge\Api;

/**
 * Class CloudFlare
 * @package CachePurge\Api
 */
class CloudFlare extends Api
{
    protected $email;
    protected $key;
    protected $zone_id;
    protected $timeout = 10;

    public function setEmail($value)
    {
        $this->email = trim($value);
    }

    public function setKey($value)
    {
        $this->key = trim($value);
    }

    public function setZoneId($value)
    {
        $this->zone_id = trim($value);
    }

    public function callApi($body)
    {
        if (!$this->email || !$this->key || !$this->zone_id) {
            $this->emit(Api::ERROR, "CloudFlare API is not configured properly");
            return false;
        }

        $msg = "DELETE /client/v4/zones/{$this->zone_id}/purge_cache HTTP/1.0\r\n";
        $msg .= "Host: api.cloudflare.com\r\n";
        $msg .= "Content-Length: " . strlen($body) . "\r\n";
        $msg .= "X-Auth-Email:" . $this->email . "\r\n";
        $msg .= "X-Auth-Key:" . $this->key . "\r\n";
        $msg .= "Content-Type: application/json\r\n";
        $msg .= "\r\n";
        $msg .= $body;

        $fp = @fsockopen('ssl://api.cloudflare.com', 443, $errno, $errstr, $this->timeout);

        if ($fp) {
            fwrite($fp, $msg);
            $resp = '';
            while (!feof($fp)) {
                $resp .= fread($fp, 131072);
            }
            if (!preg_match('#^HTTP/1.1 20[01]#', $resp)) {
                $this->emit(Api::ERROR, "Failed to invalidate");
                $this->emit(Api::ERROR, "Request: $msg");
                $this->emit(Api::ERROR, "Response: $resp");
                return false;
            } else {
                $this->emit(Api::DEBUG, "Request: $msg");
                $this->emit(Api::DEBUG, "Response: $resp");
            }
            fclose($fp);
            return $resp;
        }
        $this->emit(API::ERROR, "Connection to api.cloudflare.com failed: {$errno} {$errstr}");
        return false;
    }

    public function invalidateEverything()
    {
        return $this->callApi(json_encode(array('purge_everything' => true)));
    }

    public function invalidate(array $urls)
    {
        $result = true;
        while ($urls) {
            $result = $this->callApi(json_encode(array('files' => array_splice($urls, 0, 30)))) && $result;
        }
        return $result;
    }
}

