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
class BBjson
{
    // JSON parsed payload
    private $payload;

    /*
    Contains all usefull data for this push
    - auth: credentials for repo host (BitBucket, GitHub, ...) from config.php
    - branch: branch info from this push
    - -> ftp: FTP config for this branch
    - -> commits: all commits from this push
    - ---> files: list of files changed or removed
    */
    private $data;

    // Changes how often messages are written to the log
    private $msgIsSent = true;

    public function init($pl) {
        $this->payload = $pl;
        $this->log_it('Script called for a BitBucket JSON payload');

        $this->data = new stdClass();
        $this->load_datas();

        // echo "<pre>" . print_r($this->data, true ) . "</pre>";
        foreach ($this->data->branch as $br) {
            $this->set_wrapper($br);

            if( $br['created'] ){
                $this->initalize_files($br);
            } else {
                $this->load_files($br);
            }
        }
    }

    function load_datas() {
        if( !is_file( 'config.php' ) ) $this->error( 'Can\'t find config.php' );

        $config = include 'config.php';

        $pl_check = 0;
        $pl_repo_name = strtolower( $this->payload['repository']['full_name'] );

        // Loop through the repositories and find which repository configuration to use
        foreach( $config['repos'] as $repo ){
            if( $pl_repo_name == strtolower( $repo['repo_owner'] . '/' . $repo['repo_name'] ) && $repo['repo_host'] == 'bitbucket' ) {
                // Set the FTP information up. We check later to make sure there is only one configuration so this is fine to always be the last configuration found
                $this->data->ftp = $repo;
                $pl_check++;
            }
        }
        
        if ($pl_check == 0) {
            $this->error('error: Can\'t find any repo with the name {' . $pl_repo_name . '} for BitBucket in your config file.');
        } else if ($pl_check > 1) {
            $this->error('error: There is more than one repo with the name {' . $pl_repo_name . '} in your config file for the same repo host. And it\'s confusing!');
        } else {
            $this->log_it('Received a push from {' . $pl_repo_name . '}');
        }

        // We know this repository is a BitBucket one so lets set the auth to reflect that
        $this->data->auth = $config['bitbucket'];

        // Just do a quick check to see if any changes were pushed
        if (count($this->payload['push']['changes']) === 0) $this->error('No changes detected');

        // Okay, now we know which repository configuration to use lets grab the branch configuration
        foreach ( $this->payload['push']['changes'] as $change ){
            $br_check = 0;
            if( $change['new'] == null ){
                $this->log_it( 'The branch {' . $change['old']['name'] . '} was deleted. Not sure what to do with the files so they have not been touched.' );
                continue;
            }
            $branch_name = $change['new']['name'];
            $this->data->branch[$branch_name]['name'] = $branch_name;
            $this->data->branch[$branch_name]['changes'] = [];
            $this->data->branch[$branch_name]['created'] = $change['old'] == null && isset( $change['created'] );

            foreach ( $this->data->ftp['branches'] as $br ){
                if( $br['branch_name'] == $branch_name ){
                    $this->data->branch[$branch_name]['ftp'] = $br;
                    $br_check++;
                }
            }

            if ($br_check == 0) {
                $this->error('error: Can\'t find any branch with the name {' . $branch_name . '} for the repo {' . $this->data->ftp['repo_name'] . '}');
            } else if ($br_check > 1) {
                $this->error('error: There is more than one branch with the name {' . $branch_name . '} in your config file for the repo {' . $this->data->ftp['repo_name'] . '}. And it\'s confusing!');
            } else {
                $this->log_it('Modifications detected on the branch {' . $branch_name . '}');
            }


            $results = [];

            // Check if we are initalizing a new branch
            if( $this->data->branch[$branch_name]['created'] ){
                $this->log_it('Creating new branch.' );
                $url = 'https://bitbucket.org/api/2.0/repositories/' . $this->payload['repository']['full_name'] . '/src/' . $change['new']['target']['hash'] . '/';
            } else {
                $this->log_it('Updating existing branch.' );
                // The initial URL we are going to grab changes from
                $url = 'https://api.bitbucket.org/2.0/repositories/' . $this->payload['repository']['full_name'] . '/diffstat/' . $change['old']['target']['hash'] . '..' .  $change['new']['target']['hash'];
                $this->data->branch[$branch_name]['changes'] = [];
            }

            $has_next_page = true;

            do {
                $ch = curl_init($url);
                // curl_setopt($ch, CURLOPT_HEADER, 1);
                curl_setopt($ch, CURLOPT_USERPWD, $this->data->auth['username'] . ':' . $this->data->auth['password']);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
                $data = curl_exec($ch);
                $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

                if ($http_code != 200) {
                    $this->error( 'Cant\'t get lists of files! cURL error: ' . curl_error($ch) . ' HTTP_CODE:' . $http_code );
                } else {
                    $results = json_decode($data, true);
                    if (isset($results["error"]))
                        $this->error("cURL error: " . print_r($results, true));
                }
                curl_close($ch);

                // When updating a branch
                $this->data->branch[$branch_name]['changes'] = array_merge( $this->data->branch[$branch_name]['changes'], $results['values'] );

                // If a next key is returned that means there are more results so grab them
                $has_next_page = ( isset( $results['next'] ) && $url = $results['next'] );
            } while ( $has_next_page );

        }
    }

