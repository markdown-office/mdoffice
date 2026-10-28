<?php

declare(strict_types=1);

namespace MarkdownOffice\Console\Package;

use MarkdownOffice\Console\ConsoleException;
use ZipArchive;

/**
 * The bounds a package may not pass.
 *
 * A zip says how its contents are laid out and nothing about how much room they
 * will take up: a part of a few hundred kilobytes can hold a gigabyte once it is
 * decompressed. So the number of parts and the size of each are read out of the
 * central directory, which costs one lookup per part and no decompression at all.
 *
 * The bounds are the same wherever a deck is opened, so a deck the diff refuses is
 * refused by the theme as well. One limit is one limit, and a reader who has
 * learned it from one half of the program should not have to learn it again from
 * the other.
 */
final class Bounds
{
    /**
     * The largest one part of a package may be once decompressed.
     */
    public const MAX_PART_BYTES = 268435456;

    /**
     * The largest number of parts a package may have.
     */
    public const MAX_PARTS = 4096;

    /**
     * Refuse a package that holds more than a package may hold.
     *
     * The sizes are the ones the central directory declares, which costs one
     * lookup per part and no decompression.
     *
     * @throws ConsoleException when the package has too many parts, or a part that
     *         is too large once decompressed.
     */
    public static function check(ZipArchive $zip, string $path): void
    {
        if ($zip->numFiles > self::MAX_PARTS) {
            throw new ConsoleException(sprintf(
                'The deck "%s" has %d parts, which is more than a deck may hold.',
                $path,
                $zip->numFiles,
            ));
        }

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);

            if ($stat === false) {
                continue;
            }

            $size = (int) $stat['size'];

            if ($size > self::MAX_PART_BYTES) {
                throw new ConsoleException(sprintf(
                    'The part "%s" of the deck "%s" is %d bytes, which is more than a part may be.',
                    (string) $stat['name'],
                    $path,
                    $size,
                ));
            }
        }
    }
}
