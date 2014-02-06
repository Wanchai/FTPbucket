<?php

include 'FTPbucket.php';
require_once 'config.php';

$go = new FTPbucket();

if(isset($_POST['payload'])){
    $go->init($_POST['payload']);
}

?>