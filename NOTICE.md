# Third-Party Notices

This file describes third-party software used by CheckAI. The license for CheckAI's original code is in [LICENSE](LICENSE); commercial-use terms are in [COMMERCIAL.md](COMMERCIAL.md).

The Docker image is based on `php:8.3-apache-bookworm`. It includes PHP, Apache HTTP Server, SQLite support through PDO_SQLITE, and Debian Bookworm system packages. These third-party components remain subject to their own licenses; the CheckAI license in [LICENSE](LICENSE) applies to CheckAI's original code and does not change the third-party terms listed below.

## PHP

PHP 8.3 is provided under the [PHP License 3.01](https://www.php.net/license/3_01.txt). The PHP runtime is included through the official PHP Docker image.

## Apache HTTP Server

Apache HTTP Server is provided under the [Apache License, Version 2.0](https://www.apache.org/licenses/LICENSE-2.0). It is included through the official PHP Docker image.

## SQLite

CheckAI uses SQLite through PHP's PDO_SQLITE extension. SQLite is dedicated to the public domain; see the [SQLite copyright notice](https://sqlite.org/copyright.html).

## Debian Bookworm packages

The base Docker image also contains Debian Bookworm packages and their dependencies. Each package retains its own license and notices. Consult the package metadata and files under `/usr/share/doc/` in the image for the notices applicable to a specific package.

No separately bundled third-party application libraries are currently included in the CheckAI repository.
