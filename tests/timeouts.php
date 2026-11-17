<?php

/**
 * LOQ-20337 - the Magento autocomplete abandons a lookup after 10 s in the browser
 * (LOQ-20320), so the server must give up first instead of holding a worker. Guards:
 * the 2 s connect limit, the total limit (cURL error 28 on time), a refused
 * connection failing fast (cURL error 7), the success path returning the body
 * unchanged, Capture passing 8 s while every other call keeps the 30 s default, and
 * a cURL failure reaching Capture's caller as ['error' => true, 'message' => ...].
 * Loopback stubs only: no network, no API key, PHP 7.0 syntax, POSIX (like
 * tests/no-deprecations.php). Run: php tests/timeouts.php - prints OK and exits 0,
 * or lists every failure and exits 1. Takes about seven seconds.
 */

// The short limit the slow-stub checks pass, and how long the stub stalls. The
// stall is well past limit + margin, so a client that ignored the limit is caught.
define('LOQ20337_SHORT_TIMEOUT', 1);
define('LOQ20337_STUB_STALL', 4);
// Allowed overshoot past a limit before it counts as not enforced.
define('LOQ20337_MARGIN', 1.5);
// Total limit for the connect-limit check: well above CONNECT_TIMEOUT + margin, so
// a client that only had the total limit is caught.
define('LOQ20337_CONNECT_PROBE_TIMEOUT', 6);

// ---------------------------------------------------------------------------
// Stub endpoint. The built-in server runs this same file as its router.
// ---------------------------------------------------------------------------
if (php_sapi_name() === 'cli-server') {
    // ?loq20337_stall=1 makes the stub slower than the short limit.
    if (isset($_GET['loq20337_stall'])) {
        sleep(LOQ20337_STUB_STALL);
    }

    $method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '';
    $sent = $method === 'POST' ? json_decode(file_get_contents('php://input'), true) : $_GET;

    header('Content-Type: application/json');
    echo json_encode(array('Items' => array(array('Id' => 'LOQ-20337')), 'Method' => $method, 'Sent' => $sent));
    return;
}

$GLOBALS['loq20337_server'] = null;
$GLOBALS['loq20337_pipes'] = array();
$GLOBALS['loq20337_server_log'] = '';
$GLOBALS['loq20337_failures'] = array();

function loq20337_error_handler($errno, $errstr, $errfile, $errline)
{
    $GLOBALS['loq20337_failures'][] = 'PHP reported error ' . $errno . ': ' . $errstr
        . ' in ' . $errfile . ' on line ' . $errline;

    return true;
}

function loq20337_check($passed, $description)
{
    if (!$passed) {
        $GLOBALS['loq20337_failures'][] = $description;
    }
}

/**
 * A loopback port nothing is listening on: bind one the OS picks, then close it.
 */
function loq20337_free_port()
{
    $socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($socket === false) {
        return 0;
    }

    $name = stream_socket_get_name($socket, false);
    fclose($socket);

    return (int) substr($name, strrpos($name, ':') + 1);
}

function loq20337_probe($port)
{
    $handle = curl_init('http://127.0.0.1:' . $port . '/loq20337-ready');
    curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($handle, CURLOPT_TIMEOUT, 5);
    $body = curl_exec($handle);
    unset($handle);

    return is_string($body) && strpos($body, '"Items"') !== false;
}

/**
 * Start the stub and wait for it to answer. Returns the port, or 0.
 */
function loq20337_start_server()
{
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $port = loq20337_free_port();
        if ($port === 0) {
            continue;
        }

        $command = 'exec ' . escapeshellarg(PHP_BINARY)
            . ' -d display_errors=stderr -d html_errors=0'
            . ' -S 127.0.0.1:' . $port
            . ' ' . escapeshellarg(__FILE__);

        $pipes = array();
        $server = @proc_open(
            $command,
            array(array('pipe', 'r'), array('file', '/dev/null', 'w'), array('pipe', 'w')),
            $pipes
        );
        if (!is_resource($server)) {
            continue;
        }

        $GLOBALS['loq20337_server'] = $server;
        $GLOBALS['loq20337_pipes'] = $pipes;
        stream_set_blocking($pipes[2], false);

        for ($probe = 0; $probe < 50; $probe++) {
            if (loq20337_probe($port)) {
                return $port;
            }
            usleep(50000);
        }

        loq20337_stop_server();
    }

    return 0;
}

