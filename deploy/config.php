<?php
/*
* @repo_name = you can find it in your repo URL : https://bitbucket.org/a_name/repo_name/
* @type = only support FTP for now
*
* You can set a FTP account for each branch or just change the folder for instance
* You can only use one slug config for now
*
* the rest is self-explanatory
*/
return array(
    'repos' => array(
        array (
    		'repo_name'=>'test',
    		'branches'=>array(
                array(
    				'branch_name'=>'master',
    				'type'=>'ftp',
    				'ftp_host'=>'ftp.example.org',
    				'ftp_user'=>'example_username',
    				'ftp_pass'=>'example_password',
    				'ftp_path'=>'/prod/',
    			),
                array(
    				'branch_name'=>'beta',
    				'type'=>'ftp',
    				'ftp_host'=>'ftp.example.org',
    				'ftp_user'=>'example_username',
    				'ftp_pass'=>'example_password',
    				'ftp_path'=>'/dev/',
    			),
    		),        
        ),
    ),
    // Your BitBucket Credentials
    'bitbucket' => array(
        'username' => '',
        'password' => ''
    ),
)

?>