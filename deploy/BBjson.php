<?php

/**
 * This class manages payloads received from a BitBucket webhooks (JSON)
 * 
 * "THE BEER-WARE LICENSE" (Revision 42): 
 * Thomas MALICET wrote this file. As long as you retain this notice you
 * can do whatever you want with this stuff. If we meet some day, and you think
 * this stuff is worth it, you can buy me a beer in return.
 * 
 * www.thomasmalicet.com
 */

class BBjson {
    
    // JSON parsed payload
    private $payload;
    
    /* 
    Contains every usefull datas 
    - auth: credentials for repo host (BB, GH, ...) from config.php
    - branch: branch info from this push
    - > commits: all commits from this push
    - - > files: list of files changed or removed
    - ftp: FTP config from config.php
    */
    private $data;

    public function init($pl) {
        $this->payload = $pl;
        $this->log_it('Script called for a BitBucket JSON payload');
        
        $this->load_datas();
        
        foreach($this->data->branch as $br)
        {
            $this->load_files($br);
        }
    }

    function load_files($br){

        $ftp = $br['ftp'];

    	$log_msg = '';
		$log_msg .= $this->log_it('Connecting branch '.$ftp['branch_name'].' to '.$ftp['ftp_host'],false);

        // Makes a nice path
		if (substr($ftp['ftp_path'],0,1)!='/') $ftp['ftp_path'] = '/'.$ftp['ftp_path'];
		if (substr($ftp['ftp_path'], strlen($ftp['ftp_path'])-1,1)!='/') $ftp['ftp_path'] = $ftp['ftp_path'].'/';

		$conn_id = ftp_connect($ftp['ftp_host']);
        if (!@ftp_login($conn_id, $ftp['ftp_user'], $ftp['ftp_pass'])) {
            $this->error('error: FTP Connection failed!');
		} else {
		    
		    ftp_pasv($conn_id, true);
		    
		    // TODO TODO
		    
            foreach ($br['commits'] as $commit) 
            {
                $node = $commit ['node'];
                
                foreach ($commit['files'] as $file) 
                {
        			if ($file['type'] == "removed") 
        			{
        				if (@ftp_delete($conn_id, $ftp['ftp_path'].$file['file'])) {
        				    $log_msg .= $this->log_it('Removed '.$ftp['ftp_path'].$file['type'], false);
        				} else {
                            $log_msg .= $this->log_it('Error while removing: '.$ftp['ftp_path'].$file['type'], false);
                        }
        			} 
        			else 
        			{
        				$dirname = dirname($file['file']);
        				$chdir = @ftp_chdir($conn_id, $ftp['ftp_path'].$dirname);
        				
        				if (!$chdir)
        				{
        					if ($this->make_directory($conn_id, $ftp['ftp_path'].$dirname)) {
    						    $log_msg .= $this->log_it('Created new directory '.$dirname,false);
        					} else {
    						    $log_msg .= $this->log_it('Error: failed to create new directory '.$dirname, false);
        					}
        				}

        				$url = 'https://api.bitbucket.org/1.0/repositories/'.$this->data->auth['username'].'/'.$this->data->ftp['repo_name'].'/raw/'.$node.'/'.$file['file'];
        				
        				$cu = curl_init($url);
        				curl_setopt($cu, CURLOPT_USERPWD, $this->data->auth['username'].':'.$this->data->auth['password']);
        				curl_setopt($cu, CURLOPT_RETURNTRANSFER, true);
        				curl_setopt($cu, CURLOPT_FOLLOWLOCATION, false);

        				$data = curl_exec($cu);
        				
        				if(!$data) 
        				{
        				    $log_msg .= $this->log_it('Cant\'t get the file '.$file['file'].' cURL error: '.curl_error($cu), false);
        				}
        				else
        				{
            				$temp = tmpfile();
            				fwrite($temp, $data);
            				fseek($temp, 0);
    
            				if(ftp_fput($conn_id, $ftp['ftp_path'].$file['file'], $temp, FTP_BINARY))
            				{
            				    $log_msg .= $this->log_it('Uploaded: '.$ftp['ftp_path'].$file['file'], false);
            				}
            				else
            				{
            				    $e = error_get_last();
            				    $log_msg .= $this->log_it('Error Uploading '.$file['file'].' >> '.$e['message'], false);
            				}
            				fclose($temp);
        				}
        				curl_close($cu);
        			}
                }
    		}
    		ftp_close($conn_id);
    		
    		$log_msg .= $this->log_it("Transfer done for branch {".$br['name']."}\n", false);

        	$this->log_msg($log_msg);
		}
    }
    
