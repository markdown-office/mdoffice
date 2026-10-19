<?php

declare(strict_types=1);

namespace MarkdownOffice\Tests\Support;

use MarkdownOffice\Console\Word\UpstreamDeprecations;

/**
 * Hides one known defect in a dependency, from a test run.
 *
 * What the defect is and why the filter rather than deprecation reporting being
 * switched off is documented once, in {@see UpstreamDeprecations} — the same filter
 * the command line installs. It stays installed for the whole test rather than for a
 * wrapping helper, because a write need not go through one.
 */
final class Upstream
{
    /** A second call is a no-op, so a test file and a helper may both install it. */
    public static function install(): void
    {
        UpstreamDeprecations::install();
    }

    /** Leave the handler it replaced where it was. */
    public static function restore(): void
    {
        UpstreamDeprecations::restore();
    }

    public static function quietly(callable $work): mixed
    {
        return UpstreamDeprecations::quietly($work);
    }
}
