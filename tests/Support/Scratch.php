<?php

declare(strict_types=1);

namespace MarkdownOffice\Tests\Support;

use RuntimeException;

/**
 * A place for the files a test creates, and their removal.
 *
 * Everything lands in `tmp/pest` inside the project, never in the system temp
 * directory, so a run writes nothing outside the repository; the directory is
 * ignored by git.
 *
 * The subdirectory is the whole point of it. `tmp` is shared: a conversion run by
 * hand from the project root writes `tmp/notes.docx` into it, and the suite runs in
 * the same tree. A test run that emptied `tmp` would delete that output as it went
 * — which is what used to happen, and why one run could lose the files another had
 * just written. Each side cleans up after itself and nothing cleans up after anyone
 * else.
 */
final class Scratch
{
    private const SUBDIRECTORY = 'pest';

    public static function directory(): string
    {
        $directory = dirname(__DIR__, 2) . '/tmp/' . self::SUBDIRECTORY;

        if (!is_dir($directory) && !mkdir($directory, 0o777, true) && !is_dir($directory)) {
            throw new FixtureUnavailable(sprintf('Unable to create the scratch directory "%s".', $directory));
        }

        return $directory;
    }

    /**
     * A path no other test is using.
     */
    public static function path(string $prefix = 'docx', string $extension = '.docx'): string
    {
        return self::directory() . '/' . $prefix . '-' . bin2hex(random_bytes(6)) . $extension;
    }

    /**
     * Empty the scratch directory.
     *
     * The whole of `tmp/pest`, because a test cannot know every file it caused to be
     * written: a converter that names its output after its input creates a path nobody
     * asked for, and a media directory appears out of nowhere. A run that leaked those
     * would fill the disk quietly, which is worse than losing a scratch file after a
     * failure — the names are random either way, so there is little to look at.
     */
    public static function cleanUp(): void
    {
        $entries = scandir(self::directory());

        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = self::directory() . '/' . $entry;

            is_dir($path) ? self::removeTree($path) : @unlink($path);
        }
    }

    private static function removeTree(string $directory): void
    {
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . '/' . $entry;

            is_dir($path) ? self::removeTree($path) : @unlink($path);
        }

        @rmdir($directory);
    }

    public static function image(string $name = 'fixture.png'): string
    {
        $path = self::directory() . '/' . $name;

        if (is_file($path)) {
            return $path;
        }

        $size = 24;
        $image = imagecreatetruecolor($size, $size);

        if ($image === false) {
            throw new FixtureUnavailable('GD would not make a truecolor image to draw a fixture into.');
        }

        // A colour of `false` drawn with is `0`: an exhausted palette gives a black
        // rectangle rather than a failure, which is a fixture that stops proving anything.
        $background = imagecolorallocate($image, 0x8B, 0x1A, 0x1A);
        $ink = imagecolorallocate($image, 0xFF, 0xF3, 0xF3);

        if ($background === false || $ink === false) {
            throw new FixtureUnavailable('GD would not allocate the two colours the fixture is drawn in.');
        }

        imagefilledrectangle($image, 0, 0, $size, $size, $background);
        imagerectangle($image, 1, 1, $size - 2, $size - 2, $ink);
        imagepng($image, $path);

        return $path;
    }

    /**
     * A WebP fixture, skipped where GD was built without the format.
     *
     * There is no way to make this one on a machine that cannot: it exists to prove
     * the conversion path, and a build without WebP has no WebP to prove it with.
     */
    public static function webp(string $name = 'fixture.webp'): ?string
    {
        if (!function_exists('imagewebp') || (imagetypes() & IMG_WEBP) === 0) {
            return null;
        }

        $path = self::directory() . '/' . $name;

        if (is_file($path)) {
            return $path;
        }

        $size = 24;
        $image = imagecreatetruecolor($size, $size);

        if ($image === false) {
            throw new FixtureUnavailable('GD would not make a truecolor image to draw a fixture into.');
        }

        $background = imagecolorallocate($image, 0x1B, 0x5E, 0x20);
        $ink = imagecolorallocate($image, 0xFF, 0xFF, 0xFF);

        if ($background === false || $ink === false) {
            throw new FixtureUnavailable('GD would not allocate the two colours the fixture is drawn in.');
        }

        imagefilledrectangle($image, 0, 0, $size, $size, $background);
        imagefilledellipse($image, intdiv($size, 2), intdiv($size, 2), 14, 14, $ink);
        imagewebp($image, $path);

        return $path;
    }
}
