<?php

namespace Loqate\ApiConnector\Client\Http;

use Exception;

/**
 * HttpClient class
 */
class HttpClient
{
    /** @var int Seconds allowed to establish the connection */
    const CONNECT_TIMEOUT = 2;

    /** @var int Default seconds allowed for the whole request */
    const TIMEOUT = 30;

    /**
     * Do GET request
     *
     * @param string $endpoint
     * @param array $params
     * @param int $timeout Total time limit in whole seconds; 0 means no limit (cURL semantics)
     * @return mixed
     * @throws Exception If the request fails or the response carries a Loqate error
     */
    public function get(string $endpoint, array $params, int $timeout = self::TIMEOUT)
    {
        $queryString = http_build_query($params);
        $endpoint .= '?' . $queryString;

        $cURLConnection = curl_init();
        curl_setopt($cURLConnection, CURLOPT_URL, $endpoint);
        curl_setopt($cURLConnection, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($cURLConnection, CURLOPT_CONNECTTIMEOUT, self::CONNECT_TIMEOUT);
        curl_setopt($cURLConnection, CURLOPT_TIMEOUT, $timeout);

        $response = curl_exec($cURLConnection);
        // curl_strerror() not curl_error(): curl_error() echoes a malformed proxy URL, password included ("Unsupported proxy scheme for '...'").
        $errno = curl_errno($cURLConnection);
        $curlError = $response === false ? sprintf('cURL error %d: %s', $errno, curl_strerror($errno)) : null;
        // LOQ-17709: do not restore curl_close() - deprecated from PHP 8.5, no effect since 8.0.
        // On PHP 7 the handle is a refcounted resource, so dropping its ONLY reference frees it
        // here; anything needing curl_getinfo() or curl_errno() must read them above this line.
        unset($cURLConnection);

        if ($curlError !== null) {
            throw new Exception($curlError);
        }

        $response = json_decode($response, true);
        if ($errorMessage = $this->searchForError($response)) {
            throw new Exception($errorMessage);
        }

        return $response;
    }

    /**
     * Do POST request
     *
     * @param string $endpoint
     * @param array $params
     * @param int $timeout Total time limit in whole seconds; 0 means no limit (cURL semantics)
     * @return mixed
     * @throws Exception If the request fails or the response carries a Loqate error
     */
    public function post(string $endpoint, array $params, int $timeout = self::TIMEOUT)
    {
        $cURLConnection = curl_init($endpoint);
        curl_setopt($cURLConnection, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
        curl_setopt($cURLConnection, CURLOPT_POSTFIELDS, json_encode($params));
        curl_setopt($cURLConnection, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($cURLConnection, CURLOPT_CONNECTTIMEOUT, self::CONNECT_TIMEOUT);
        curl_setopt($cURLConnection, CURLOPT_TIMEOUT, $timeout);

        $response = curl_exec($cURLConnection);
        // curl_strerror() not curl_error(): curl_error() echoes a malformed proxy URL, password included ("Unsupported proxy scheme for '...'").
        $errno = curl_errno($cURLConnection);
        $curlError = $response === false ? sprintf('cURL error %d: %s', $errno, curl_strerror($errno)) : null;
        // LOQ-17709: do not restore curl_close() - deprecated from PHP 8.5, no effect since 8.0.
        // On PHP 7 the handle is a refcounted resource, so dropping its ONLY reference frees it
        // here; anything needing curl_getinfo() or curl_errno() must read them above this line.
        unset($cURLConnection);

        if ($curlError !== null) {
            throw new Exception($curlError);
        }

        $response = json_decode($response, true);
        if ($errorMessage = $this->searchForError($response)) {
            throw new Exception($errorMessage);
        }

        return $response;
    }

    /**
     * Check for error in response
     *
     * @param $response
     * @return false|mixed
     */
    private function searchForError($response)
    {
        if (isset($response['Items'][0]['Error'])) {
            return $response['Items'][0]['Description'];
        }

        if (isset($response['Number'])) {
            return $response['Description'];
        }

        return false;
    }
}
