<?php

/**
 * LOQ-17709 - the HTTP client must not raise a deprecation on any supported PHP.
 *
 * PHP 8.5 deprecated curl_close(), and this client used to call it after every
 * request. On Magento that is not log noise: app/bootstrap.php sets
 * error_reporting(E_ALL) unconditionally and Bootstrap::run() installs an error
 * handler that throws on E_DEPRECATED whatever MAGE_MODE is, so the deprecation
 * became an exception mid-request and the callers' own catch blocks turned every
 * single Loqate API call into an error envelope. A cURL handle has been a garbage
 * collected object since PHP 8.0, so the client now just drops its reference and
 * lets the engine free it.
 *
 * This file guards that. It boots its own stub endpoint on the loopback interface
 * with the built-in server (this same file acts as the router), drives get() and
 * post() at it under an error handler that turns any diagnostic PHP reports to
 * the ambient handler into a failure, and covers two things: the clean path, so
 * that silencing the deprecation by breaking the request cannot pass either, and
 * the error path, so that both methods are still proven to throw the Description
 * out of a Loqate error envelope - the contract the Magento module's behaviour is
 * built on (see its Helper/Validator.php).
 *
 * It never contacts api.addressy.com and it needs no API key. It has no
 * dependencies and no syntax newer than PHP 7.0, because the library supports
 * PHP >= 7.0 and has nothing in require-dev. It does assume POSIX, in three
 * places - the "exec" prefix, escapeshellarg()'s single-quote quoting and
 * /dev/null - so it does not run on Windows. Run it with:
 *
 *     php tests/no-deprecations.php
 *
 * It prints OK and exits 0, or prints every offending diagnostic and exits 1.
 */

// The stub and the assertion below must agree on this text exactly, and the
// built-in server runs this same file, so define it once for both.
define('LOQ17709_STUB_ERROR', 'LOQ-17709 stub error');

// ---------------------------------------------------------------------------
// Stub endpoint. The built-in server runs this same file as its router, so serve
// the fake Loqate response and stop before the test below is reached.
// ---------------------------------------------------------------------------
if (php_sapi_name() === 'cli-server') {
    header('Content-Type: application/json');

    // ?loq17709_error=1 asks for a top-level Loqate error envelope - the shape
    // searchForError() reports on and both methods are expected to throw for.
    if (isset($_GET['loq17709_error'])) {
        echo json_encode(array('Number' => 123, 'Description' => LOQ17709_STUB_ERROR));
        return;
    }

    $method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '';

    $sent = array();
    if ($method === 'POST') {
        $decoded = json_decode(file_get_contents('php://input'), true);
        if (is_array($decoded)) {
            $sent = $decoded;
        }
    } else {
        $sent = $_GET;
    }

    // No Items[0].Error and no Number, so searchForError() stays quiet and both
    // methods run their clean path. Sent echoes the request back, which is what
    // lets the test tell a real round trip from an empty return value.
    echo json_encode(array('Items' => array(), 'Method' => $method, 'Sent' => $sent));
    return;
}

$GLOBALS['loq17709_server'] = null;
$GLOBALS['loq17709_pipes'] = array();
$GLOBALS['loq17709_server_log'] = '';
$GLOBALS['loq17709_boot_reasons'] = array();
$GLOBALS['loq17709_diagnostics'] = array();
$GLOBALS['loq17709_failures'] = array();

/**
 * Name an error level for the report. Only the levels a userland handler can
 * actually be called for here are listed - the fallback covers the rest, which
 * keeps unreachable entries out of the map.
 *
 * E_USER_DEPRECATED is the exception to that: nothing in the client raises one,
 * but it is the userland twin of the very defect class this test guards, so if one
 * ever does appear it should be reported by name and not as a bare number.
 *
 * E_STRICT is left out on purpose: naming that constant is itself deprecated
 * from PHP 8.4 on, which would make this test trip over itself.
 */
function loq17709_error_name($errno)
{
    $names = array(
        E_WARNING => 'E_WARNING',
        E_NOTICE => 'E_NOTICE',
        E_DEPRECATED => 'E_DEPRECATED',
        E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
        E_USER_DEPRECATED => 'E_USER_DEPRECATED',
    );

    return isset($names[$errno]) ? $names[$errno] : 'error ' . $errno;
}

