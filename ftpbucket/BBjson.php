<?php

class BBjson
{
    // JSON parsed payload
    private $payload;

    private $curl_calls = 0;

    private $rate_limits_hit = 0;

    /*
    Contains every usefull datas
    - auth: credentials for repo host ( BB, GH, ... ) from config.php
    - branch: branch info from this push
    - -> ftp: FTP config for this branch
    - -> commits: all commits from this push
    - ---> files: list of files changed or removed
    */
    private $config;

    public function init ( $payload ) {
        $this->payload = $payload;
        $this->config = new stdClass(); 

        $this->log_it ( 'Script called for BitBucket JSON payload' ); 

        if ( count ( $this->payload['push']['changes'] ) === 0 ) 
            $this->error ( 'No changes detected' ); 

        $this->load_config();

        foreach ( $this->config->branch as $branch_name => $branch_config ) {
            $this->load_changed_files ( $branch_config ); 
            $this->log_it ( "Transfer done for branch {" . $branch_name . "}");
        }
        $this->log_it ( "Push completed. There were {" . $this->curl_calls . "} total CURL calls made." . PHP_EOL );
    }

    private function load_config() {

        if ( !is_file ( 'config.php' ) ) $this->error ( 'Can\'t find config.php' ); 
        $config = include 'config.php';

        // Make sure we only get 1 configuration
        $check = 0;
        $payload_repo_name = $this->payload['repository']['full_name'];

        // --- Checks if the repo from BB match one of yours --- //
        // Loop through configs and find a matching one
        foreach ( $config['repos'] as $repo ) {
            if ( strpos($payload_repo_name, $repo['repo_name']) !== false && $repo['repo_host'] == 'bitbucket' ) {
                $this->config->ftp = $repo;
                $check++;
            }
        }

        if ( $check == 0 ) {
            $this->error ( 'error: Can\'t find any repo with the name {' . $payload_repo_name . '} for BitBucket in your config file.' ); 
        } else if ( $check > 1 ) {
            $this->error ( 'error: There is more than one repo with the name {' . $payload_repo_name . '} in your config file. And it\'s confusing!' ); 
        } else {
            $this->log_it ( 'Received a push from {' . $payload_repo_name . '}' ); 
        }

        // Set the full name 
        $this->config->ftp["full_name"] = $this->payload['repository']['full_name'];

        // Setup the authentication
        $this->config->auth = $config['bitbucket'];

        // Multiple changes happen when pushing multiple branches at once
        foreach ( $this->payload['push']['changes'] as $change ) {
            $check = 0;
            $branch_name = $change['new']['name'];

            // $branch_name might be null if the branch was deleted. If that is the case just log it.
            if ( is_null ( $branch_name ) ){
                $this->log_it ( 'The branch {' . $change['old']['name'] . '} was deleted.' );
                continue;
            }

            $this->config->branch[$branch_name]['name'] = $branch_name;
            $this->config->branch[$branch_name]['changes'] = array(); 

            foreach ( $this->config->ftp['branches'] as $br ) {
                if ( $br['branch_name'] == $branch_name ) {
                    $this->config->branch[$branch_name]['ftp'] = $br;
                    $this->config->branch[$branch_name]['changes'][] = $change;

                    $check++;
                }
            }

            if ( $check == 0 ) {
                $this->error ( 'error: Can\'t find any branch with the name {' . $branch_name . '} for the repo {' . $this->config->ftp['repo_name'] . '}' ); 
            } else if ( $check > 1 ) {
                $this->error ( 'error: There is more than one branch with the name {' . $branch_name . '} in your config file for the repo {' . $this->config->ftp['repo_name'] . '}. And it\'s confusing!' ); 
            } else {
                $this->log_it ( 'Modifications detected on the branch {' . $branch_name . '}' ); 
            }
        }
    }

