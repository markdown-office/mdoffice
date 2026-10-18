<?php

declare(strict_types=1);

/*
 * Fetches PHPStan into `tmp/` and checks it against a checksum, so a local run and the
 * one in continuous integration are the same analyser.
 *
 * The version and the hash live here and nowhere else. A range and a plain download
 * would be a different analyser next month, and a green run would then mean nothing.
 *
 * Usage: php tools/install-phpstan.php
 */

$version = '2.3.1';
$sha256 = '85157dba3d95d77ca603d2b7303aa78a538f9e3b56ccad07dd794c53aa96d1ae';
$target = dirname(__DIR__) . '/tmp/phpstan.phar';

if (is_file($target) && hash_file('sha256', $target) === $sha256) {
    printf("PHPStan %s is already in place.\n", $version);

    exit(0);
}

$url = sprintf('https://github.com/phpstan/phpstan/releases/download/%s/phpstan.phar', $version);
$phar = @file_get_contents($url);

if ($phar === false || hash('sha256', $phar) !== $sha256) {
    fwrite(STDERR, sprintf("%s did not download to the bytes it claims to be.\n", $url));

    exit(1);
}

if (!is_dir(dirname($target))) {
    mkdir(dirname($target), 0o777, true);
}

file_put_contents($target, $phar);
chmod($target, 0o755);

printf("PHPStan %s written to %s.\n", $version, $target);
