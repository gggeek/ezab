ezab
====

This is a tool for benchmarking web servers.
It is designed to give you an impression of how your current web server installation performs.
This especially shows you how many requests per second your web server installation is capable of serving.

It strives to be as close to Apache Bench as possible, both in supported options
and in output format.

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

Development status
------------------

Current status is UNDER CONSTRUCTION.

Some features of the original AB are still missing, such as POST/PUT support, etc...
The output of the tool is also lacking.
Instead of having the list of things that do work in the docs, it is suggested to run the script with the -h option.

On the other hand, there are already a couple of features that we do better than the original:
1. support for http 1.1 by default. AB uses http 1.0, but most of the web in 2012 runs on version 1.1.
   This means that the numbers you get should be closer to "real life traffic"
2. better support for keep-alives (AB can only do http/1.0 keepalives, which Apache does not support when running dynamic page generators such as php)
3. easy support for compressed http responses (using the -j option)


Under the hood
--------------

Whatever way you choose to run the program, what happens is that the php script will
execute more copies of itself, each of which will send some requests to the server.
The number of processes forked depends on the "-c" parameter.
The main process waits for all children to terminate execution, collects their
metrics, aggregates them and displays the result.
To debug execution of the program if anything goes wrong, run it at verbosity level 4 (option: -v 4)
