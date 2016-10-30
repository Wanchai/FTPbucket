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
class GHjson
{
    // JSON parsed payload
    private $payload;

    /**
     * Contains every usefull datas
     * - auth: credentials for repo host (BB, GH, ...) from config.php
     * - branch: branch info from this push
     * - -> ftp: FTP config for this branch
     * - -> commits: all commits from this push
     * - ---> files: list of files changed or removed
     */
    private $data;

    public function init($pl)
    {
        $this->payload = $pl;
        $this->log_it('Script called for a GitHub payload');

        $this->load_datas();
        foreach ($this->data->branch as $br) {
            $this->load_files($br);
        }
    }

    function load_files($br)
    {
        $ftp = $br['ftp'];

        if ($ftp['type'] == 'ssh' && !function_exists('ssh2_connect'))
            $this->error('error: You don\'t have SSH capabilities on this server. You must install the SSH2 extension available from PECL');

        // Makes a nice path
        if (substr($ftp['ftp_path'], 0, 1) != '/' && $ftp['type'] != 'none') $ftp['ftp_path'] = '/' . $ftp['ftp_path'];
        if (substr($ftp['ftp_path'], strlen($ftp['ftp_path']) - 1, 1) != '/') $ftp['ftp_path'] = $ftp['ftp_path'] . '/';

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

        foreach ($br['commits'] as $commit) {
            $node = $commit ['id'];

            foreach ($commit['removed'] as $file) {
                if (@unlink($wrapper . $file)) {
                    $this->log_it('Removed ' . $ftp['ftp_path'] . $file);
                } else {
                    $this->log_it('Error while removing: ' . $ftp['ftp_path'] . $file);
                }
            }

            $files = array_merge($commit['added'], $commit['modified']);
            foreach ($files as $file) {
                $dirname = dirname($file);

                if (!is_dir($wrapper . $dirname)) {
                    if (mkdir($wrapper . $dirname, 0705, true)) {
                        $this->log_it('Created new directory ' . $dirname);
                    } else {
                        $this->log_it('Error: failed to create new directory ' . $dirname);
                    }
                }

                $url = 'https://raw.githubusercontent.com/' . $this->data->ftp["full_name"] . '/' . $node . '/' . $file;

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
                    $this->log_it('Cant\'t get the file ' . $file . ' cURL error: ' . curl_error($cu));
                } else if (curl_getinfo($cu, CURLINFO_HTTP_CODE) == 404) {
                    $this->log_it('Cant\'t get the file ' . $file . ' : 404');
                } else {
                    if (file_put_contents($wrapper . $file, $data, 0, stream_context_create(array('ftp' => array('overwrite' => true))))) {
                        $this->log_it('Uploaded: ' . $ftp['ftp_path'] . $file);
                    } else {
                        $e = error_get_last();
                        $this->log_it('Error Uploading ' . $file . ' >> ' . $e['message']);
                    }
                }
                curl_close($cu);
            }
        }
        $this->log_it("Transfer done for branch {" . $br['name'] . "}\n");
    }

    function load_datas()
    {
        if (!is_file('config.php')) $this->error('Can\'t find config.php');

        $config = include 'config.php';

        // --- Checks if the repo from BB match one of yours --- //
        $check = 0;
        $pl_repo_name = $this->payload['repository']['name'];

        foreach ($config['repos'] as $repo) {
            if (strtolower($pl_repo_name) == strtolower($repo['repo_name']) && $repo['repo_host'] == 'github') {
                // --- FTP config from config.php --- //
                $this->data->ftp = $repo;
                $check++;
            }
        }

        if ($check == 0) {
            $this->error('error: Can\'t find any repo with the name {' . $pl_repo_name . '} for GitHub in your config file.');
        } else if ($check > 1) {
            $this->error('error: There is more than one repo with the name {' . $pl_repo_name . '} in your config file for the same repo host. And it\'s confusing!');
        } else {
            $this->log_it('Received a push from {' . $pl_repo_name . '}');
        }

        $this->data->ftp["full_name"] = $this->payload['repository']['full_name'];

        // --- Required for Authentication --- //
        $this->data->auth = $config['github'];

        // --- Gets the commits and changes --- //

        // --- The Branch --- //
        $chck = 0;
        $branch_name = explode('/', $this->payload['ref']);
        $branch_name = $branch_name[count($branch_name) - 1];
        $this->data->branch[$branch_name]['name'] = $branch_name;
        $this->data->branch[$branch_name]['commits'] = array();

        foreach ($this->data->ftp['branches'] as $br) {
            if ($br['branch_name'] == $branch_name) {
                $this->data->branch[$branch_name]['ftp'] = $br;
                $chck++;
            }
        }
        if ($check == 0) {
            $this->error('error: Can\'t find any branch with the name {' . $branch_name . '} for the repo {' . $this->data->ftp['repo_name'] . '}');
        } else if ($check > 1) {
            $this->error('error: There is more than one branch with the name {' . $branch_name . '} in your config file for the repo {' . $this->data->ftp['repo_name'] . '}. And it\'s confusing!');
        }

        // --- The Commits --- //
        foreach ($this->payload['commits'] as $cmt) {
            $this->data->branch[$branch_name]['commits'][intval(strtotime($cmt['timestamp']))] = $cmt;
        }
        ksort($this->data->branch[$branch_name]['commits']);
    }

    /*
    * LOGGING FUNCTIONS
    */
    function error($text)
    {
        $this->log_it($text);
        $this->log_payload($this->log_it($this->payload, false));
        die();
    }

    // Formats $text for login
    // Appends to log file if save == true
    function log_it($text, $save = true)
    {
        $msg = date("d.m.Y, H:i:s", time()) . ': ' . $text . "\n";

        if (!$save) {
            return $msg;
        } else {
            $this->log_msg($msg);
        }
    }

    // Appends to log file
    function log_msg($text)
    {
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