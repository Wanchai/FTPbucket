FTPbucket
=========

FTPbucket is a PHP script that enables you to sync your BitBucket (and soon Github) repository with any FTP account.

**- release notes -**
		
**v0.6**
If you are updating, you should go for a fresh install. Erase everything except the .txt files if you want to keep your logs.

- Now compatible with both Webhooks and POST hooks from BitBucket
- huge changes in order to support Github hooks in the future (soon!!!)

INSTALLATION
------------

- Edit the config file and rename it to 'config.php'
- Copy the deploy folder on your FTP server
- On Bitbucket repositorie page, go to Settings>Webhooks>Add webhook and setup a hook pointing to http://myserver/deploy/deploy.php with "Triggers > Repository push"

Note: POST hooks are still supported by FTPbucket (it's detected automatically).
 
LOGS
-----
You can see and clear the logs by connecting to http://myserver/deploy/ 
You have to setup a password in the config file.

LIMITATIONS
-----------

1. The script only copies the files you are pushing. It means that if you start with this tool when you already have files in your Bitbucket repo, they won't be copied on the server. I'm looking for solutions on a full deploy. Which brings me the second point.
2. I tried to push a 160Mo repo with more than 26 000 files and the hook didn't like it. The limit is 1000 files/push I think. It's an unsolved issue: https://bitbucket.org/site/master/issue/7439/git-post-commit-hook-payloads-has-empty

SOLUTION : When you create a new repo on BB and need to push a lot of files, just do it. Right after, you set up the hook and manually copy the repo and FTPbucket files on your FTP.

MORE
----

It should work with Mercurial too but it's not tested yet.

I'm sure a lot of improvements can be made to my code so don't hesitate to fork and improve it! I would be glad to hear about your tests and issues too.

TODO
----

- Add support for SSH, Github...

LICENCE
-------
Copyright (c) 2014-2015 [Thomas Malicet](http://www.thomasmalicet.com/)

This code is an open-sourced software licensed under the [BEER-WARE LICENCE] (https://fedoraproject.org/wiki/Licensing/Beerware)