    private function load_changed_files ( $branch_config ) {
        $repo_full_name = $this->payload['repository']['full_name'];

        $ftp = $branch_config['ftp'];

        if ( $ftp['type'] == 'ssh' && !function_exists ( 'ssh2_connect' ) ) 
            $this->error ( 'error: You don\'t have SSH capabilities on this server. You must install the SSH2 extension available from PECL' ); 

        // Makes a nice path
        if ( substr ( $ftp['ftp_path'], 0, 1 ) != '/' && $ftp['type'] != 'none' ) $ftp['ftp_path'] = '/' . $ftp['ftp_path'];
        if ( substr ( $ftp['ftp_path'], strlen ( $ftp['ftp_path'] ) - 1, 1 ) != '/' ) $ftp['ftp_path'] = $ftp['ftp_path'] . '/';

        // --- Check Connection --- //
        switch ( $ftp['type'] ) {
            case 'ftp':
                // decode to prevent double encoding then encode because special characters can't be in a url
                $ftp['ftp_user'] = urlencode ( urldecode ( $ftp['ftp_user'] ) ); 
                $ftp['ftp_pass'] = urlencode ( urldecode ( $ftp['ftp_pass'] ) ); 

                $wrapper = 'ftp://' . $ftp['ftp_user'] . ':' . $ftp['ftp_pass'] . '@' . $ftp['ftp_host'] . $ftp['ftp_path'];
                break;
            case 'ssh':
                $wrapper = 'ssh2.sftp://' . $ftp['ftp_user'] . ':' . $ftp['ftp_pass'] . '@' . $ftp['ftp_host'] . $ftp['ftp_path'];
                break;
            case 'none':
                $wrapper = $ftp['ftp_path'];
                break;
            default:
                $this->error ( 'error: ' . strtoupper ( $ftp['type'] ) . ' Connection type not reconized!' ); 
                break;
        }

        foreach ( $branch_config['changes'] as $index => $change ) {
            $msgIsSent = true;
            $message = "";

            $this->log_it ( 'Commencing transfer for change ' . ( $index + 1 ) );

            $old_hash = isset( $change['old']['target']['hash'] ) ? $change['old']['target']['hash'] : false;
            $new_hash = isset( $change['new']['target']['hash'] ) ? $change['new']['target']['hash'] : false;

            if ( $old_hash && $new_hash ){
                $url = sprintf ( 'https://bitbucket.org/api/2.0/repositories/%s/diffstat/%s..%s', $repo_full_name, $new_hash, $old_hash );
                $latest_hash = $new_hash;
            } elseif( $old_hash || $new_hash ) {
                $latest_hash = $old_hash ? $old_hash : $new_hash;
                $url = sprintf ( 'https://bitbucket.org/api/2.0/repositories/%s/diffstat/%s', $repo_full_name, $latest_hash );
            } else {
                $this->log_it( 'Neither an old hash or new hash could be found. No idea what to do.');
                continue;
            }

            $files_changed = $this->api_get_values ( $url, $branch_config['name'] );

            if ( !$files_changed ){
                $this->log_it( 'No file changes found.' );
                continue;
            }


            $file_index = 0;
            $total_files = count( $files_changed );
            $this->log_it ( $total_files . ' files changed.' );

            do {
                $file = $files_changed[$file_index];

                $file['new'] = $this->fix_bitbucket_links_hash_commit_id( $file['new'], $latest_hash );

                $file_path = $file['status'] == "removed" ? $file['old']['path'] : $file['new']['path'];

                if ( $file['status'] == "removed" ) {
                    if ( @unlink ( $wrapper . $file_path ) ) {
                        $message .= $this->log_it ( 'Removed ' . $ftp['ftp_path'] . $file_path, $msgIsSent ); 
                    } else {
                        $message .= $this->log_it ( 'Error while removing: ' . $ftp['ftp_path'] . $file_path, $msgIsSent ); 
                    }
                } else {
                    $dirname = dirname ( $file_path ); 

                    if ( !is_dir ( $wrapper . $dirname ) ) {
                        if ( file_exists ( $wrapper . $dirname ) ) {
                            $message .= $this->log_it ( 'Couldn\'t create directory as a file named {' . $dirname . '} already exists.', $msgIsSent ); 
                        }
                        elseif ( mkdir ( $wrapper . $dirname, 0705, true ) ) {
                            $message .= $this->log_it ( 'Created new directory ' . $dirname, $msgIsSent ); 
                        } else {
                            $message .= $this->log_it ( 'Error: failed to create new directory ' . $dirname, $msgIsSent ); 
                        }
                    }

                    // Todo http://arguments.callee.info/2010/02/21/multiple-curl-requests-with-php/
                    $url = fix_bitbucket_api_base_url($file['new']['links']['self']['href']);

                    $cu = curl_init ( $url ); 
                    curl_setopt ( $cu, CURLOPT_USERPWD, $this->config->auth['username'] . ':' . $this->config->auth['password'] ); 
                    curl_setopt ( $cu, CURLOPT_RETURNTRANSFER, true ); 
                    curl_setopt ( $cu, CURLOPT_FOLLOWLOCATION, false ); 
                    $data = curl_exec ( $cu );
                    $this->curl_calls++;

                    /**
                     * Check for HTTP return status instead of the data that is returned from
                     * CURL. This ensures that even an empty file will be transfered properly.
                     */
                    $http_code = curl_getinfo ( $cu, CURLINFO_HTTP_CODE );
                    if ( $http_code == 429 ) {
                        $this->handle_rate_limit();

                        // Break the do loop here so we don't go on to the next file until we can fetch this one.
                        continue;
                    } elseif ( $http_code != 200 ) {
                        $message .= $this->log_it ( 'Cant\'t get the file ' . $file_path . ' - error code : ' . $http_code . ' - cURL error: ' . curl_error ( $cu ) , $msgIsSent ); 
                    } else {
                        if ( file_put_contents ( $wrapper . $file_path, $data, 0, stream_context_create ( array ( 'ftp' => array ( 'overwrite' => true ) ) ) ) ) {
                            $message .= $this->log_it ( 'Uploaded: ' . $ftp['ftp_path'] . $file_path, $msgIsSent ); 
                        } else {
                            $e = error_get_last(); 
                            $message .= $this->log_it ( 'Error Uploading ' . $ftp['ftp_path'] . $file_path . ' >> ' . $e['message'], $msgIsSent ); 
                        }
                    }
                    curl_close ( $cu );
                }

                // Increment the file index so we continue the loop.
                $file_index++;
            } while( $file_index < $total_files );

            if ( !$msgIsSent ) {
                $this->log_it( $message );
            }
        }
    }

