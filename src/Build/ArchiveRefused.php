<?php

declare(strict_types=1);

namespace MarkdownOffice\Build;

use RuntimeException;

/**
 * The archive on disk is not one this build can stand behind: it does not parse as the
 * layout this PHP wrote, the rewrite of its timestamps did not take, or it could not be
 * read and written back at all.
 *
 * The other half of {@see BuildRefused}, which is about what the build was handed rather
 * than what it wrote. Every one of these ends the same way — the build deletes the file,
 * because an archive it cannot vouch for is worse than no archive — so it extends
 * `RuntimeException` and the catch in `mdofficeBuild()` is unchanged.
 */
final class ArchiveRefused extends RuntimeException
{
}
