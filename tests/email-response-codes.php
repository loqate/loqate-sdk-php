<?php

/**
 * LOQ-20324 - Verify::verifyEmail() answers true/false only for a verdict (Valid,
 * Invalid, Valid_CatchAll with AcceptValidCatchAll). Timeout - once returned as false,
 * so Magento rejected the order as "invalid email" - and any other, malformed or
 * missing code, no Items or a transport failure give ['error' => true, 'message' =>
 * ...] with no PHP diagnostic. A spy HttpClient (as in tests/timeouts.php) hands back
 * the decoded response: no server, no network, no API key, PHP 7.1 syntax.
 * Run: php tests/email-response-codes.php - prints OK and exits 0, or lists every
 * failure and exits 1.
 */

$GLOBALS['loq20324_failures'] = array();

function loq20324_error_handler($errno, $errstr, $errfile, $errline)
{
    $GLOBALS['loq20324_failures'][] = 'PHP reported error ' . $errno . ': ' . $errstr
        . ' in ' . $errfile . ' on line ' . $errline;

    return true;
}

error_reporting(E_ALL);
set_error_handler('loq20324_error_handler');

require_once __DIR__ . '/../src/Client/Http/HttpClient.php';
require_once __DIR__ . '/../src/Utils/API.php';
require_once __DIR__ . '/../src/Client/Verify.php';

use Loqate\ApiConnector\Client\Http\HttpClient;
use Loqate\ApiConnector\Client\Verify;

// Stands in for Verify's HttpClient: answers $response, or throws it if it is an Exception.
class Loq20324SpyHttpClient extends HttpClient
{
    public $response;

    public function get(string $endpoint, array $params, int $timeout = self::TIMEOUT)
    {
        if ($this->response instanceof Exception) {
            throw $this->response;
        }
        return $this->response;
    }
}

function loq20324_verify($response, array $params)
{
    $spy = new Loq20324SpyHttpClient();
    $spy->response = $response;
    $verify = new Verify('LOQ-20324-NOT-A-REAL-KEY');
    $inject = Closure::bind(function () use ($spy) {
        $this->httpClient = $spy;
    }, $verify, Verify::class);
    $inject();

    return $verify->verifyEmail($params + array('Email' => 'test@example.com'));
}

function loq20324_item($code)
{
    return array('Items' => array(array('Email' => 'test@example.com', 'ResponseCode' => $code)));
}

$flag = array(Verify::ACCEPT_VALID_CATCH_ALL => true);
$curlError = 'cURL error 7: Could not connect to server';

// label => [decoded response or Exception, params, expected: true, false, or text the error message must contain]
$cases = array(
    'Valid' => array(loq20324_item('Valid'), array(), true),
    'Valid with AcceptValidCatchAll' => array(loq20324_item('Valid'), $flag, true),
    'Invalid' => array(loq20324_item('Invalid'), array(), false),
    'Invalid with AcceptValidCatchAll' => array(loq20324_item('Invalid'), $flag, false),
    'Valid_CatchAll with AcceptValidCatchAll' => array(loq20324_item('Valid_CatchAll'), $flag, true),
    'Valid_CatchAll without AcceptValidCatchAll' => array(loq20324_item('Valid_CatchAll'), array(), false),
    'Timeout' => array(loq20324_item('Timeout'), array(), 'Timeout'),
    'Timeout with AcceptValidCatchAll' => array(loq20324_item('Timeout'), $flag, 'Timeout'),
    'unknown code "Unknown"' => array(loq20324_item('Unknown'), array(), ''),
    'empty ResponseCode' => array(loq20324_item(''), array(), ''),
    'Item without ResponseCode' => array(array('Items' => array(array('Email' => 'test@example.com'))), array(), ''),
    'null ResponseCode' => array(loq20324_item(null), array(), ''),
    'int ResponseCode 7' => array(loq20324_item(7), array(), ''),
    'array ResponseCode' => array(loq20324_item(array('Timeout')), array(), ''),
    'no Items' => array(array('Items' => array()), array(), ''),
    'get() throws' => array(new Exception($curlError), array(), $curlError),
);

foreach ($cases as $label => $case) {
    $result = loq20324_verify($case[0], $case[1]);
    $expected = $case[2];
    echo '  ' . $label . ': ' . json_encode($result) . "\n";

    $passed = is_bool($expected) ? $result === $expected
        : is_array($result) && isset($result['error'], $result['message']) && $result['error'] === true
            && is_string($result['message']) && $result['message'] !== ''
            && ($expected === '' || strpos($result['message'], $expected) !== false);
    if (!$passed) {
        $GLOBALS['loq20324_failures'][] = $label . ' must return '
            . (is_bool($expected) ? var_export($expected, true)
                : 'the error envelope' . ($expected === '' ? '' : ' naming "' . $expected . '"'));
    }
}

$failures = $GLOBALS['loq20324_failures'];
if (count($failures) === 0) {
    echo 'OK - verifyEmail() returns a verdict only for Valid, Invalid and Valid_CatchAll, and the error '
        . 'envelope for everything else, on PHP ' . PHP_VERSION . "\n";
    exit(0);
}

echo 'FAILED (' . count($failures) . ') on PHP ' . PHP_VERSION . "\n";
foreach ($failures as $number => $failure) {
    echo ($number + 1) . ') ' . $failure . "\n";
}
exit(1);
