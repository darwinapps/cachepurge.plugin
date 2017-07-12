<?php

namespace CachePurge\Api;

/* example nxing configuration section

    location ~ /purge(.*) {
        selective_cache_purge_query "$1";
    }

    location ~ /mpurge {
        client_body_buffer_size 128k;
        client_max_body_size 128k;

        content_by_lua_block {
            ngx.req.read_body()
            local data = ngx.req.get_body_data()

            local http = require "resty.http"
            local httpc = http.new()
            httpc:set_timeouts(100, 100, 5000)

            for str in string.gmatch(data, "([^\r\n]+)") do
                local res, err = httpc:request_uri("http://127.0.0.1/purge" .. str)
                if not res then
                    ngx.say("failed to purge " .. str .. ": ", err)
                else
                    ngx.say(res.body)
                end
            end

            return
        }
    }
     */

/**
 * Class Nginx
 * @package CachePurge\Api
 */
class Nginx extends Api
{
    protected $url;
    protected $username;
    protected $password;
    protected $timeout = 10;

    public function setUrl($value)
    {
        $this->url = trim($value);
    }

    public function setUsername($value)
    {
        $this->username = trim($value);
    }

    public function setPassword($value)
    {
        $this->password = trim($value);
    }

    public function callApi($url, $body)
    {
        if (!$this->url || false == parse_url($this->url)) {
            $this->emit(Api::ERROR, "Nginx API is not configured properly");
            return false;
        }

        $host = parse_url($url, PHP_URL_HOST);
        $msg = "POST {$url} HTTP/1.0\r\n";
        $msg .= "Host: {$host}\r\n";
        $msg .= "Content-Length: " . strlen($body) . "\r\n";
        if ($this->username)
            $msg .= 'Authorization: Basic ' . base64_encode($this->username . ':' . $this->password) . "\r\n";
        $msg .= "\r\n";
        $msg .= $body;

        $fp = strtolower(parse_url($url, PHP_URL_SCHEME)) == 'https'
            ? @fsockopen('ssl://' . $host, 443, $errno, $errstr, $this->timeout)
            : @fsockopen($host, 80, $errno, $errstr, $this->timeout);

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
        $this->emit(API::ERROR, "Connection to {$url} failed: {$errno} {$errstr}");
        return false;
    }

    public function invalidate(array $urls)
    {
        $result = true;
        foreach (preg_split('/\s*;\s*/', $this->url) as $apiUrl) {
            $result = $this->callApi($apiUrl, join("\n", $urls)) && $result;
        }
        return $result;
    }
}