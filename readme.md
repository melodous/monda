# Monitoring system data analysis

This package contains tools to analyze data from monitoring system.
At this moment, only Zabbix is supported. Please, read documentation before 
using this package. Monda can be performance killer for your Zabbix installation!

## Basics

Monda will use lot of resources and can be performance killer for Zabbix! 
There will be lot of SQL queries which can cause big load or big IOwait time.
Even if Monda is made "as safe as possible", it is really hard to predict what
it would do with your Zabbix server. 

## Two databases concept

Monda is developed with dual database concept. One is native Zabbix database,
used by Zabbix server, second is Monda database. In theory, both of them can be 
one database system, but main concept of Monda is, to analyze data in idle
server time and not to slowdown any Zabbix process. From this reason, Monda uses
its own database system for storing its data.

## More zabbixes in one Monda database

It is possible to analyse more zabbix servers using one monda database. You can use zabbix_alias or zabbix_id
for this. All statistics, correlation and other facts about zabbix servers will be in one monda database.

### Monda host and user considerations

Monda itself can run on same server a Zabbix server. Best practice is to run as **monda** user.
In fact, it can be any user different from root. If you do not want to touch your Zabbix server 
and want to use dedicated server for monda, it is possible. But you have to permit monda to access zabbix database 
and zabbix api.

### Zabbix database considerations

Zabbix database can be in theory mysql or postgresql. If you want to analyze lots of data in history,
take care about table partitioning. Especialy history and history_uint table. There will be lot
of queries to this tables.

### Monda database considerations

Monda database has to be postgresql now. It is good idea to be on another server
than Zabbix. But if you are lazy, you can use same server as Zabbix. Depending on
your Zabbix setup, Monda database will grow significantly!

## Installing

Before installing, please read text above and choose right databases and host(s). We will need 
two database configs. One will become from Zabbix setup and will be referrenced as 
**ZabbixDB**. Second will be Monda db and will be referrenced as **MondaDB**. Next, we
will need host and user for monda process itself. See above.

### Zabbix preparation

Create monda user using Zabbix frontend. This user should have readonly rights for all 
hosts which you want to process by Monda. User must have API access enabled.
Next to this, create host group **monda** (name is case sensitive!).
All hosts in this group will be processed by Monda. Or you can use another group and use **-Hg** 
parameter.

### Cloning

There is no release yet. You must use git to clone monda repository. Git must be installed before.

```
# su -l monda
$ git clone git@github.com:limosek/monda.git
$ cd monda
$ . env.sh
```
Optionaly, you can add PATH for monda account permanently:

```
$ cat >>~/.profile <<EOF
  export PATH=\$PATH:~/monda/
EOF
```

### Installing DB

First you have to create MondaDB. According to your setup, you have to feed sql/init.sql into your 
SQL command. You have to be postgresql admin user to run scripts. There are three scripts.
- init_db.sql to create role and DB It will use default password for role monda!
- init_schema.sql to create tables and objects inside monda DB
- drop.sql to drop database, tables and roles (if you want to start from scratch again)
- db.sh {init|drop} will try to do all

```
# cd ~/monda
# su postgres
$ ./misc/db.sh init
```

### Configuring

Monda configuration is based on commandline arguments, INI file and environment variables. 

All commandline arguments can be saved into .mondarc file. This is located at ~/.mondarc. It can contain arguments 
like passed from commandline (but only long variant). All lines starting with **#** will be ignored.
If you will pass all informations on commandline, you do not need config.

Example config file:
```
$ cat ~/.mondarc

[global]
# Zabbix API url, user and password
zabbix_api_url='http://zabbix/api_jsonrpc.php'
zabbix_api_user=monda
zabbix_api_pw=someapipassword
# Zabbix api enable (default disabled)
zabbix_api=1

# ZabbixDb DSN
zabbix_dsn='pgsql:host=127.0.0.1;port=5432;dbname=zabbix'
zabbix_db_user=zabbix
zabbix_db_pw=some_password
# Zabbix ID (there can be more zabbix server in one monda db). If unspecified, "1" is used.
zabbix_id=1

# MondaDb DSN
monda_dsn='pgsql:host=127.0.0.1;port=5432;dbname=monda'
monda_db_user=monda
monda_db_pw=some_password

# This section will be automaticaly read, if monda is used with --zabbix_alias site1
# or if environment variable MONDA_ZABBIX_ALIAS=site1
[zabbix-site1]
zabbix_dsn='pgsql:host=some.other.host;port=5432;dbname=zabbix'
zabbix_db_user=zabbix
zabbix_db_pw=some_other_password
# Zabbix ID (has to be unique per alias!)
zabbix_id=2

# Module specific parameters
[Tw]
#output_mode=cli

# Module:action specific parameters
[Is:compute]
#
max_windows_per_query=5

```
Basic help and list of modules can be obtained by runing:
```
$ monda [module]

```

Advanced help can be obtained by
```
$ monda [module] -h 2>&1 |less
```
or
```
$ monda [module] -xh 2>&1 |less
```

## Running

First we will create timewindows to inspect. This will test MondaDb connection.
```
$ monda tw:create -s yesterday
```

Next, we will try to compute item statistics for created windows. This will test ZabbixDB
connection
```
$ monda is:compute -s yesterday
$ monda is:show -s yesterday
```

You can use cron module, which will do all work automaticaly. **-Sc** parameter 
will do cron subtargets too (eg. all days from week). Take care! This can be very 
expensive to do cron with subtargets for long time (like last example).
```
$ monda cron:1hour
$ monda cron:1day -Sc
$ monda cron:1week -Sc
$ monda cron:1month -Sc
$ monda cron:1month -s "1 year ago" -Sc

```

To see text results, use
```
$ monda tw:show -s yesterday -Om csv
$ monda is:show -s yesterday -Om csv -Ov expanded
$ monda ic:show -s yesterday -Om csv -Ov expanded
```

## Graphical outputs

Monda uses Graphviz to show some results. You must install graphviz. At this time you can use:
```
# Generate timewindows graph with correlations
$ monda gm:tws -s yesterday -Ov expanded --loi_sizefactor 0.01| fdp -Tsvg >tws.svg
# Generate item correlation graph in one window with id wid.
$ monda gm:ics -w wid -Ov expanded --loi_sizefactor 0.01| fdp -Tsvg >ics.svg
```

