<?php

declare(strict_types=1);

namespace MarkdownOffice\Tests\Support;

use RuntimeException;

/**
 * A fixture could not be built or could not be read.
 *
 * A type of its own rather than a `RuntimeException` inline, because every one of
 * these means a test cannot get as far as asserting anything: the document it was
 * going to open is not there, or is not the shape the library writes. A failure like
 * that should not be catchable by accident as "something went wrong at runtime", and
 * naming it says what actually happened — a fixture, not the program under test.
 */
final class FixtureUnavailable extends RuntimeException
{
}