/**
 * Record every diagnostic PHP reports as a failure, and show it as it happens so
 * it is obvious which call produced it.
 */
function loq17709_error_handler($errno, $errstr, $errfile, $errline)
{
    $diagnostic = loq17709_error_name($errno) . ': ' . $errstr
        . ' in ' . $errfile . ' on line ' . $errline;

    $GLOBALS['loq17709_diagnostics'][] = $diagnostic;
    echo '  ! ' . $diagnostic . "\n";

    return true;
}

function loq17709_check($passed, $description)
{
    if (!$passed) {
        $GLOBALS['loq17709_failures'][] = $description;
    }
}

/**
 * Ask the operating system for a port nobody is using, so parallel runs and busy
 * build agents cannot collide on a hardcoded one.
 */
function loq17709_free_port()
{
    // Kept quiet because a failure here is reported by this function's return
    // value, but the out-parameters are recorded so the reason is not lost.
    $socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($socket === false) {
        $GLOBALS['loq17709_boot_reasons'][] = 'stream_socket_server() failed: '
            . $errstr . ' (errno ' . $errno . ')';

        return 0;
    }

    $name = stream_socket_get_name($socket, false);
    fclose($socket);

    $colon = strrpos($name, ':');

    return $colon === false ? 0 : (int) substr($name, $colon + 1);
}

/**
 * One request at the stub, over cURL because ext-curl is a hard requirement of
 * this library while allow_url_fopen is not guaranteed to be on.
 */
function loq17709_probe($port)
{
    $handle = curl_init('http://127.0.0.1:' . $port . '/loq17709-ready');
    curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($handle, CURLOPT_TIMEOUT, 5);
    $body = curl_exec($handle);
    unset($handle);

    return is_string($body) && strpos($body, '"Items"') !== false;
}

/**
 * Start the stub and wait for it to answer. Returns the port, or 0 if it never
 * came up. Budget is 2 attempts of 50 probes each, which is about 2.5s per attempt
 * when the port refuses the probe immediately - the case that matters, since a port
 * nothing is listening on refuses instantly - so an environment that cannot run the
 * built-in server at all fails fast instead of sitting silent. A port that instead
 * swallowed the probe would take the probe timeout for each of the 50 instead.
 */
function loq17709_start_server()
{
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $port = loq17709_free_port();
        if ($port === 0) {
            continue;
        }

        // "exec" so that the shell proc_open() goes through replaces itself with
        // PHP, which keeps proc_terminate() pointed at the server itself.
        $command = 'exec ' . escapeshellarg(PHP_BINARY)
            . ' -d display_errors=stderr -d html_errors=0'
            . ' -S 127.0.0.1:' . $port
            . ' ' . escapeshellarg(__FILE__);

        // Nothing reads the server's stdout, so send it to the bit bucket and
        // keep only stderr, which is where the boot error and the access log go.
        $pipes = array();
        error_clear_last();
        $server = @proc_open(
            $command,
            array(array('pipe', 'r'), array('file', '/dev/null', 'w'), array('pipe', 'w')),
            $pipes
        );
        if (!is_resource($server)) {
            $last = error_get_last();
            $GLOBALS['loq17709_boot_reasons'][] = 'proc_open() failed: '
                . ($last === null ? 'no reason reported' : $last['message']);
            continue;
        }

        $GLOBALS['loq17709_server'] = $server;
        $GLOBALS['loq17709_pipes'] = $pipes;
        stream_set_blocking($pipes[2], false);

        for ($probe = 0; $probe < 50; $probe++) {
            if (loq17709_probe($port)) {
                return $port;
            }
            usleep(50000);
        }

        $GLOBALS['loq17709_boot_reasons'][] = 'the stub on port ' . $port
            . ' never answered a probe';
        loq17709_stop_server();
    }

    return 0;
}

/**
 * Stop the stub. Registered as a shutdown function, so it also runs when the
 * test fails, throws, or dies part way through.
 */
