<?php

declare(strict_types=1);

namespace MarkdownOffice\Tests\Support;

use ZipArchive;

/**
 * Builds the packages the tests need that are not quite decks.
 *
 * A deck that is not a deck is a fixture too, and it is the one a reader is most
 * likely to meet: a file with the wrong extension, an archive that was never a
 * presentation, a deck whose slide part was written by something that does not
 * write XML. The deck factory builds decks, so these are built here.
 */
final class Packages
{
    /**
     * A package with nothing in it but the parts it is given.
     *
     * @param array<string, string> $parts The parts, by name.
     */
    public static function package(array $parts, string $prefix = 'package'): string
    {
        $path = Scratch::path($prefix, '.pptx');
        $zip = new ZipArchive();

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new FixtureUnavailable(sprintf('Unable to write the fixture "%s".', $path));
        }

        foreach ($parts as $name => $contents) {
            $zip->addFromString($name, $contents);
        }

        $zip->close();

        return $path;
    }

    /**
     * A copy of a package with parts changed and parts left out.
     *
     * @param array<string, string> $changes The parts to write afresh, by name.
     * @param list<string>           $removals The parts to leave out.
     */
    public static function copy(string $package, array $changes = [], array $removals = [], string $prefix = 'copy'): string
    {
        $path = Scratch::path($prefix, '.pptx');
        $source = new ZipArchive();

        if ($source->open($package) !== true) {
            throw new FixtureUnavailable(sprintf('Unable to read the fixture "%s".', $package));
        }

        $out = new ZipArchive();

        if ($out->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $source->close();

            throw new FixtureUnavailable(sprintf('Unable to write the fixture "%s".', $path));
        }

        for ($index = 0; $index < $source->numFiles; $index++) {
            $name = (string) $source->getNameIndex($index);

            if (in_array($name, $removals, true)) {
                continue;
            }

            $out->addFromString($name, (string) $source->getFromName($name));
        }

        foreach ($changes as $name => $contents) {
            $out->addFromString($name, $contents);
        }

        $out->close();
        $source->close();

        return $path;
    }

    /**
     * A package with as many parts in it as it is told to.
     *
     * The parts are empty, because a package that is refused for holding too many
     * of them is refused on the count and not on what is in them.
     */
    public static function many(int $count, string $prefix = 'many'): string
    {
        $path = Scratch::path($prefix, '.pptx');
        $zip = new ZipArchive();

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new FixtureUnavailable(sprintf('Unable to write the fixture "%s".', $path));
        }

        for ($index = 1; $index <= $count; $index++) {
            $zip->addFromString(sprintf('part%05d.xml', $index), '');
        }

        $zip->close();

        return $path;
    }

    /**
     * A package with one part that is larger than a part may be.
     *
     * The part is a file of zeros, so the archive is a real one and the size in
     * its directory is the size the part really has once it is decompressed. A
     * hole in the file takes no room on the disk, so the fixture costs a few
     * hundred kilobytes rather than the quarter of a gigabyte it holds.
     *
     * @param positive-int $bytes The size the part has once it is decompressed.
     */
    public static function oversized(string $part = 'ppt/presentation.xml', int $bytes = 268435457): string
    {
        $source = Scratch::path('part', '.bin');
        $handle = fopen($source, 'w');

        if ($handle === false) {
            throw new FixtureUnavailable(sprintf('Unable to write the fixture "%s".', $source));
        }

        ftruncate($handle, $bytes);
        fclose($handle);

        $path = Scratch::path('oversized', '.pptx');
        $zip = new ZipArchive();

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new FixtureUnavailable(sprintf('Unable to write the fixture "%s".', $path));
        }

        $zip->addFile($source, $part);
        $zip->close();

        @unlink($source);

        return $path;
    }
}
