<?php

declare(strict_types=1);

namespace MarkdownOffice\Console;

use Composer\InstalledVersions;
use MarkdownOffice\Console\Word\WordApplication;

/**
 * The `mdoffice` command: one program for every converter in the suite.
 *
 * The suites own the converting. This owns the choosing: which of them a run is for,
 * and the help that says so. Each part is an {@see WordApplication}-shaped object of
 * its own, so adding one is an entry in {@see self::PARTS} and a class rather than a
 * change here.
 *
 * A bare file is not a run. `mdword notes.md` worked out from the file which way to
 * go, and the equivalent under a dispatcher is ambiguous the moment there is more than
 * one direction — `mdoffice notes.md` could be either half, and guessing wrong writes a
 * document nobody asked for. So the part is named, and a first argument that is not one
 * is reported with the spelling that would have worked.
 */
final class Application
{
    public const NAME = 'mdoffice';

    /**
     * The phar reads this to stamp its own manifest, and the release job fails the
     * run when this and the tag pushed disagree.
     *
     * Independent of the libraries on purpose: a CLI-only change is a reason to
     * release, and one version across three repositories would mean a Word-only fix
     * forces a presentation release. What the libraries are is in
     * {@see self::version()}, read from the lock rather than declared here.
     */
    public const VERSION = '0.1.0';

    /** Exit code for a run that did what it was asked. */
    public const SUCCESS = 0;

    /**
     * Exit code for a run that did not finish, whether the cause is a mistake the
     * caller can put right or a defect they cannot. What it printed says which.
     */
    public const FAILURE = 1;

    /**
     * The parts, by the name they are typed under.
     *
     * The one list the dispatcher, the usage text and the error for an unknown part
     * all read, so a part added here cannot be missing from the help.
     *
     * @var array<string, class-string>
     */
    private const PARTS = [
        'word' => WordApplication::class,
    ];

    /**
     * The packages whose versions a bug report needs, in the order they are printed.
     *
     * Read through `InstalledVersions` rather than declared, because that is the only
     * answer that cannot drift from what is actually in the archive: it reads the
     * lock the build resolved from, so a `--version` and the file beside it cannot
     * disagree.
     *
     * @var list<string>
     */
    private const LIBRARIES = [
        'markdown-office/markdown-word',
        'markdown-office/markdown-presentation',
    ];

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

    /**
     * Run a command and return the exit code.
     *
     * @param list<string> $argv The arguments, without the program name.
     */
    public function run(array $argv): int
    {
        $part = $argv[0] ?? null;

        if ($part === null || $part === 'help' || $part === '--help' || $part === '-h') {
            $this->write($this->out, $this->usage());

            return self::SUCCESS;
        }

        if ($part === '--version' || $part === '-V' || $part === 'version') {
            $this->write($this->out, self::version() . PHP_EOL);

            return self::SUCCESS;
        }

        if (!isset(self::PARTS[$part])) {
            $this->error($this->unknownPart($part));

            return self::FAILURE;
        }

        $class = self::PARTS[$part];

        // The part is handed the arguments after its own name and the streams it was
        // given, so a test can drive a whole run through one of these without a
        // process, and a part cannot reach for a stream the dispatcher did not pass.
        return (new $class($this->out, $this->err, $this->in))->run(array_slice($argv, 1));
    }

    /**
     * This tool's version, and the versions of the libraries it is holding.
     *
     * The first line on its own and in the shape every other PHP tool prints, so a
     * script parsing `--version` keeps working; the libraries follow as an indented
     * list rather than in the first line, for the same reason.
     *
     * A library that is not installed reads as `not installed` rather than being
     * left out: a version list that quietly omits a half is the one thing that would
     * make this answer useless for the job it exists for.
     */
    public static function version(): string
    {
        $lines = [sprintf('%s %s', self::NAME, self::VERSION)];

        foreach (self::LIBRARIES as $package) {
            $version = InstalledVersions::isInstalled($package)
                ? (InstalledVersions::getPrettyVersion($package) ?? 'unknown version')
                : 'not installed';

            $lines[] = sprintf('  %-40s %s', $package, $version);
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * The text `mdoffice help` prints.
     */
    public function usage(): string
    {
        $lines = [
            self::NAME . ' — Markdown to Word and to PowerPoint.',
            '',
            'USAGE',
            '  ' . self::NAME . ' <part> [<file>] [options]',
            '  ' . self::NAME . ' help',
            '  ' . self::NAME . ' --version',
            '',
            'PARTS',
        ];

        foreach (self::PARTS as $name => $class) {
            $lines[] = sprintf('  %-10s %s', $name, $class::summary());
        }

        $lines[] = '';
        $lines[] = 'Run `' . self::NAME . ' <part> help` for what a part accepts. A file on its';
        $lines[] = 'own is not a run: with more than one converter here, the part is what says';
        $lines[] = 'which way the data goes, and it is worth naming.';
        $lines[] = '';

        return implode(PHP_EOL, $lines);
    }

    /**
     * The error for a first argument that is not a part, naming the ones that are.
     *
     * A bare word is very likely a file somebody meant to convert, so the correction
     * is offered where there is one to offer rather than the parts listed and nothing
     * else.
     */
    private function unknownPart(string $given): string
    {
        $lines = [sprintf('Unknown part "%s".', $given), ''];

        foreach (self::PARTS as $name => $class) {
            $lines[] = sprintf('  %-10s %s', $name, $class::summary());
        }

        // Only where a single part exists, where it is the only possible answer. With
        // two it is a guess, and guessing which half somebody meant would be the
        // whole failure this avoids.
        if (count(self::PARTS) === 1) {
            $lines[] = '';
            $lines[] = sprintf('Did you mean `%s %s %s`?', self::NAME, array_key_first(self::PARTS), $given);
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * @param resource $stream
     */
    public function write($stream, string $text): void
    {
        // A memory sink opened with `php://memory` in a test accepts a plain write
        // just as a pipe does.
        fwrite($stream, $text);
    }

    public function error(string $message): void
    {
        $this->write($this->err, self::NAME . ': ' . $message . PHP_EOL);
    }
}