/**
 * Stop the stub - also as a shutdown function, so a fatal part way through does
 * not leave it running (and still stalling).
 */
function loq20337_stop_server()
{
    if (!is_resource($GLOBALS['loq20337_server'])) {
        return;
    }

    if (isset($GLOBALS['loq20337_pipes'][2]) && is_resource($GLOBALS['loq20337_pipes'][2])) {
        $GLOBALS['loq20337_server_log'] .= (string) stream_get_contents($GLOBALS['loq20337_pipes'][2]);
    }

    proc_terminate($GLOBALS['loq20337_server']);
    foreach ($GLOBALS['loq20337_pipes'] as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    proc_close($GLOBALS['loq20337_server']);

    $GLOBALS['loq20337_server'] = null;
    $GLOBALS['loq20337_pipes'] = array();
}

/**
 * Run one request expected to fail with cURL error $errno within $limit seconds.
 */
function loq20337_expect_curl_error($label, $errno, $limit, $request)
{
    $started = microtime(true);
    $passed = false;
    try {
        $request();
        $outcome = 'it returned normally';
    } catch (Exception $exception) {
        $outcome = '"' . $exception->getMessage() . '"';
        $passed = strpos($exception->getMessage(), 'cURL error ' . $errno . ':') === 0;
    } catch (Throwable $throwable) {
        $outcome = get_class($throwable) . ': ' . $throwable->getMessage();
    }
    $elapsed = microtime(true) - $started;

    loq20337_check($passed, $label . ' must throw Exception "cURL error ' . $errno . ': ...", got ' . $outcome);
    loq20337_check(
        $elapsed < $limit + LOQ20337_MARGIN,
        sprintf('%s must give up within %.1f s, took %.2f s', $label, $limit + LOQ20337_MARGIN, $elapsed)
    );
    printf("  %s: %.2f s, %s\n", $label, $elapsed, $outcome);
}

// ---------------------------------------------------------------------------
// Arrange.
// ---------------------------------------------------------------------------
echo 'LOQ-20337 timeouts test, PHP ' . PHP_VERSION . "\n";

if (!extension_loaded('curl')) {
    echo "FAILED - ext-curl is not loaded, so HttpClient cannot be exercised\n";
    exit(1);
}

register_shutdown_function('loq20337_stop_server');

$port = loq20337_start_server();
if ($port === 0) {
    echo "FAILED - could not start the stub endpoint on 127.0.0.1\n" . trim($GLOBALS['loq20337_server_log']) . "\n";
    exit(1);
}
$base = 'http://127.0.0.1:' . $port;
$refusedPort = loq20337_free_port();
if ($refusedPort === 0) {
    echo "FAILED - could not find a free loopback port for the refused-connection check\n";
    exit(1);
}
$refused = 'http://127.0.0.1:' . $refusedPort;

// Accepts TCP in the kernel but is never accept()ed, so a TLS ClientHello sent to it
// is never answered - and libcurl counts the TLS handshake as part of connecting.
$silent = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($silent === false) {
    echo 'FAILED - could not open the silent listener: ' . $errstr . "\n";
    exit(1);
}
$silentUrl = 'https://' . stream_socket_get_name($silent, false) . '/x';

echo 'stub endpoint listening on ' . $base . ', refused endpoint ' . $refused
    . ', silent endpoint ' . $silentUrl . "\n";

error_reporting(E_ALL);
set_error_handler('loq20337_error_handler');

require_once __DIR__ . '/../src/Client/Http/HttpClient.php';

use Loqate\ApiConnector\Client\Capture;
use Loqate\ApiConnector\Client\Extras;
use Loqate\ApiConnector\Client\Http\HttpClient;
use Loqate\ApiConnector\Client\Verify;

/**
 * Stands in for the HttpClient a facade builds for itself: records the limit each
 * call was given and answers without touching the network, or throws $failure.
 */
class Loq20337SpyHttpClient extends HttpClient
{
    public $timeouts = array();
    public $failure = null;

    public function get(string $endpoint, array $params, int $timeout = self::TIMEOUT)
    {
        return $this->answer('GET', $timeout);
    }

    public function post(string $endpoint, array $params, int $timeout = self::TIMEOUT)
    {
        return $this->answer('POST', $timeout);
    }

    private function answer($method, $timeout)
    {
        $this->timeouts[] = $method . ' ' . $timeout;
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return array('Items' => array(array('Id' => 'LOQ-20337', 'ResponseCode' => 'Valid', 'IsValid' => 'Yes')));
    }
}

/**
 * Swap the spy into a facade's private $httpClient.
 */
function loq20337_inject($facade, HttpClient $spy)
{
    $inject = Closure::bind(function () use ($spy) {
        $this->httpClient = $spy;
    }, $facade, get_class($facade));
    $inject();

    return $facade;
}

$client = new HttpClient();
$skipped = '';
// A missing constant is reported by (d); here it just means no wait is allowed at all.
$connectLimit = defined(HttpClient::class . '::CONNECT_TIMEOUT') ? HttpClient::CONNECT_TIMEOUT : 0;
$getParams = array('Key' => 'LOQ-20337-NOT-A-REAL-KEY', 'Text' => '1 Test Street', 'Country' => 'GB');
$postParams = array('Key' => 'LOQ-20337-NOT-A-REAL-KEY', 'Addresses' => array(array('Address1' => '1 Test Street')));
$getEndpoint = $base . '/Capture/Interactive/Find/v1.10/json3.ws';
$postEndpoint = $base . '/Cleansing/International/Batch/v1.00/json4.ws';

// ---------------------------------------------------------------------------
// (c) An endpoint that answers in time: the default limit still returns the body.
// Run first, while the single-threaded stub is not busy stalling.
// ---------------------------------------------------------------------------
echo "answering endpoint, default limit\n";
try {
    loq20337_check(
        $client->get($getEndpoint, $getParams) === array(
            'Items' => array(array('Id' => 'LOQ-20337')), 'Method' => 'GET', 'Sent' => $getParams,
        ),
        'get() must return the decoded body unchanged when the endpoint answers in time'
    );
    loq20337_check(
        $client->post($postEndpoint, $postParams) === array(
            'Items' => array(array('Id' => 'LOQ-20337')), 'Method' => 'POST', 'Sent' => $postParams,
        ),
        'post() must return the decoded body unchanged when the endpoint answers in time'
    );
} catch (Throwable $throwable) {
    loq20337_check(false, 'a request the stub answers in time threw ' . get_class($throwable) . ': ' . $throwable->getMessage());
}

// ---------------------------------------------------------------------------
// (a) A stalling endpoint and a short explicit limit: cURL error 28, on time. The
// post() request queues behind the stalled get() on the single-threaded stub,
// which is just as slow from the client's side.
// ---------------------------------------------------------------------------
$failuresBeforeStall = count($GLOBALS['loq20337_failures']);
echo 'stalling endpoint (' . LOQ20337_STUB_STALL . ' s), limit ' . LOQ20337_SHORT_TIMEOUT . " s\n";
loq20337_expect_curl_error('get() at a stalling endpoint', 28, LOQ20337_SHORT_TIMEOUT, function () use ($client, $getEndpoint, $getParams) {
    $client->get($getEndpoint, array_merge($getParams, array('loq20337_stall' => '1')), LOQ20337_SHORT_TIMEOUT);
});
loq20337_expect_curl_error('post() at a stalling endpoint', 28, LOQ20337_SHORT_TIMEOUT, function () use ($client, $postEndpoint, $postParams) {
    $client->post($postEndpoint . '?loq20337_stall=1', $postParams, LOQ20337_SHORT_TIMEOUT);
});

// The silent endpoint below never answers, so only a client that enforces a total
// limit can be sent there without hanging the run.
$totalLimitEnforced = count($GLOBALS['loq20337_failures']) === $failuresBeforeStall;

// The stub has served its purpose; stop it so its stall does not outlive the test.
loq20337_stop_server();

// ---------------------------------------------------------------------------
// (b) Nothing listening: cURL error 7, well inside the connect limit.
// ---------------------------------------------------------------------------
echo "refused connection\n";
loq20337_expect_curl_error('get() at a refused port', 7, $connectLimit, function () use ($client, $refused, $getParams) {
    $client->get($refused . '/Capture/Interactive/Find/v1.10/json3.ws', $getParams);
});
loq20337_expect_curl_error('post() at a refused port', 7, $connectLimit, function () use ($client, $refused, $postParams) {
    $client->post($refused . '/Cleansing/International/Batch/v1.00/json4.ws', $postParams);
});

// ---------------------------------------------------------------------------
// The connect limit: a TLS handshake nobody answers, under a total limit well above
// it, must be cut off by CONNECT_TIMEOUT. Without it this runs to the total limit.
// ---------------------------------------------------------------------------
echo 'silent endpoint, total limit ' . LOQ20337_CONNECT_PROBE_TIMEOUT . " s\n";
if (!(curl_version()['features'] & CURL_VERSION_SSL)) {
    // Never pass silently: the OK line carries this too.
    $skipped .= ' (connect-limit check SKIPPED: this libcurl has no TLS support)';
    echo "  SKIPPED - this libcurl has no TLS support, so the handshake cannot stall\n";
} elseif (!$totalLimitEnforced) {
    loq20337_check(false, 'connect limit not checked: the total limit is not enforced, so the silent endpoint would hang the run');
} else {
    loq20337_expect_curl_error('get() at a silent endpoint', 28, $connectLimit, function () use ($client, $silentUrl, $getParams) {
        $client->get($silentUrl, $getParams, LOQ20337_CONNECT_PROBE_TIMEOUT);
    });
    loq20337_expect_curl_error('post() at a silent endpoint', 28, $connectLimit, function () use ($client, $silentUrl, $postParams) {
        $client->post($silentUrl, $postParams, LOQ20337_CONNECT_PROBE_TIMEOUT);
    });
}
fclose($silent);

// ---------------------------------------------------------------------------
// (d) The limits themselves.
// ---------------------------------------------------------------------------
echo "configured limits\n";
try {
    loq20337_check(HttpClient::CONNECT_TIMEOUT === 2, 'HttpClient::CONNECT_TIMEOUT must be 2');
    // At least 30: the module's email validation Timeout is up to 15 s, and its
    // batch address verification POSTs 100 rows.
    loq20337_check(HttpClient::TIMEOUT === 30, 'HttpClient::TIMEOUT must be 30');
    foreach (array('get', 'post') as $method) {
        $parameter = new ReflectionParameter(array(HttpClient::class, $method), 'timeout');
        loq20337_check(
            $parameter->getDefaultValue() === HttpClient::TIMEOUT,
            $method . '() $timeout must default to HttpClient::TIMEOUT'
        );
    }
} catch (Throwable $throwable) {
    loq20337_check(false, 'reading the limits threw ' . get_class($throwable) . ': ' . $throwable->getMessage());
}

// ---------------------------------------------------------------------------
// (e) Which limit each facade passes, and (f) the error envelope a cURL failure
// becomes. Both go through a spy, because the facades' base URL is hard-wired.
// ---------------------------------------------------------------------------
echo "facades, through a spy HttpClient\n";
try {
    require_once __DIR__ . '/../src/Utils/API.php';
    require_once __DIR__ . '/../src/Client/Capture.php';
    require_once __DIR__ . '/../src/Client/Verify.php';
    require_once __DIR__ . '/../src/Client/Extras.php';

    // defined() first, so a missing constant fails this check alone and the spy checks still run.
    loq20337_check(
        defined(Capture::class . '::TIMEOUT') && Capture::TIMEOUT === 8,
        'Capture::TIMEOUT must be 8, under the browser\'s 10 s'
    );

    // Capture: the autocomplete calls, each limited to 8 s.
    $spy = new Loq20337SpyHttpClient();
    $capture = loq20337_inject(new Capture('LOQ-20337-NOT-A-REAL-KEY'), $spy);
    $capture->find(array('Text' => '1 Test Street'));
    $capture->retrieve(array('Id' => 'LOQ-20337'));
    $capture->geolocate(array('Latitude' => '51.5', 'Longitude' => '-0.1'));
    loq20337_check(
        $spy->timeouts === array('GET 8', 'GET 8', 'GET 8'),
        'Capture find(), retrieve() and geolocate() must each pass 8 s, got [' . implode(', ', $spy->timeouts) . ']'
    );

    // Everything else keeps the 30 s default.
    $spy = new Loq20337SpyHttpClient();
    $verify = loq20337_inject(new Verify('LOQ-20337-NOT-A-REAL-KEY'), $spy);
    $extras = loq20337_inject(new Extras('LOQ-20337-NOT-A-REAL-KEY'), $spy);
    $verify->verifyEmail(array('Email' => 'test@example.com'));
    $verify->verifyPhone(array('Phone' => '+442071234567'));
    $verify->verifyAddress(array('Addresses' => array()));
    $extras->ipToCountry(array('IpAddress' => '127.0.0.1'));
    $extras->verifyAddress(array('Addresses' => array()));
    loq20337_check(
        $spy->timeouts === array('GET 30', 'GET 30', 'POST 30', 'GET 30', 'POST 30'),
        'Verify and Extras must keep the 30 s default, got [' . implode(', ', $spy->timeouts) . ']'
    );

    // (f) A cURL failure comes back from every Capture call as the envelope.
    $message = 'cURL error 28: Timeout was reached'; // the shape HttpClient builds from curl_strerror()
    $spy = new Loq20337SpyHttpClient();
    $spy->failure = new Exception($message);
    $capture = loq20337_inject(new Capture('LOQ-20337-NOT-A-REAL-KEY'), $spy);
    $envelope = array('error' => true, 'message' => $message);
    loq20337_check($capture->find(array('Text' => 'x')) === $envelope, 'find() must return the cURL error envelope');
    loq20337_check($capture->retrieve(array('Id' => 'x')) === $envelope, 'retrieve() must return the cURL error envelope');
    loq20337_check($capture->geolocate(array('Latitude' => '0')) === $envelope, 'geolocate() must return the cURL error envelope');
} catch (Throwable $throwable) {
    loq20337_check(false, 'the facade checks threw ' . get_class($throwable) . ': ' . $throwable->getMessage());
}

restore_error_handler();

// ---------------------------------------------------------------------------
// Report.
// ---------------------------------------------------------------------------
$failures = $GLOBALS['loq20337_failures'];
if (count($failures) === 0) {
    echo 'OK - requests time out and fail fast as configured on PHP ' . PHP_VERSION . $skipped . "\n";
    exit(0);
}

echo 'FAILED (' . count($failures) . ') on PHP ' . PHP_VERSION . "\n";
foreach ($failures as $number => $failure) {
    echo ($number + 1) . ') ' . $failure . "\n";
}
$log = trim($GLOBALS['loq20337_server_log']);
if ($log !== '') {
    echo "stub endpoint stderr:\n" . $log . "\n";
}
exit(1);
