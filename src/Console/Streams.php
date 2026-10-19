<?php

declare(strict_types=1);

namespace MarkdownOffice\Console;

/**
 * The three streams a run reads and writes, and the file handling every part shares.
 *
 * What is *not* here is anything a part does on its own account: naming itself, which
 * is what separates `mdoffice word:` from `mdoffice slides:` in a message, and the
 * direction detection that only the Word half has, because a deck has one direction
 * and a document has two.
 */
final class Streams
{
    private const UNWRITABLE = 'Unable to write "%s".';

    /** @var resource */
    private $out;

    /** @var resource */
    private $err;

    /** @var resource */
    private $in;

    /**
     * @param resource|null $out Standard output; defaults to the process's.
     * @param resource|null $err Standard error; defaults to the process's.
     * @param resource|null $in  Standard input; defaults to the process's.
     */
    public function __construct($out = null, $err = null, $in = null)
    {
        $this->out = $out ?? STDOUT;
        $this->err = $err ?? STDERR;
        $this->in = $in ?? STDIN;
    }

    /** @return resource */
    public function out()
    {
        return $this->out;
    }

    /** @return resource */
    public function err()
    {
        return $this->err;
    }

    /** @return resource */
    public function in()
    {
        return $this->in;
    }

    /**
     * The whole of standard input, or the whole of a file.
     *
     * `-` and null are both standard input, which is how a pipe is asked for.
     *
     * @throws ConsoleException
     */
    public function read(?string $path): string
    {
        if ($path === null || $path === '-') {
            return (string) stream_get_contents($this->in);
        }

        if (!is_file($path)) {
            throw new ConsoleException(sprintf('The file "%s" does not exist.', $path));
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new ConsoleException(sprintf('Unable to read "%s".', $path));
        }

        return $contents;
    }

    /**
     * Write the result: to a file, or to standard output when the path is `-`.
     *
     * Staged beside the target and renamed, because `file_put_contents()` opens an
     * existing file with `O_TRUNC`: writing to a name that is a hard link to the input
     * would truncate the input itself.
     *
     * @throws ConsoleException
     */
    public function writeResult(string $path, string $contents, string $temporaryPrefix): void
    {
        if ($path === '-') {
            $this->write($this->out, $contents);

            return;
        }

        $directory = dirname($path);

        if ($directory !== '' && !is_dir($directory) && !@mkdir($directory, 0o777, true) && !is_dir($directory)) {
            throw new ConsoleException(sprintf('Unable to create the directory "%s".', $directory));
        }

        $temp = tempnam($directory, $temporaryPrefix);

        if ($temp === false) {
            throw new ConsoleException(sprintf(self::UNWRITABLE, $path));
        }

        try {
            if (@file_put_contents($temp, $contents) === false) {
                throw new ConsoleException(sprintf(self::UNWRITABLE, $path));
            }

            if (!@rename($temp, $path)) {
                throw new ConsoleException(sprintf(self::UNWRITABLE, $path));
            }
        } finally {
            if (is_file($temp)) {
                @unlink($temp);
            }
        }
    }

    /**
     * Where the result should go, when the user did not say.
     *
     * Standard input has no name to derive one from, so the result goes to standard
     * output rather than to a file nobody asked for.
     */
    public function outputPath(?string $input, string $extension, ?string $requested): string
    {
        if ($requested !== null) {
            return $requested;
        }

        if ($input === null || $input === '-') {
            return '-';
        }

        // In the directory it was read from, so `docs/notes.md` becomes
        // `docs/notes.pptx` rather than something that merely looks like it.
        $directory = pathinfo($input, PATHINFO_DIRNAME);
        $stem = pathinfo($input, PATHINFO_FILENAME);

        // A file whose whole name is an extension, such as `.md`, has no stem to
        // work with; the name is then used whole.
        if ($stem === '') {
            $stem = $input;
            $directory = '.';
        }

        $prefix = ($directory === '' || $directory === '.') ? '' : $directory . '/';

        return $prefix . $stem . $extension;
    }

    /**
     * The directory of the input file, which relative image paths resolve against —
     * the same rule a Markdown renderer in an editor would follow.
     */
    public static function directoryOf(?string $path): ?string
    {
        if ($path === null || $path === '' || $path === '-') {
            return null;
        }

        $resolved = realpath($path);

        return $resolved === false ? null : dirname($resolved);
    }

    /**
     * A refusal on standard error, named for the program that refused rather than for
     * the part doing the asking: `mdoffice word:` is what a person would quote back.
     */
    public function error(string $program, string $message): void
    {
        $this->write($this->err(), $program . ': ' . $message . PHP_EOL);
    }

    /**
     * @param resource $stream
     */
    public function write($stream, string $text): void
    {
        // A memory sink opened with `php://memory` in a test accepts a plain write just
        // as a pipe does.
        fwrite($stream, $text);
    }
}