    function load_datas()
    {
        if (!is_file('config.php')) 
        {
            $this->error('Can\'t find config.php');
        } 

        $config = include 'config.php';
        
        // --- Checks if the repo from BB match one of yours --- //
        $check = 0;
        $pl_repo_name = $this->payload['repository']['name'];
        
        foreach ($config['repos'] as $repo) 
        {
            if(strtolower($pl_repo_name) == strtolower($repo['repo_name']) && $repo['repo_host'] == 'bitbucket')
            {
                $this->data->ftp = $repo;
                $check++;
            }
        }
        
        // $this->log_it(print_r($this->data->ftp, true));
        
        if($check == 0){
            $this->error('error: Can\'t find any repo with the name {'.$pl_repo_name.'} for BitBucket in your config file.');
        } else if($check > 1) {
            $this->error('error: There is more than one repo with the name {'.$pl_repo_name.'} in your config file for the same repo host. And it\'s confusing!');
        } else {
            $this->log_it('Received a push from {'.$pl_repo_name.'}');
        }
        // --- Required for Authentication --- // 
        $this->data->auth = $config['bitbucket'];
    	
    	// --- Gets the commits and changes --- //
    	foreach ($this->payload['push']['changes'] as $change)
    	{
    	    // --- The Branch --- //
    	    $chck = 0;
    	    $branch_name = $change['new']['name'];
    	    $this->data->branch[$branch_name]['name'] = $branch_name;
    	    $this->data->branch[$branch_name]['commits'] = array();
    	    
    	    foreach($this->data->ftp['branches'] as $br)
    	    {
    	        if($br['branch_name'] == $branch_name)
    	        {
    	            $this->data->branch[$branch_name]['ftp'] = $br;
    	            $chck++;
    	        }
    	    }
            if($check == 0){
                $this->error('error: Can\'t find any branch with the name {'.$branch_name.'} for the repo {'.$this->data->ftp['repo_name'].'}');
            } else if($check > 1) {
                $this->error('error: There is more than one branch with the name {'.$branch_name.'} in your config file for the repo {'.$this->data->ftp['repo_name'].'}. And it\'s confusing!');
            }   
            
    	    // --- The Commits --- //
    	    foreach ($change['commits'] as $cmt)
    	    {
                // --- Retrieve list of files --- //
                $url = 'https://bitbucket.org/api/1.0/repositories/'.$this->data->auth['username'].'/'.$this->data->ftp['repo_name'].'/changesets/'.$cmt['hash'].'/';
                
				$ch = curl_init($url);
				curl_setopt($ch, CURLOPT_USERPWD, $this->data->auth['username'].':'.$this->data->auth['password']);
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
				curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
				$data = curl_exec($ch);
				
    	        $commit = json_decode($data, true);
    	        $commit['hash'] = $cmt['hash'];
    	       // array_push($this->data->branch[$branch_name]['commits'], $commit);
    	       $this->data->branch[$branch_name]['commits'][intval(strtotime($commit['timestamp']))] = $commit;
    	    }
    	    ksort($this->data->branch[$branch_name]['commits']);
    	}

    //$this->log_it(print_r($this->data->branch['master'], true));
    }

    function get_branch($repo){
        foreach ($this->commits as $commit) {

            // For several commits, only the last one has the branch name, the others null
            if ($commit->branch != null){
        
                foreach ($repo['branches'] as $branch) {
        
                    // Checks if you have a config for BB's branch
                    if ($branch['branch_name'] == $commit->branch) return $branch;
                }
            }
        }
        $this->error('error: Can\'t find a branch {'.$br.'} on repo {'.$repo['repo_name'].'}');
    }

    function make_directory($ftp_stream, $dir)
    {
    	if ($this->ftp_is_dir($ftp_stream, $dir) || @ftp_mkdir($ftp_stream, $dir)) return true;
    	if (!$this->make_directory($ftp_stream, dirname($dir))) return false;
    	return ftp_mkdir($ftp_stream, $dir);
    }

    function ftp_is_dir($ftp_stream, $dir)
    {
    	$original_directory = ftp_pwd($ftp_stream);
    	if ( @ftp_chdir( $ftp_stream, $dir ) ) {
    		ftp_chdir( $ftp_stream, $original_directory );
    		return true;
    	} else {
    		return false;
    	}
    }

    /*
    * LOGGING FUNCTIONS
    */
    function error($text){
        $this->log_it($text);
        $this->log_payload($this->log_it($this->payload, false));
        die();
    }

    // Formats $text for login
    // Appends to log file if save == true
    function log_it($text, $save=true) {
    	$msg = date("d.m.Y, H:i:s",time()) .': '.$text."\n";

    	if (!$save) {
    		return $msg;
    	} else {
    		$this->log_msg($msg);
    	}
    }

    // Appends to log file
    function log_msg($text) {
    	$logdatei = fopen("logfile.txt","a");
    	fputs($logdatei,$text);
    	fclose($logdatei);
    }
    
    // Log the received payload
    function log_payload($text) {
    	$logdatei = fopen("logpayload.txt","a");
    	fputs($logdatei,$text);
    	fclose($logdatei);
    }
}
?>