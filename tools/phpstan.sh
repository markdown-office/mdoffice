#!/usr/bin/env bash

#
# PHPStan, fetched by checksum and run at the level in phpstan.neon.dist.
#
# A wrapper rather than a memory of the command, because the version, the hash and the
# level each live in exactly one place: this script fetches, phpstan.neon.dist
# configures, and continuous integration runs `composer analyse`.
#
# The memory limit is raised here rather than left to php.ini because collecting type
# information for `src`, `tools/build-phar.php` and the test support code together runs
# past the 128M a default install gives, and the failure that produces is a crashed
# worker rather than a finding — which reads as the analyser's opinion rather than as
# this machine's memory.

set -euo pipefail

cd "$(dirname "$0")/.."

php tools/install-phpstan.php
php tmp/phpstan.phar analyse --no-progress --memory-limit=2G --autoload-file=vendor/autoload.php