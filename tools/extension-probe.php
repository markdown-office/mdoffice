<?php

declare(strict_types=1);

/**
 * What the interpreter that runs this file ended up with.
 *
 * A released binary cannot be asked what it carries: it runs one program and stops.
 * So the same interpreter is asked instead — the `micro` SAPI with this file
 * appended to it, which is the very file the released binary is built from. What it
 * reports is therefore what the released binary has, and not what a sibling build of
 * the same commit happened to have.
 *
 * The report is two lines, because the smoke test reads them and does not parse
 * PHP:
 *
 *     extensions: dom,gd,imagick,zip
 *     jpeg: yes
 *
 * The names are lowercased, because `SimpleXML` and `simplexml` are the same
 * extension and the smoke test compares them against the names the libraries ask
 * for.
 */

$extensions = array_map('strtolower', get_loaded_extensions());

sort($extensions);

echo 'extensions: ', implode(',', $extensions), PHP_EOL;

$arguments = $_SERVER['argv'] ?? [];

/**
 * The path of the JPEG to read, when there is one and it is a path.
 *
 * `$_SERVER['argv']` is whatever the SAPI put there, and this file runs in a
 * program with a fixed job, so the check is the narrowing rather than an assertion.
 */
$jpeg = is_array($arguments) && is_string($arguments[1] ?? null) ? $arguments[1] : '';

/**
 * `ext-gd` being present says nothing about the libraries behind it: the function
 * is compiled in either way, and only reading a JPEG tells the difference. The
 * file is checked first, because a missing one is a missing file and not a library
 * that cannot read it.
 */
$readable = $jpeg !== '' && is_file($jpeg) && imagecreatefromjpeg($jpeg) !== false;

echo 'jpeg: ', $readable ? 'yes' : 'no', PHP_EOL;
