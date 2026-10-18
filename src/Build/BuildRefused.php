<?php

declare(strict_types=1);

namespace MarkdownOffice\Build;

use RuntimeException;

/**
 * The build cannot go on: the manifest, the dependencies or the archive is not what
 * it needs to be.
 *
 * A type of its own rather than a `RuntimeException` thrown inline, because every one
 * of these ends the build, and `composer build:phar` reports what it was told rather
 * than a stack trace. Catching this by name is also how a test can assert that a
 * build was refused for the reason it says rather than for some other one.
 */
final class BuildRefused extends RuntimeException
{
}
