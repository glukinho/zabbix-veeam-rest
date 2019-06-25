# zabbix-veeam-rest
Zabbix template and php script for monitoring Veeam B&R jobs and repositories.

## How it works
It uses Veeam REST API: https://helpcenter.veeam.com/docs/backup/rest/overview.html?ver=95u4. JSON mode only is now supported, XML is not.

REST queries are sent from Zabbix server (or proxy), no scripts or zabbix agent setup is needed on Veeam host itself.

Developed and Tested on Zabbix 3.4.3 (PHP 5.4.16) and Veeam Backup & Replication 9.5u4.

Discovered items:
* Backup jobs and agent backup jobs:
  * Last job result/duration/date (for each discovered job)
* Replication jobs:
  * Last SUCCESSFUL job result/date (for each discovered job)
* Backup repositories:
  * Total capacity
  * Free space, in Gb and %

Triggers rise:
* Backup jobs and agent jobs: last job was not successful
* Replication jobs: last successful job was more than `{$VEEAM_REPLICA_FAILED_TIME}` ago (you can set it yourself using macros)
* Repositories: free space less than 10%.

## Pre-requisites
* Veeam Backup & Replication with Enterprise Manager installed. Make sure you can reach REST API: http://<veeam_ip>:9399/api/
* Windows user with appropriate rights on Veeam host (Administrators group, I suppose) with known password.
* PHP and php-curl on Zabbix Server or proxy (I believe you have it already).

## Installation
1. Copy `zabbix-veeam.php` to Zabbix server (or proxy) here: `/usr/lib/zabbix/externalscripts/`
1. `chown +x /usr/lib/zabbix/externalscripts/zabbix-veeam.php`
1. Import template `zbx_export_templates.xml` to Zabbix
1. Assign the template and host macros to Veeam host:

   `{$VEEAM_URL} => http://username:password@veeam_ip:9399/api/`
   
   where `username` and `password` are for account on Veeam host, `veeam_ip` is Veeam host.
   
1. Create global or host macros `{$VEEAM_REPLICA_FAILED_TIME}`. For example, I use '6h' for it, so my replication jobs rise trigger when last successful replica was more than 6 hours ago.
1. Wait for items discovery.

## Logging
By default, it writes some logs to `/tmp/zabbix-veeam.log`  (passwords are not logged). You can change it with `$debug_file = `  inside. To turn off logging, use `$debug = false;`
