<?php

declare(strict_types=1);

namespace MarkdownOffice\Build;

use RuntimeException;

/**
 * The build cannot go on over something it was handed: a manifest it cannot stage, a
 * path repository it has no URL to resolve, or a setting in the environment whose value
 * it will not invent.
 *
 * The archive has its own type in {@see ArchiveRefused}, because what this build wrote
 * is a different thing from what it was given.
 *
 * Named so that a test can assert a build was refused for the reason it says. It
 * extends `RuntimeException`, so the throws already in `tools/build-phar.php` catch
 * the same way they did.
 */
final class BuildRefused extends RuntimeException
{
}
