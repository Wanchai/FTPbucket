<?php

/**
 * FTPbucket - A PHP tool to sync your bitbucket repository with any FTP account
 *
 * @author Thomas Malicet
 */

class FTPbucket {

    private $ftp;
    private $bitbucket;
    private $repo;
    private $commits;
    private $files = array();
    private $payload;

    public function init($pl) {
        $this->payload = $pl;
        $this->load_config();
        $this->load_payload($pl);
        $this->load_files();
    }

    function load_files(){
    	$log_msg = '';

        $ftp = $this->get_ftpdata();

		$log_msg .= $this->log_it('Connecting branch '.$ftp['branch_name'].' to '.$ftp['ftp_host'].$ftp['ftp_path'],false);

        // Makes a nice path
		if(substr($ftp['ftp_path'],0,1)!='/') $ftp['ftp_path'] = '/'.$ftp['ftp_path'];
		if(substr($ftp['ftp_path'], strlen($ftp['ftp_path'])-1,1)!='/') $ftp['ftp_path'] = $ftp['ftp_path'].'/';

		$conn_id = ftp_connect($ftp['ftp_host']);
        if(!@ftp_login($conn_id, $ftp['ftp_user'], $ftp['ftp_pass'])){
            $this->error('error: Connection failed!');
		}else{
            foreach($this->commits as $commit) {

                $node = $commit->node;
                $time = $commit->timestamp;

                foreach($commit->files as $file) {

        			if ($file->type=="removed") {
        			     // TODO: Check if file exists
        				if(@ftp_delete($conn_id, $ftp['ftp_path'].$file->file)) {
        				    $log_msg .= $this->log_it('Removed '.$ftp['ftp_path'].$file->file,false);
        				}
        			}else{
        				$url = "https://api.bitbucket.org/1.0/repositories".$this->repo->absolute_url."raw/".$node."/".$file->file;
        				$dirname = dirname($file->file);
        				$chdir = @ftp_chdir($conn_id, $ftp['ftp_path'].$dirname);
        				if($chdir == false){
        					if($this->make_directory($conn_id, $ftp['ftp_path'].$dirname)){
        						$log_msg .= $this->log_it('Created new directory '.$dirname,false);
        					} else {
        						$log_msg .= $this->log_it('Error: failed to create new directory '.$dirname,false);
        					}
        				}
        				$ch = curl_init($url);
        				curl_setopt($ch, CURLOPT_USERPWD, $this->bitbucket['username'].':'.$this->bitbucket['password']);
        				curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        				curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);

        				$data = curl_exec($ch);

        				curl_close($ch);

        				$temp = tmpfile();
        				fwrite($temp, $data);
        				fseek($temp, 0);

        				ftp_fput($conn_id, $ftp['ftp_path'].$file->file, $temp, FTP_BINARY);

        				fclose($temp);

        				$log_msg .= $this->log_it('Uploaded: '.$ftp['ftp_path'].$file->file,false);
        			}
                }
    		}
    		ftp_close($conn_id);

        	$this->log_msg($log_msg);
		}
    }
    function load_config(){
        $config = include 'config.php';

        $this->ftp = $config['repos'];
        $this->bitbucket = $config['bitbucket'];
    }

    function load_payload($payload) {
    	$data = json_decode(stripslashes($payload));

    	$this->repo = $data->repository;
    	$this->commits = $data->commits;
    }

    function get_ftpdata() {
        $repo = $this->get_repo();
        // Returns the branch ftp config related to the commit 
        return $this->get_branch($repo);
    }

    function get_repo(){
        foreach ($this->ftp as $repo) {
            // check if the repo from BB match one of yours
            if($this->repo->slug == $repo['repo_name']) return $repo;
        }
        $this->error('error: Can\'t find any repo with the name {'.$this->repo->slug.'} in your config file');
    }
    
    function get_branch($repo){
        foreach($this->commits as $commit) {

            // For several commits, only the last one has the branch name, the others null
            if($commit->branch != null){
        
                foreach ($repo['branches'] as $branch) {
        
                    // Checks if you have a config for BB's branch
                    if($branch['branch_name'] == $commit->branch) return $branch;
                }
            }
        }
        $this->error('error: Can\'t find a branch {'.$br.'} on repo {'.$repo['repo_name'].'}');
    }

    function make_directory($ftp_stream, $dir){
    	if ($this->ftp_is_dir($ftp_stream, $dir) || @ftp_mkdir($ftp_stream, $dir)) return true;
    	if (!$this->make_directory($ftp_stream, dirname($dir))) return false;
    	return ftp_mkdir($ftp_stream, $dir);
    }

    function ftp_is_dir($ftp_stream, $dir){
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
    function log_it($text,$save=true) {
    	$msg = date("d.m.Y, H:i:s",time()) .': '.$text."\n";

    	if(!$save){
    		return $msg;
    	}else{
    		$this->log_msg($msg);
    	}
    }

    // Appends to log file
    function log_msg($text) {
    	$logdatei=fopen("logfile.txt","a");
    	fputs($logdatei,$text);
    	fclose($logdatei);
    }
    
    // Log the payload from bitbucket
    function log_payload($text) {
    	$logdatei = fopen("logpayload.txt","a");
    	fputs($logdatei,$text);
    	fclose($logdatei);
    }
}
?>