    function initalize_files($br) {
        $msg = "";
        $this->log_it('Commencing transfer for branch {' . $br['name'] . '}' );
        foreach ( $br['changes'] as $change ) {
            $msg .= $this->log_it( $this->upload_file( $change['path'], $change['links']['self']['href'] ), !$this->msgIsSent );
        }

        if( !$this->msgIsSent )
            $this->log_msg( $msg );

        $this->log_it("Transfer done for branch {" . $br['name'] . "}" . PHP_EOL);
    }

    function load_files($br) {
        $wrapper = $this->get_wrapper();

        $this->log_it('Commencing transfer for branch {' . $br['name'] . '}' );

        $msg = "";
        var_dump($br['changes']);

        foreach ( $br['changes'] as $change ) {
            if ( $change['status'] == 'removed' ) {
                if (@unlink($wrapper . $change['old']['path'])) {
                    $msg .= $this->log_it( 'Removed ' . $ftp['ftp_path'] . $change['old']['path'], $this->msgIsSent );
                } else {
                    $msg .= $this->log_it( 'Error while removing: ' . $ftp['ftp_path'] . $change['old']['path'], $this->msgIsSent );
                }
            } elseif ( $change['status'] == 'added' ) {
                $upload_msg = $this->upload_file( $change['new']['path'], $change['new']['links']['self']['href'] );
                if( !$this->msgIsSent )
                    $msg .= $upload_msg;
            } elseif ( $change['status'] == 'modified' ) {
                $msg .= $this->log_it('I have no idea what to do with the status {' . $change['status'] . '}. Skipping {' . $change['path'] . '}', $this->msgIsSent );
            } elseif ( $change['status'] == 'renamed' ) {
                $msg .= $this->log_it('I have no idea what to do with the status {' . $change['status'] . '}. Skipping {' . $change['path'] . '}', $this->msgIsSent );
            } else {
                $msg .= $this->log_it('I have no idea what to do with the status {' . $change['status'] . '}. Skipping {' . $change['path'] . '}', $this->msgIsSent );
            }

            // if( $change['status'] == 'removed' ) {
            //     if (@unlink($wrapper . $file['file'])) {
            //         $msg .= $this->log_it('Removed ' . $ftp['ftp_path'] . $file['file'], false);
            //     } else {
            //         $msg .= $this->log_it('Error while removing: ' . $ftp['ftp_path'] . $file['file'], false);
            //     }
            // }
        }

        // Log everything in one bulk shot
        if (!$this->msgIsSent) $this->log_msg( $msg );
        $this->log_it("Transfer done for branch {" . $br['name'] . "}" . PHP_EOL);

    }

