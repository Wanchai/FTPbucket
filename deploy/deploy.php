<?php

if(isset($_POST['payload']))
{
    $data = json_decode(stripslashes($_POST['payload']));
    
    if($data->canon_url != null)
	{
	    include 'BBpost.php';
        $go = new BBpost();
        $go->init($_POST['payload']);
	}
}
else
{
    $inputJSON = file_get_contents('php://input');
    $payload = json_decode($inputJSON, TRUE);
    
    if (!isset($payload)) 
    {
        $msg = date("d.m.Y, H:i:s",time()) .": PAYLOAD not supported \n";
        $log = fopen ("logpayload.txt", "a");
        fputs($log, $msg);
        fclose($log);
    }
    else if (isset($payload['repository']['html_url']) && substr_count($payload['repository']['html_url'], 'github') === 1)
    {
        // GitHub webhook goes here
	    include 'GHjson.php';
        $go = new GHjson();
        $go->init($payload);        
    }
    else
    {
        // BitBucket webhook goes here
	    include 'BBjson.php';
        $go = new BBjson();
        $go->init($payload);
    }
}

?>