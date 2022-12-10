ezab toolkit
============

This is a suite of tools for benchmarking (load testing) web servers and databases.

Goals
-----
It is designed to be useful for consultants.
Primary need is ease of use on hostile environments (a.k.a customers servers).
This translates into:
- no install/deinstall process (just copy a text file and you're done)
- as few dependencies as possible (I work on servers where php is already installed so that does not count)
- easy learning curve: mimic usage of other existing, well known tools

Requirements
------------

- php version 5 or higher
- ability to run php from the command line (for linux this often means installing the php-cli package)
- various php extensions, depending on the script used (`curl` for ezab.php, `mysqli` for ezmyreplay.php)

List of tools available
-----------------------

- `ezab.php`: a clone of the Apache Bench tool

- `abrunner.php`: a script which runs AB many times in a row and collects aggregate data
  ( e.g. useful to test responsiveness of one web page while increasing concurrency or collect response times across a
  list of urls)

- `ezmyreplay.php`: replays queries from eg. a slow log against a mysql server
  ( e.g. useful to test responsiveness of one db while increasing concurrency or test performance changes obtained via
  configuration tweaks)

FAQ
---

* **Q:** can these tools be installed via Composer? **A:** yes

More info
---------

For more information, look at the tool-specific README file: [ezab](README_ezab.md), [ezmyreplay](README_ezmyreplay.md)


[![License](https://poser.pugx.org/gggeek/ezab/license)](https://packagist.org/packages/gggeek/ezab)
[![Latest Stable Version](https://poser.pugx.org/gggeek/ezab/v/stable)](https://packagist.org/packages/gggeek/ezab)
[![Total Downloads](https://poser.pugx.org/gggeek/ezab/downloads)](https://packagist.org/packages/gggeek/ezab)
