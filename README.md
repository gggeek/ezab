ezab
====

This is a tool for benchmarking web servers.
It is designed to give you an impression of how your current web server installation performs.
This especially shows you how many requests per second your web server installation is capable of serving.

It strives to be as close to Apache Bench as possible, both in supported options
an in output format.

Requirements
------------

- php version 5
- ability to run php from the command line (for linux this often means installing the php-cli package)
- the curl php extension

Using the tool
--------------

This tool can be used in 3 ways:
1. from the command line, eg: "php ezab.php -h"
2. as a web page (when hosted by a webserver), eg: http://localhost/ezab.php
3. as a library included by other php applications

Synopsis, options
-----------------

Just run the script using the -h option:
* php ezab.php -h
* http://localhost/ezab.php?h=1

The goal is to be 100% compatible with the syntax used by AB.

Output
------

See the AB docs for details.
At the time of writing, the most recent version is available at <http://httpd.apache.org/docs/2.4/programs/ab.html>

Under the hood
--------------

Whatever way you choose to run the program, what happens is that the php script will
execute more copies of itself, each of which will send some requests to the server.
The number of processes forked depends on the "-c" parameter.
The main process waits for all chidren to terminate execution, collects their
metrics, aggregates them and displays the result.