    private function fix_bitbucket_api_base_url($url)
    {
        if (!preg_match("/^https:\/\/bitbucket.org\/!api/", $url)) {
            return $url;
        }

        return str_replace("https://bitbucket.org/!api", "https://bitbucket.org/api", $url);
    }

    private function api_get_values ( $url, $branch ) {

        if( empty( $branch ) ){
            return false;
        }
        
        $has_more = true;
        $all_results = [];
        
        $loop_counter = 0;

        // Loop through until there are no more pages of results
        do {
            $ch = curl_init ( $url ); 
            curl_setopt ( $ch, CURLOPT_USERPWD, $this->config->auth['username'] . ':' . $this->config->auth['password'] ); 
            curl_setopt ( $ch, CURLOPT_RETURNTRANSFER, true ); 
            curl_setopt ( $ch, CURLOPT_FOLLOWLOCATION, false ); 
            $data = curl_exec ( $ch );
            $this->curl_calls++;

            /**
             * Check for HTTP return status instead of the data that is returned from
             * CURL. This ensures that even an empty file will be transfered properly.
             */
            $http_code = curl_getinfo ( $ch, CURLINFO_HTTP_CODE ); 

            // First check for rate limiting
            if ( $http_code == 429 ) {
                $this->handle_rate_limit();
                continue;
            } else if ( $http_code != 200 ) {
                $this->error ( 'Cant\'t get lists of commits! Request URL: ' . $url . ' Loop count: ' . $loop_counter . ' HTTP_CODE: ' . $http_code . ' cURL error: ' . curl_error ( $ch ) ); 
            } else {
                $arr = json_decode ( $data, true ); 
                if ( isset ( $arr["error"] ) ) 
                    $this->error ( "Loop count: ' . $loop_counter . ' cURL error: " . $data ); 
            }
            $result = json_decode ( $data, true ); 
            
            $has_more = isset ( $result['next'] ) && !empty ( $result['next'] ); 
            
            if ( $has_more ) {
                $url = $result['next'];
            }

            // Make sure decoding worked
            if ( !is_null ( $result ) && is_array ( $result['values'] ) ) {
                $all_results = array_merge ( $all_results, $result['values'] ); 
            }
            $loop_counter++;
        } while ( $has_more ); 
        
        return $all_results;
    }


    // Reported here: https://bitbucket.org/site/master/issues/18034/incorrect-diffstat-api-result-for-changed
    private function fix_bitbucket_links_hash_commit_id ( $commit_object, $latest_commit_hash ) {
        if ( !is_array ( $commit_object ) || empty ( $latest_commit_hash ) ) {
            return $commit_object;
        }

        $path = $commit_object['path'];
        
        foreach ( $commit_object['links'] as &$type ) {
            if( ! isset( $type['href'] ) )
                continue;
            $type['href'] = preg_replace (
                sprintf ( '#/[a-z0-9]+/%s#', $path ),
                sprintf( '/%s/%s', $latest_commit_hash, $path),
                $type['href']
           );
        }
        return $commit_object;
    }

    private function handle_rate_limit(){
        $this->rate_limits_hit++;

        // Increase sleep time each time we hit the rate limit so we don't hammer the server
        $sleep_time = pow( ( $this->rate_limits_hit / 2 ) , 2 ) + 1;

        $message .= $this->log_it (
            sprintf( 'Waiting %s seconds because we hit a rate limit. We have hit the rate limit %d time(s) so far.',
                $sleep_time,
                $this->rate_limits_hit
            ),
            $msgIsSent
        );
        sleep( $sleep_time );
    }

    /**
     * LOGGING FUNCTIONS
     **/
    function error ( $text ) {
        $this->log_it ( $text ); 
        $this->log_payload ( $this->log_it ( json_encode ( $this->payload ) , false ) ); 
        die(); 
    }

    // Formats $text for login
    // Appends to log file if save == true
    function log_it ( $text, $save = true ) {
        $msg = date ( "d.m.Y, H:i:s", time() ) . ': ' . $text . PHP_EOL;
        if ( !$save ) {
            return $msg;
        } else {
            $this->log_msg ( $msg ); 
        }
    }

    // Appends to log file
    function log_msg ( $text ) {
        $fh = fopen ( "logfile.txt", "a" ); 
        fputs ( $fh, $text ); 
        fclose ( $fh ); 
    }

    // Log the received payload
    function log_payload ( $text ) {
        $fh = fopen ( "logpayload.txt", "a" ); 
        fputs ( $fh, $text ); 
        fclose ( $fh ); 
    }
}
