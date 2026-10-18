<?php

declare(strict_types=1);

namespace MarkdownOffice\Build;

use RuntimeException;

/**
 * The build cannot go on: the manifest, the dependencies or the archive is not what
 * it needs to be.
 *
 * Named so that a test can assert a build was refused for the reason it says. It
 * extends `RuntimeException`, so the throws already in `tools/build-phar.php` catch
 * the same way they did.
 */
final class BuildRefused extends RuntimeException
{
}