    function set_wrapper ($br){
        $ftp = $br['ftp'];

        if ($ftp['type'] == 'ssh' && !function_exists('ssh2_connect'))
            $this->error('error: You don\'t have SSH capabilities on this server. You must install the SSH2 extension available from PECL');

        // Makes a nice path
        if (substr($ftp['ftp_path'], 0, 1) != '/' && $ftp['type'] != 'none') $ftp['ftp_path'] = '/' . $ftp['ftp_path'];
        if (substr($ftp['ftp_path'], strlen($ftp['ftp_path']) - 1, 1) != '/') $ftp['ftp_path'] = $ftp['ftp_path'] . '/';

        $ftp['ftp_pass'] = urlencode($ftp['ftp_pass']);
        // --- Check Connection --- //
        switch ($ftp['type']) {
            case 'ftp':
                $wrapper = 'ftp://' . $ftp['ftp_user'] . ':' . $ftp['ftp_pass'] . '@' . $ftp['ftp_host'] . $ftp['ftp_path'];
                break;
            case 'ssh':
                $wrapper = 'ssh2.sftp://' . $ftp['ftp_user'] . ':' . $ftp['ftp_pass'] . '@' . $ftp['ftp_host'] . $ftp['ftp_path'];
                break;
            case 'none':
                $wrapper = $ftp['ftp_path'];
                break;
            default:
                $this->error('error: ' . strtoupper($ftp['type']) . ' Connection type not reconized!');
                break;
        }
        $this->data->wrapper = $wrapper;
    }

    function get_wrapper () {
        return $this->data->wrapper;
    }

    function upload_file ($path, $href){
        $dirname = dirname($path);
        $wrapper = $this->get_wrapper();
        $msg = "";

        if (!is_dir($wrapper . $dirname)) {
            if (@mkdir($wrapper . $dirname, 0755, true)) {
                $msg .= $this->log_it('Created new directory ' . $dirname, $this->msgIsSent);
            } else {
                $msg .= $this->log_it('Error: failed to create new directory ' . $dirname, $this->msgIsSent);
            }
        }

        // Todo http://arguments.callee.info/2010/02/21/multiple-curl-requests-with-php/
        //$url = 'https://api.bitbucket.org/1.0/repositories/' . $this->data->ftp['full_name'] . '/raw/' . $node . '/' . $file['file'];

        // $url = 'https://api.bitbucket.org/2.0/repositories/' . $this->data->ftp['full_name'] . '/src/' . $node . '/' . $file['file'];

        $url = $href;

        $cu = curl_init($url);
        curl_setopt($cu, CURLOPT_USERPWD, $this->data->auth['username'] . ':' . $this->data->auth['password']);
        curl_setopt($cu, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($cu, CURLOPT_FOLLOWLOCATION, false);
        $data = curl_exec($cu);

        /**
         * Check for HTTP return status instead of the data that is returned from
         * CURL. This ensures that even an empty file will be transfered properly.
         */
        $http_code = curl_getinfo($cu, CURLINFO_HTTP_CODE);
        if ($http_code != 200) {
            $msg .= $this->log_it('Cant\'t get the file ' . $path . ' - error code : ' . $http_code . ' - cURL error: ' . curl_error($cu), false);
        } else {
            if (file_put_contents($wrapper . $path, $data, 0, stream_context_create(array('ftp' => array('overwrite' => true))))) {
                $msg .= $this->log_it('Uploaded: ' . $path, $this->msgIsSent);
            } else {
                $e = error_get_last();
                $msg .= $this->log_it('Error Uploading ' . $path . ' >> ' . $e['message'], $this->msgIsSent);
            }
        }
        curl_close($cu);

        return $msg;
    }

    /*
     * LOGGING FUNCTIONS
     */
    function error($text)
    {
        $this->log_it($text);
        $this->log_payload($this->log_it(print_r($this->payload, true), false));
        die();
    }

    // Formats $text for login
    // Appends to log file if save == true
    function log_it($text, $save = true)
    {
        $msg = date("d.m.Y, H:i:s", time()) . ': ' . $text . PHP_EOL;

        if (!$save) {
            return $msg;
        } else {
            $this->log_msg($msg);
        }
    }

    // Appends to log file
    function log_msg($text)
    {
        // For testing delay the script
        sleep(1);
        $logdatei = fopen("logfile.txt", "a");
        fputs($logdatei, $text);
        fclose($logdatei);
    }

    // Log the received payload
    function log_payload($text)
    {
        $logdatei = fopen("logpayload.txt", "a");
        fputs($logdatei, $text);
        fclose($logdatei);
    }
}