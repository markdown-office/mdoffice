<?php

declare(strict_types=1);

namespace MarkdownOffice\Console;

use Composer\InstalledVersions;
use MarkdownOffice\Console\Command\Command;
use Throwable;

/**
 * What every part of the tool does: the streams, the two questions a run can answer
 * without converting anything, and the commands it dispatches to.
 *
 * The Word half and the slides half were written as copies of one another — the same
 * three streams, the same `help` and `--version`, the same refusal sentences — and
 * they stay separate files rather than becoming one class with a flag because what is
 * left of them really does differ: a document has two directions and a deck has one.
 *
 * So a part sets {@see self::NAME}, {@see self::PACKAGE} and {@see self::COMMANDS},
 * and answers {@see self::summary()}, {@see self::usage()},
 * {@see self::defaultCommand()} and {@see self::invalidInput()}. Everything else in a
 * run is here, so a sentence printed by one half cannot drift from the same sentence
 * in the other.
 */
abstract class PartApplication
{
    /** How the part is typed on a command line. Every part spells its own. */
    public const NAME = '';

    /** The package whose version {@see self::version()} reports. */
    public const PACKAGE = '';

    /** Exit code for a run that did what it was asked. */
    public const SUCCESS = 0;

    /** Exit code for a run that did not finish, whatever the cause. */
    public const FAILURE = 1;

    /**
     * The commands, by the name they are typed under.
     *
     * One table per part, read by the dispatcher, by the usage text and by the alias
     * table, so a command cannot be missing from one of the three.
     *
     * @var array<string, class-string<Command>>
     */
    protected const COMMANDS = [];

    /**
     * The streams and the file handling, which are the same whichever part is running.
     */
    protected readonly Streams $streams;

    /**
     * @param Streams|null $streams The process's own streams when none is given.
     */
    public function __construct(?Streams $streams = null)
    {
        $this->streams = $streams ?? new Streams();
    }

    /**
     * Run a command and return the exit code.
     *
     * @param list<string> $argv The arguments, without the part name.
     */
    public function run(array $argv): int
    {
        try {
            return $this->dispatch($argv);
        } catch (Throwable $e) {
            return $this->reportFailure($e);
        }
    }

    /**
     * One line, for the parent to list this part by.
     */
    abstract public static function summary(): string;

    /**
     * The text `mdoffice <part> help` prints.
     */
    abstract public function usage(): string;

    /**
     * The run when the first argument is not a command: the part's own idea of what
     * the arguments mean. A document works its direction out from the file; a deck
     * has one direction and takes the whole line as it stands.
     *
     * @param list<string> $argv The whole command line, with no command name in it.
     */
    abstract protected function defaultCommand(array $argv): int;

    /**
     * The library's own exception for something the caller can put right by naming
     * something else, which {@see self::reportFailure()} prints as a refusal rather
     * than as a defect. Each half reads its own library's.
     *
     * @return class-string<Throwable>
     */
    abstract protected static function invalidInput(): string;

    /**
     * This part's version, read from the install rather than declared here.
     *
     * A library that is not installed reads as `not installed` rather than being left
     * out: a version list that quietly omits a half is the one thing that would make
     * this answer useless for the job it exists for.
     */
    public static function version(): string
    {
        $version = InstalledVersions::isInstalled(static::PACKAGE)
            ? (InstalledVersions::getPrettyVersion(static::PACKAGE) ?? 'unknown version')
            : 'not installed';

        return sprintf('%s %s%s', static::NAME, $version, PHP_EOL);
    }

    /**
     * @param list<string> $argv The arguments after the part name.
     */
    private function dispatch(array $argv): int
    {
        $command = $argv[0] ?? null;

        if ($command === null || in_array($command, ['help', '--help', '-h'], true)) {
            $this->print($this->usage());

            return self::SUCCESS;
        }

        if (in_array($command, ['version', '--version', '-V'], true)) {
            $this->print(static::version());

            return self::SUCCESS;
        }

        return $this->runNamed($command, $argv);
    }

