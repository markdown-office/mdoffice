#!/usr/bin/env bash

#
# PHPStan, fetched by checksum and run at the level in phpstan.neon.dist.
#
# A wrapper rather than a memory of the command, so the version, the hash and the level
# each live in exactly one place: this script fetches, phpstan.neon.dist configures,
# and continuous integration runs `composer analyse`.
#
# The memory limit is set here rather than left to php.ini: analysing these paths
# together passes what a default install gives, and the result is a crashed worker —
# which reads as the analyser's opinion rather than as this machine's memory.

set -euo pipefail

cd "$(dirname "$0")/.."

php tools/install-phpstan.php
php tmp/phpstan.phar analyse --no-progress --memory-limit=2G --autoload-file=vendor/autoload.php
