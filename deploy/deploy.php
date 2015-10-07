<?php

if(isset($_POST['payload']))
{
    include 'FTPbucket.php';
    
    $go = new FTPbucket();
    $go->init($_POST['payload']);
}
else
{
    $msg = date("d.m.Y, H:i:s",time()) .': We received nothing from BitBucket ... \n';
    $log = fopen ("logfile.txt", "a");
    fputs($log, $msg);
    fclose($log);
}

?>