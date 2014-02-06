FTPbucket
=========


FTPbucket is a PHP script to sync your bitbucket repository with any FTP account

INSTALLATION
------------

- Edit the config file
- Copy the deploy folder on your FTP server.
- On Bitbucket>Admin>Hooks, setup a POST hook pointing to http://myserver/deploy/deploy.php

LIMITATIONS
-----------

- The script only copies the files you are pushing. Which means that if you start with this tool and you already have files in your Bitbucket repo, they won't be copied on the server. I'm looking for solutions on a full deploy. Which brings the second point.
- I tried to push a 160Mo repo with more than 26 000 files and the POST hook didn't work out very well. From the logs, I could see that Bitbucket sent an empty 'commits' Array in the POST request. The limit is 1000 files/push I think. It's an unsolved issue: https://bitbucket.org/site/master/issue/7439/git-post-commit-hook-payloads-has-empty

TODO
----

- Add a GUI
- Add a post-commit hook
- Add support for SSH, Github, Mercurial


LICENCE
-------
Copyright (c) 2014 [Thomas Malicet](http://www.thomasmalicet.com/)

This code is an open-sourced software licensed under the MIT license