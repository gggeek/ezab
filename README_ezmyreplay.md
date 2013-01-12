ezmyreplay
==========

This is a tool for benchmarking MySql database servers.
It is designed to give you an impression of how your current database server
installation performs, by repeatedly executing - with many parallel threads - a
series of queries taken from a file.
There is no need to have a spoecific database schema or data-set installed.
It is inspired by the percona-playback tool from Percona.


Requirements
------------

- php version 5
- ability to run php from the command line (for linux this often means installing the php-cli package)
- the mysqli php extension


Using the tool
--------------

This tool can be used in 3 ways:
1. from the command line, eg: "php ezmyreplay.php -h"
2. as a web page (when hosted by a webserver), eg: http://localhost/ezmyreplay.php
3. as a library included by other php applications

A typical usage scenario is: replicating real-life server load while changing
server configuration (or while increasing the number of concurrent clients to
assess scalability).
Steps:
1. stop all applications using the database except the one you intend to examine
2. set up the db server so that it captures all queries into the slow query log (slow_query_log, long_query_time=0)
3. restart the db server and rotate the slow query log
4. run your application so that the queries you later want to replay are executed (try not to capture too many queries!)
5. set the slow query log back to normal; restart the db server and rotate the slow query log
6. stop all applications using the database
7. replay the generated query log as many times as you want with ezmyreplay,
   increasing client threads and/or making database configuration changes

Notes:
. the database is not reset between each run of the tool. Take care if your load
  to be replayed contains INSERT statements
. when comparing performance across many benchmarking sessions, make sure that the
  database contents are identical. It is a good idea to dump the db at the beginning
  and reimport it before each benchmarking session


Synopsis, options
-----------------

php ezmyreplay.php [options] logfile

The log file should contain sql statements, each starting on a new line and ending with a semicolon.
Lines starting with the hash character are considered comments.
This is compatible with the format used by the MySql slow query log.

To get a full list of supported options run the script using the -h option:
* php ezmyreplay.php -h
* http://localhost/ezmyreplay.php?h=1

Notes:
. you can omit from command line arguments to specify the database to use if there
  are USE statements in your sql log. Viceversa USE statements in your sql log
  will be ignored if you specify on the command line  a database to be used


Output
------

The output is a mixed bag of information, taken from both percona-playback and
Apache Bench.


Development status
------------------

Current status is UNDER CONSTRUCTION.


Under the hood
--------------

Whatever way you choose to run the program, what happens is that the php script will
execute more copies of itself, each of which will send some requests to the server.
The number of processes forked depends on the "-c" parameter.
The main process waits for all chidren to terminate execution, collects their
metrics, aggregates them and displays the result.
To debug execution of the program if anything goes wrong, run it at verbosity level 4 (option: -v 4)
