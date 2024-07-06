<?php

namespace App\Libraries\Icress;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

abstract class IcressClient
{
    private $timeout = 30;

    private $url;
    private $headers = array();

    protected $debug = false;

    // API routes that returns response in HTML
    private const HTML_ROUTES = [
        'index_tt.cfm',
        'index_result.cfm',
    ];

    // Set debug
    public function setDebug($debug = true)
    {
        $this->debug = (bool) $debug;
    }

    // HTTP request URL
    private function getUrl($route = null)
    {
        return config('uitmtimetable.icress_url') . $route;
    }

    // Set HTTP request header
    protected function setHeader($key, $value)
    {
        $this->headers[$key] = $value;
    }

    // HTTP request headers
    private function getHeaders()
    {
        return $this->headers;
    }

    // HTTP GET request
    protected function get($route, $params = array())
    {
        return $this->request($route, $params, 'GET');
    }

    // HTTP POST request
    protected function post($route, $params = array())
    {
        return $this->request($route, $params);
    }

    // HTTP request
    protected function request($route, $params = array(), $method = 'POST')
    {
        $url = $this->getUrl($route);
        $headers = $this->getHeaders();

        $response = false;

        try {
            switch ($method) {
                case 'GET':
                    $response = Http::timeout($this->timeout)
                                    ->withHeaders($headers)
                                    ->get($url, $params);
                    break;

                case 'POST':
                    $response = Http::timeout($this->timeout)
                                    ->asForm()
                                    ->withHeaders($headers)
                                    ->post($url, $params);
                    break;
            }
        } catch (Exception $e) {
            throw new Exception('Unable to connect with UiTM iCress');
        }

        if (!$response) {
            throw new Exception('Invalid request method');
        }

        $code = (int) $response->status();
        $body = $response->json();

        // Some route returns response in HTML
        if (in_array($route, self::HTML_ROUTES)) {
            $body = $response->body();

            // Remove whitespaces and new lines from HTML response
            $body = preg_replace('/[\r\n\t ]+/', ' ', $body);
            $body = trim($body);
        }

        $this->log()->debug($method . ' - ' . $url, [
            'request' => [
                'headers' => $headers,
                'params'  => $params,
            ],
            'response' => [
                'status'  => $code,
                'body'    => $body,
            ],
        ]);

        return array($code, $body);
    }

    // Debug logging
    protected function log()
    {
        return Log::channel('uitm_timetable_icress_api');
    }
}
