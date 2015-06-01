<?php



if(isset($_POST['payload'])){
    
    include 'FTPbucket.php';
    
    $go = new FTPbucket();
    
    $go->init($_POST['payload']);
}

?>