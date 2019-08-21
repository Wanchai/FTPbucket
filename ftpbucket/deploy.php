<?php
ini_set('memory_limit', '250M');
ini_set('max_execution_time', 0);

ob_end_clean();

ignore_user_abort(true);
set_time_limit(0);

// Sends a green flag to the repo
header("Connection: close\r\n");
header("Content-Encoding: none\r\n");

ob_start();
echo "Roger that! The deployment script has received your request.";
header("Content-Length: ".ob_get_length());

ob_end_flush();
flush();
ob_end_clean();

if ($fp = fopen('logconnection.txt', 'a')) {
    $start_time = microtime(true);
    fwrite($fp, 'A PUSH was started at ' . date("d.m.Y, H:i:s", $start_time) . PHP_EOL);
}


$inputJSON = file_get_contents('php://input');
$payload = json_decode($inputJSON, TRUE);

if (!isset($payload)) {
    $msg = date("d.m.Y, H:i:s", time()) . ": PAYLOAD not supported \n";
    $log = fopen("logpayload.txt", "a");
    fputs($log, $msg);
    fclose($log);
} else if (isset($payload['repository']['html_url']) && substr_count($payload['repository']['html_url'], 'github') === 1) {
    // GitHub webhook goes here
    include 'GHjson.php';
    $go = new GHjson();
    $go->init($payload);
} else {
    // BitBucket webhook goes here
    include 'BBjson.php';
    $go = new BBjson();
    $go->init($payload);
}


$end_time = microtime(true);
fwrite($fp, 'The push finally finished at ' . date("d.m.Y, H:i:s", $end_time) . '.' . PHP_EOL);
fwrite($fp, 'In total it took ' . ceil($end_time - $start_time) . ' seconds.' . PHP_EOL . PHP_EOL);
fclose($fp);