function loq17709_stop_server()
{
    if (!is_resource($GLOBALS['loq17709_server'])) {
        return;
    }

    // Drain stderr into the buffer while the pipe is still open. Reading it after
    // teardown - which closes the pipes and empties this array - always saw
    // nothing, which is how a failed boot came with no explanation at all. Draining
    // only here is safe because the whole run is a handful of requests, one access
    // log line each, nowhere near enough to fill the pipe and block the server.
    if (isset($GLOBALS['loq17709_pipes'][2]) && is_resource($GLOBALS['loq17709_pipes'][2])) {
        $GLOBALS['loq17709_server_log'] .= (string) stream_get_contents($GLOBALS['loq17709_pipes'][2]);
    }

    proc_terminate($GLOBALS['loq17709_server']);
    foreach ($GLOBALS['loq17709_pipes'] as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    proc_close($GLOBALS['loq17709_server']);

    $GLOBALS['loq17709_server'] = null;
    $GLOBALS['loq17709_pipes'] = array();
}

/**
 * Show the stub's side of the story next to a failure, if it said anything.
 */
function loq17709_print_server_log()
{
    $log = trim($GLOBALS['loq17709_server_log']);
    if ($log === '') {
        return;
    }

    echo "stub endpoint stderr:\n" . $log . "\n";
}

// ---------------------------------------------------------------------------
// Arrange. Boot the stub first, while warnings from probing a port that is not
// listening yet are still harmless.
// ---------------------------------------------------------------------------
echo 'LOQ-17709 no-deprecations test, PHP ' . PHP_VERSION . "\n";

if (!extension_loaded('curl')) {
    // ext-curl is a hard requirement of this library, so a run without it has
    // proved nothing and must not be allowed to look like a pass.
    echo "FAILED - ext-curl is not loaded, so HttpClient cannot be exercised\n";
    exit(1);
}

register_shutdown_function('loq17709_stop_server');

$port = loq17709_start_server();
if ($port === 0) {
    echo "FAILED - could not start the stub endpoint on 127.0.0.1\n";
    foreach ($GLOBALS['loq17709_boot_reasons'] as $reason) {
        echo '  ! ' . $reason . "\n";
    }
    loq17709_print_server_log();
    exit(1);
}

echo 'stub endpoint listening on http://127.0.0.1:' . $port . "\n";

$getParams = array(
    'Key' => 'LOQ-17709-NOT-A-REAL-KEY',
    'Text' => '1 Test Street',
    'Country' => 'GB',
);
$postParams = array(
    'Key' => 'LOQ-17709-NOT-A-REAL-KEY',
    'Addresses' => array(array('Address1' => '1 Test Street', 'Locality' => 'London')),
);

// ---------------------------------------------------------------------------
// Act. From here on any diagnostic at all is a failure. The client is loaded
// inside this region on purpose, so that a deprecation raised while compiling it
// counts too, not only the ones raised while a request runs.
// ---------------------------------------------------------------------------
error_reporting(E_ALL);
set_error_handler('loq17709_error_handler');

$getResult = null;
$postResult = null;

try {
    require_once __DIR__ . '/../src/Client/Http/HttpClient.php';
    $client = new Loqate\ApiConnector\Client\Http\HttpClient();

    echo "calling HttpClient::get()\n";
    $getResult = $client->get('http://127.0.0.1:' . $port . '/Capture/Interactive/Find/v1.10/json3.ws', $getParams);

    echo "calling HttpClient::post()\n";
    $postResult = $client->post('http://127.0.0.1:' . $port . '/Cleansing/International/Batch/v1.00/json4.ws', $postParams);

    // The error envelope reaches searchForError() only after the handle has been
    // released, so these exercise the same release on the throwing path - and they
    // pin the throw itself, which is what the Magento module reads a failed
    // verification off of. Both methods are covered because both carry operations
    // the module calls: get() six of the eight (find, retrieve, geolocate,
    // verifyEmail, verifyPhone, ipToCountry) and post() the other two (the single
    // and the batch address verification).
    echo "calling HttpClient::get() at the error route\n";
    try {
        // The flag goes in the parameters, not on the endpoint: get() appends a
        // query string of its own built from them, so a "?" in the endpoint would
        // leave the request carrying two.
        $client->get(
            'http://127.0.0.1:' . $port . '/Capture/Interactive/Find/v1.10/json3.ws',
            array_merge($getParams, array('loq17709_error' => '1'))
        );
        loq17709_check(false, 'get() must throw when the response is a Loqate error envelope');
    } catch (Exception $expected) {
        loq17709_check(
            $expected->getMessage() === LOQ17709_STUB_ERROR,
            'get() must throw the envelope Description "' . LOQ17709_STUB_ERROR
                . '", got "' . $expected->getMessage() . '"'
        );
    }

    echo "calling HttpClient::post() at the error route\n";
    try {
        $client->post(
            'http://127.0.0.1:' . $port . '/Cleansing/International/Batch/v1.00/json4.ws?loq17709_error=1',
            $postParams
        );
        loq17709_check(false, 'post() must throw when the response is a Loqate error envelope');
    } catch (Exception $expected) {
        loq17709_check(
            $expected->getMessage() === LOQ17709_STUB_ERROR,
            'post() must throw the envelope Description "' . LOQ17709_STUB_ERROR
                . '", got "' . $expected->getMessage() . '"'
        );
    }
} catch (Throwable $throwable) {
    loq17709_check(false, 'HttpClient threw ' . get_class($throwable) . ': ' . $throwable->getMessage());
}

restore_error_handler();

// ---------------------------------------------------------------------------
// Assert. Both the absence of diagnostics and the response itself, so that a
// "fix" which quietened the deprecation by returning nothing still fails.
// ---------------------------------------------------------------------------
loq17709_check(is_array($getResult), 'get() must return the decoded response array, got ' . gettype($getResult));
loq17709_check(
    is_array($getResult) && isset($getResult['Method']) && $getResult['Method'] === 'GET',
    'get() must reach the endpoint with a GET request'
);
loq17709_check(
    is_array($getResult) && isset($getResult['Items']) && $getResult['Items'] === array(),
    'get() must return the empty Items list the endpoint sent'
);
$getSent = is_array($getResult) && isset($getResult['Sent']) && is_array($getResult['Sent'])
    ? $getResult['Sent']
    : array();
// Loose on purpose, and only here: a query string carries no types, so $_GET hands
// every value back as a string and === would fail on that alone. Do not "fix" it to
// a strict comparison. The POST body below is compared strictly, because that one
// round trips as JSON in both directions and so keeps its types.
loq17709_check($getSent == $getParams, 'get() must send its parameters as the query string unchanged');

loq17709_check(is_array($postResult), 'post() must return the decoded response array, got ' . gettype($postResult));
loq17709_check(
    is_array($postResult) && isset($postResult['Method']) && $postResult['Method'] === 'POST',
    'post() must reach the endpoint with a POST request'
);
loq17709_check(
    is_array($postResult) && isset($postResult['Items']) && $postResult['Items'] === array(),
    'post() must return the empty Items list the endpoint sent'
);
$postSent = is_array($postResult) && isset($postResult['Sent']) && is_array($postResult['Sent'])
    ? $postResult['Sent']
    : array();
loq17709_check($postSent === $postParams, 'post() must send its parameters as the JSON body unchanged');

// ---------------------------------------------------------------------------
// Report.
// ---------------------------------------------------------------------------
$failures = $GLOBALS['loq17709_failures'];
foreach ($GLOBALS['loq17709_diagnostics'] as $diagnostic) {
    $failures[] = $diagnostic;
}

loq17709_stop_server();

if (count($failures) === 0) {
    echo 'OK - get() and post() returned the response, both threw on the error envelope, and no '
        . 'diagnostics were raised on PHP ' . PHP_VERSION . "\n";
    exit(0);
}

echo 'FAILED (' . count($failures) . ') on PHP ' . PHP_VERSION . "\n";
$number = 0;
foreach ($failures as $failure) {
    $number++;
    echo $number . ') ' . $failure . "\n";
}
loq17709_print_server_log();
exit(1);
