<?php

namespace Loqate\ApiConnector\Client;

use Loqate\ApiConnector\Client\Http\HttpClient;
use Loqate\ApiConnector\Utils\API;
use Throwable;

/**
 * Verify class
 */
class Verify
{
    const VALID = 'Valid';
    const INVALID = 'Invalid';
    const VALID_CATCH_ALL = 'Valid_CatchAll';
    /** @var string Email ResponseCode value, not a duration (unlike HttpClient::TIMEOUT, Capture::TIMEOUT) */
    const TIMEOUT = 'Timeout';
    const ACCEPT_VALID_CATCH_ALL = 'AcceptValidCatchAll';

    /** @var string ApiKey */
    private $apiKey;

    /** @var HttpClient $httpClient */
    private $httpClient;

    /**
     * Verify constructor
     *
     * @param $apiKey
     */
    public function __construct($apiKey)
    {
        $this->httpClient = new HttpClient();
        $this->apiKey = $apiKey;
    }

    /**
     * Search address using the find endpoint of the Loqate API
     *
     * @param $params
     * @return array
     */
    public function verifyAddress($params)
    {
        $params['Key'] = $this->apiKey;
        $endpoint = API::getEndpoint('address_verify');

        try {
            $response = $this->httpClient->post($endpoint, $params);

            if (is_array($response)) {
                return array_column($response, 'Matches');
            }
        } catch (Throwable $exception) {
            return ['error' => true, 'message' => $exception->getMessage()];
        }

        return ['error' => true, 'message' => 'Unexpected error occurred.'];
    }

    /**
     * Verify email address
     *
     * Returns true/false only for a verdict (Valid, Invalid, Valid_CatchAll). Otherwise it
     * returns ['error' => true, 'message' => string]. When Loqate returned an item with no
     * verdict (e.g. Timeout) the array also has 'responseCode': the code if it is a word of
     * 1-64 characters, else ''. The key is absent when there was no usable item (no Items,
     * empty Items, an empty first item) or the request failed (transport error, or a Loqate
     * API error, which HttpClient throws). Compare it (=== Verify::TIMEOUT); don't take
     * isset() to mean "timed out".
     *
     * @param $params
     * @return array|bool
     */
    public function verifyEmail($params)
    {
        $params['Key'] = $this->apiKey;
        $endpoint = API::getEndpoint('email_verification');

        try {
            $response = $this->httpClient->get($endpoint, $params);

            if (isset($response['Items']) && $item = reset($response['Items'])) {
                $code = isset($item['ResponseCode']) ? $item['ResponseCode'] : '';

                if ($code === self::VALID || $code === self::INVALID) {
                    return $code === self::VALID;
                }

                if ($code === self::VALID_CATCH_ALL) {
                    return isset($params[self::ACCEPT_VALID_CATCH_ALL]);
                }

                // json_encode(): an array code would warn if concatenated, and a newline in it could forge a log line.
                // responseCode keeps only a plain word so it is safe to log or render; \z, not $, which allows a trailing newline.
                return [
                    'error' => true,
                    'message' => 'Email verification returned no verdict (ResponseCode: ' . json_encode($code) . ').',
                    'responseCode' => is_string($code) && preg_match('/^[A-Za-z0-9_]{1,64}\z/', $code) ? $code : '',
                ];
            }
        } catch (Throwable $exception) {
            return ['error' => true, 'message' => $exception->getMessage()];
        }

        return ['error' => true, 'message' => 'Unexpected error occurred.'];
    }

    /**
     * Verify phone number
     *
     * @param $params
     * @return array|bool
     */
    public function verifyPhone($params)
    {
        $params['Key'] = $this->apiKey;
        $endpoint = API::getEndpoint('phone_verification');

        try {
            $response = $this->httpClient->get($endpoint, $params);

            if (isset($response['Items']) && $item = reset($response['Items'])) {
                return $item['IsValid'] === 'Yes';
            }
        } catch (Throwable $exception) {
            return ['error' => true, 'message' => $exception->getMessage()];
        }

        return ['error' => true, 'message' => 'Unexpected error occurred.'];
    }
}
