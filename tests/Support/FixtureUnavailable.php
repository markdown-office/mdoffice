<?php

declare(strict_types=1);

namespace MarkdownOffice\Tests\Support;

use RuntimeException;

/**
 * A fixture could not be built or could not be read.
 *
 * Every one of these means the test cannot get as far as asserting anything, so the
 * name says what happened — a fixture, not the program under test.
 */
final class FixtureUnavailable extends RuntimeException
{
}
