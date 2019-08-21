<?php

unlink( 'logfile.txt' );
unlink( 'logconnection.txt' );
// ob_end_clean();

ini_set('memory_limit', '2500M');
ini_set('max_execution_time', 0);

putenv( 'PHP_FCGI_MAX_REQUESTS=0' );
putenv( 'PHP_FCGID_BUSY_TIMEOUT=3600' );
putenv( 'PHP_FCGI_IDLE_TIMEOUT=0' );
// var_dump(getenv('PHP_FCGI_MAX_REQUESTS'));
// phpinfo();
// var_dump(get_defined_vars());
// exit;
// echo "<pre>";
// print_r(get_defined_constants());
// echo "</pre>";
// exit;

// ob_end_clean();

ignore_user_abort(true);
set_time_limit(0);

ob_start();
// do initial processing here
echo "Roger that! The deployment script has received your request.";

header('Connection: close');
header('Content-Length: '.ob_get_length());
ob_end_flush();
ob_flush();
flush();

for( $i = 0; $i< 22;$i++){
  flush();
}
if( function_exists( 'fastcgi_finish_request' ) )
  fastcgi_finish_request();


if ($fp = fopen('logconnection.txt', 'a')) {
    $start_time = microtime(true);
    fwrite($fp, 'A PUSH was started at ' . date("d.m.Y, H:i:s", $start_time) . PHP_EOL);
}

// BitBucket webhook goes here
$create_new_branch = true;
if( $create_new_branch ){
  $payload = json_decode(file_get_contents('tests/bb_new_branch_created.json'), TRUE);
} else {
  $payload = json_decode(file_get_contents('tests/bb_mixed_payload.json'), TRUE);
}
 // else {
 //  $payload = json_decode(file_get_contents('tests/bb_payload.json'), TRUE);
// }

include 'BBjson-test.php';
$go = new BBjson();
$go->init($payload);

$end_time = microtime(true);
fwrite($fp, 'The push finally finished at ' . date("d.m.Y, H:i:s", $end_time) . '.' . PHP_EOL);
fwrite($fp, 'In total it took ' . ceil($end_time - $start_time) . ' seconds.' . PHP_EOL . PHP_EOL);
fclose($fp);