    /**
     * @param list<string> $argv The whole line, the command still at its head.
     */
    private function runNamed(?string $command, array $argv): int
    {
        if ($command !== null && isset(static::COMMANDS[$command])) {
            $class = static::COMMANDS[$command];

            return (new $class($this))->execute(array_slice($argv, 1));
        }

        // Nothing is stripped: whatever is in front is either the input to read or an
        // option, and the whole line goes to the command that gets chosen.
        return $this->defaultCommand($argv);
    }

    /**
     * A refusal, in a sentence rather than a stack trace.
     *
     * A `ConsoleException` and the library's own invalid-input exception are the same
     * kind of failure — something the caller handed in that they can hand in
     * differently — and their messages are written for a person reading them. Only
     * the first carries hints. Anything else is a defect in this library and is
     * reported as one: the type, the message and where it happened, which is what
     * tells a defect from a mistake.
     */
    private function reportFailure(Throwable $e): int
    {
        if ($e instanceof ConsoleException || is_a($e, static::invalidInput())) {
            $this->error($e->getMessage());

            foreach ($e instanceof ConsoleException ? $e->hints() : [] as $hint) {
                $this->error('  ' . $hint);
            }

            return self::FAILURE;
        }

        $this->error(sprintf('%s: %s', $e::class, $e->getMessage()));
        $this->error(sprintf('  at %s:%d', $e->getFile(), $e->getLine()));

        return self::FAILURE;
    }

    /**
     * Fill a help template: `{name}` for the program as it is invoked, `{commands}`
     * for the sections {@see CommandReference::sections()} generates.
     *
     * Templates rather than an assembled array because the pages are prose, and two
     * arrays of prose fragments is what the duplication was: the two halves had the
     * same forty-odd lines of array in the same order, differing only in the words,
     * which is a page written twice.
     */
    final protected function page(string $template): string
    {
        return str_replace(
            ['{name}', '{commands}'],
            [static::NAME, implode(PHP_EOL, CommandReference::sections(static::COMMANDS))],
            $template,
        ) . PHP_EOL;
    }

    /**
     * @throws ConsoleException
     */
    public function readInput(?string $path): string
    {
        return $this->streams->read($path);
    }

    /**
     * Write the result: to a file, or to standard output when the path is `-`.
     *
     * @throws ConsoleException
     */
    public function writeResult(string $path, string $contents): void
    {
        $this->streams->writeResult($path, $contents, '.mdoffice_');
    }

    /**
     * Where the result should go, when the user did not say.
     *
     * @see \MarkdownOffice\Console\Streams::outputPath()
     */
    public function outputPath(?string $input, string $extension, ?string $requested): string
    {
        return $this->streams->outputPath($input, $extension, $requested);
    }

    /**
     * Note progress on standard error, so a piped standard output stays clean.
     */
    public function progress(string $message): void
    {
        $this->error($message);
    }

    public function error(string $message): void
    {
        $this->streams->error(static::NAME, $message);
    }

    /**
     * The Markdown refers to the images beside it and the images are written
     * beside it, so both paths are the same — which means the reader has to be
     * running from the output's own directory to agree with the file. The
     * directory is changed for the duration and put back afterwards, including
     * when the conversion throws.
     *
     * @param callable(): string $read The conversion, run in the directory.
     */
    final public function withMedia(CommandLine $command, string $output, callable $read): string
    {
        if ($command->value('media') === null || $output === '-') {
            return $read();
        }

        $directory = dirname(realpath($output) ?: $output);
        $previous = getcwd();

        if ($previous === false || !@chdir($directory)) {
            // The Markdown still has to be written, so the images are left where
            // the reader puts them and the reference it writes is used as it comes
            // out.
            return $read();
        }

        try {
            return $read();
        } finally {
            @chdir($previous);
        }
    }

    /**
     * Write to standard output, for the help — the only thing that goes out this way;
     * a converted document leaves through {@see self::writeResult()}. Both go through
     * `Streams::write()` rather than reaching for `STDOUT`, which is what makes a run
     * drivable from a test.
     */
    public function print(string $text): void
    {
        $this->streams->write($this->streams->out(), $text);
    }
}
