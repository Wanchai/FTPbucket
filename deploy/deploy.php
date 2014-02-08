<?php

include 'FTPbucket.php';

$go = new FTPbucket();

if(isset($_POST['payload'])){
    $go->init($_POST['payload']);
}

?>