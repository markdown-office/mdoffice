<?php

declare(strict_types=1);

namespace MarkdownOffice\Console;

/**
 * The refusal of a result that would land on the file it was made from.
 *
 * Both halves of the tool can be pointed at the file they are converting, and a
 * conversion that overwrites its own input destroys it. So the check is here,
 * in one place, rather than in a command of each half.
 */
final class Overwrite
{
    /**
     * Refuse a result that would land on the file it was made from.
     *
     * Called twice, and the second call is the point. The first happens before
     * the conversion, so that a run which is going to be refused stops before
     * spending the work. But a conversion is not instant, and anything with
     * write access to the output directory can put the input's own inode at the
     * output path in between — so each command looks again at the last moment.
     *
     * That narrows the window rather than closing it: what is left is the space
     * between the check and the write. Closing it needs the write to be
     * conditional on what it is about to replace, which `rename()` cannot
     * promise and `file_put_contents()` certainly does not.
     *
     * @throws ConsoleException when the two paths are one file.
     */
    public static function refuse(?string $input, string $output): void
    {
        // Nothing to protect: a stream is not a file the run can land on.
        if ($input === null || $input === '-' || $output === '-') {
            return;
        }

        if (! self::namesTheInput($input, $output)) {
            return;
        }

        throw new ConsoleException(
            sprintf('The result would overwrite the input file "%s".', $input),
            ['Pass --output to write it somewhere else.'],
        );
    }

    /**
     * Whether the two paths are one file, whatever they happen to be called.
     *
     * By inode rather than by name: on a case-insensitive filesystem — APFS and
     * NTFS, which is what most macOS and Windows machines have — `Notes.md` and
     * `notes.md` are two spellings of one inode, and `realpath()` hands each back
     * in the case it was written in, so the two strings never match. A hard link
     * is two paths to one inode for the same reason, and a symlink to the input is
     * a third.
     *
     * A path that is not there cannot be the input, however it is spelled. The one
     * shape still worth catching is `sub/../notes.md`, and the detour in front of
     * it is exactly why the comparison cannot be left to `realpath()`, which
     * returns nothing at all for a path whose directory is missing. So the two are
     * compared as text, with the `.` and `..` segments taken out by hand.
     */
    private static function namesTheInput(string $input, string $output): bool
    {
        $from = realpath($input);
        $to = realpath($output);

        if ($from !== false && $to !== false) {
            return self::isTheSameFile($from, $to);
        }

        // The resolved path goes through the same normaliser too, which changes
        // nothing about it except the separators on Windows.
        return $from !== false && self::withoutDetours($output) === self::withoutDetours($from);
    }

    private static function isTheSameFile(string $left, string $right): bool
    {
        // Silenced because the path can be replaced by a directory between the
        // two `realpath()` calls above and this one, and refusing is still the
        // right answer when it has.
        $one = @stat($left);
        $other = @stat($right);

        return $one !== false
            && $other !== false
            && $one['dev'] === $other['dev']
            && $one['ino'] === $other['ino'];
    }

    /**
     * A path as it would be written out, with the `.` and `..` segments
     * taken out and a relative one anchored where it was written from.
     *
     * Textual, and so blind to one shape: where a `..` follows a symbolic link
     * the filesystem keeps the link's own directory and this throws the name
     * away. A refusal that was not necessary is the cheaper mistake to make in
     * a guard.
     */
    private static function withoutDetours(string $path): string
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $path = str_replace('\\', '/', $path);
        }

        $prefix = preg_match('#^(?:[A-Za-z]:)?/#', $path, $matches) === 1 ? $matches[0] : '';
        $parts = [];

        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }

            if ($part === '..') {
                array_pop($parts);

                continue;
            }

            $parts[] = $part;
        }

        $joined = implode('/', $parts);

        if ($prefix !== '') {
            return $prefix . $joined;
        }

        $working = getcwd();

        return $working === false ? $joined : $working . '/' . $joined;
    }
}
