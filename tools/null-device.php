<?php

declare(strict_types=1);

/**
 * The device that is always empty, which is what a child reads instead of the
 * standard input it would otherwise inherit.
 *
 * It is spelled differently on each platform, and the name of the POSIX one is
 * not understood by the Windows shell: a redirect from `/dev/null` there is a
 * redirect from a file that does not exist, and the command never runs.
 *
 * @return string The path of the empty device of the platform.
 */

return PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
