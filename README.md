# zabbix-veeam-rest
Zabbix template and php script for monitoring Veeam B&R jobs and repositories.

## Now supports Veeam B&R 10 with HTTPS
The script was re-created from scratch, now it supports Veeam 10 with HTTPS API, implements OOP logic and good logging.

## How it works
It uses Veeam REST API: https://helpcenter.veeam.com/docs/backup/rest/overview.html?ver=100. JSON mode only is now supported, XML is not.

REST queries are sent from Zabbix server (or proxy), no scripts or zabbix agent setup is needed on Veeam host itself.

Developed and tested on **Zabbix 3.4.15** and **Veeam Backup & Replication 10**.

Discovered items:
* Backup jobs, backup copy jobs and agent backup jobs:
  * Last job result/duration/date (for each discovered job)
* Replication jobs:
  * Last SUCCESSFUL job result/date (for each discovered job)
* Backup repositories:
  * Total capacity
  * Free space, in Gb and %

## Pre-requisites
* Veeam Backup & Replication with Enterprise Manager installed. Make sure your Zabbix server/proxy can reach REST API: `https://<veeam_ip>:9398/api/`
* Windows user with appropriate rights on Veeam host (Administrators group, I suppose) with known password.
* PHP (tested on 5.4.16) + php-curl + php-xml on Zabbix Server (or proxy).

## Installation
1. Copy `zabbix-veeam.php` to Zabbix server (or proxy) here: `/usr/lib/zabbix/externalscripts/`
1. `chmod +x /usr/lib/zabbix/externalscripts/zabbix-veeam.php`
1. Edit options in `AppOptions` class, most likely you need only username and password for Veeam.
1. Import `zbx_export_templates.xml` to Zabbix
1. Assign the template `Template Veeam REST` to Veeam host.
1. Assign host macros to Veeam host:

   `{$VEEAM_URL}` => `https://<veeam_ip>:9398/api/`
   
   where `veeam_ip` is Veeam host IP address or hostname.
   
1. Create global or host macros `{$VEEAM_BACKUP_NODATA}`. I use '36h' to have trigger risen when the job was not executed for 36 hours.
1. Create global or host macros `{$VEEAM_REPLICA_FAILED_TIME}`. For example, I use '6h' for it, so my replication jobs rise trigger when last successful replica was more than 6 hours ago.
1. Create global regular expression:
    * Name: `Veeam REPLICAJOBSCHEDULE`
    * Expression type: Character string not included
    * Expression: `false`
  
1. Wait for items discovery.

## Backup, backup copy and agent jobs
Trigger rises if last job was not successful or last job was over `{$VEEAM_BACKUP_NODATA}` ago.

## Replica jobs
Trigger rises if last successful job was over `{$VEEAM_REPLICA_FAILED_TIME}` ago (you can set it yourself using global or host macros).

You can adjust this time on per-job basis using this hack: just add tag `<zabbix_replica_time>...</zabbix_replica_time>` to job's description inside Veeam: http://prntscr.com/o6ymc8

For example, to rise trigger if the job remains unsuccessful for 24 hours, add the tag: `<zabbix_replica_time>24h</zabbix_replica_time>`

Replica jobs not having the tag will get alert time from macros `{$VEEAM_REPLICA_FAILED_TIME}`.

Replica jobs without schedule (which are not planned to start automatically) are not discovered to avoid trash alerts in Zabbix.

## Repositories
Trigger rises if free space on the repository is less than 10%.

## Debug & logging
You can easily debug the script by launching it with something as fourth parameter:

`./zabbix-veeam.php <URL> getLastBackupJobInfo "<job name>" debug`

It will give all logs (not truncated) to stdout.

Of course, you can see logfile too. By default, it writes logs to `/tmp/zabbix-veeam.log`  (passwords are not logged). You can change it with `$logFile = `  in `AppOptions` class. To turn off logging, use `$logToFile = false;`

