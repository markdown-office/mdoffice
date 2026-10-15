<?php

declare(strict_types=1);

namespace MarkdownOffice\Console\Slides;

use Composer\InstalledVersions;
use MarkdownOffice\Console\CommandLine;
use MarkdownOffice\Console\ConsoleException;
use MarkdownOffice\Console\Slides\Command\ToPptx;
use MarkdownOffice\Console\Streams;
use MarkdownPresentation\Exception\InvalidInput;
use Throwable;

/**
 * The `mdoffice slides` command: Markdown to `.pptx`.
 *
 * The same shape as the Word half and for the same reason — the whole interface lives
 * in a class rather than a script, so a run can be driven from a test without a
 * process. Where it differs is that there is exactly one direction. A deck is
 * Markdown on the way in and a `.pptx` on the way out, so there is nothing to detect
 * from a file's first four bytes and `mdoffice slides deck.md` names the one command
 * rather than working it out.
 *
 * The result goes to standard output and everything else to standard error, so
 * `mdoffice slides to-pptx deck.md -o - | pbcopy` does what it looks like it does.
 */
final class SlidesApplication
{
    public const NAME = 'mdoffice slides';

    /** The package this part drives, and so whose version it reports. */
    public const PACKAGE = 'markdown-office/markdown-presentation';

    /** Exit code for a run that did what it was asked. */
    public const SUCCESS = 0;

    /** Exit code for a run that did not finish, whatever the cause. */
    public const FAILURE = 1;

    /**
     * The commands, by the name they are typed under.
     *
     * @var array<string, class-string<\MarkdownOffice\Console\Command\Command>>
     */
    private const COMMANDS = [
        'to-pptx' => ToPptx::class,
    ];

    private readonly Streams $streams;

    /**
     * @param resource|null $out Standard output; defaults to the process's.
     * @param resource|null $err Standard error; defaults to the process's.
     * @param resource|null $in  Standard input; defaults to the process's.
     */
    public function __construct($out = null, $err = null, $in = null)
    {
        $this->streams = new Streams($out, $err, $in);
    }

    /**
     * One line, for the parent to list this part by.
     */
    public static function summary(): string
    {
        return 'Markdown to .pptx';
    }

    /**
     * This part's version, read from what is installed rather than declared here.
     *
     * Not a constant, where the Word half has one. That half has a release to match,
     * and a test holds the constant to its `CHANGELOG.md`; this one has none — it has
     * never been on Packagist, so a constant would be a number no release has ever
     * been, and would go stale without anything noticing. `InstalledVersions` reads
     * the install that is actually there.
     */
    public static function version(): string
    {
        $version = InstalledVersions::isInstalled(self::PACKAGE)
            ? (InstalledVersions::getPrettyVersion(self::PACKAGE) ?? 'unknown version')
            : 'not installed';

        return sprintf("%s %s%s", self::NAME, $version, PHP_EOL);
    }

    /**
     * Run a command and return the exit code.
     *
     * @param list<string> $argv The arguments after the part name.
     */
    public function run(array $argv): int
    {
        $command = $argv[0] ?? null;

        if ($command === null || $command === 'help' || $command === '--help' || $command === '-h') {
            $this->print($this->usage());

            return self::SUCCESS;
        }

        if ($command === '--version' || $command === '-V' || $command === 'version') {
            $this->print(self::version());

            return self::SUCCESS;
        }

        try {
            if (isset(self::COMMANDS[$command])) {
                $class = self::COMMANDS[$command];

                return (new $class($this))->execute(array_slice($argv, 1));
            }

            // Nothing is stripped: whatever is in front is either the deck to read or an
            // option, and the whole line goes to the only command there is.
            return (new ToPptx($this))->execute($argv);
        } catch (ConsoleException|InvalidInput $e) {
            $this->error($e->getMessage());

            if ($e instanceof ConsoleException) {
                foreach ($e->hints() as $hint) {
                    $this->error('  ' . $hint);
                }
            }
        } catch (Throwable $e) {
            // The type, the message and where it happened. No stack trace — the phar has
            // no source paths that mean anything to the person reading it — and the type
            // named is what tells a defect from a mistake.
            $this->error(sprintf('%s: %s', $e::class, $e->getMessage()));
            $this->error(sprintf('  at %s:%d', $e->getFile(), $e->getLine()));
        }

        return self::FAILURE;
    }

    /**
     * The text `mdoffice slides help` prints.
     */
    public function usage(): string
    {
        $lines = [
            self::NAME . ' — convert Markdown to a PowerPoint deck.',
            '',
            'USAGE',
            '  ' . self::NAME . ' <deck>            # the only command, so it is the default',
            '  ' . self::NAME . ' to-pptx  [<deck>] [options]',
            '  ' . self::NAME . ' help',
            '  ' . self::NAME . ' --version',
            '',
            'The deck is read with the Slidev-compatible dialect, so a `---` line closes',
            'the slide being read and `---` on its own opens the frontmatter block.',
            '',
        ];

        foreach (self::COMMANDS as $class) {
            foreach (self::describeCommand($class) as $line) {
                $lines[] = $line;
            }

            $lines[] = '';
        }

        $lines[] = 'EXAMPLES';
        $lines[] = '  ' . self::NAME . ' deck.md                       # -> deck.pptx';
        $lines[] = '  ' . self::NAME . ' to-pptx deck.md -o talk.pptx';
        $lines[] = '  ' . self::NAME . ' cat deck.md | ' . self::NAME . ' to-pptx - -o - | pbcopy';
        $lines[] = '';

        return implode(PHP_EOL, $lines);
    }

    /**
     * The heading and option table for one command.
     *
     * @param class-string<\MarkdownOffice\Console\Command\Command> $command
     * @return list<string>
     */
    public static function describeCommand(string $command): array
    {
        $short = (string) preg_replace('/(?<!^)[A-Z]/', ' $0', substr($command, (int) strrpos($command, '\\') + 1));
        $heading = strtoupper(str_replace('_', ' ', $short));
        $lines = [$heading, str_repeat('-', strlen($heading))];

        foreach ($command::options() as [$flags, $description]) {
            $lines[] = sprintf('  %-30s %s', $flags, $description);
        }

        return $lines;
    }

    /**
     * @return array<string, string>
     */
    public static function aliasMap(): array
    {
        $aliases = [];

        foreach (self::COMMANDS as $class) {
            $aliases = array_merge($aliases, $class::spec()['aliases']);
        }

        return $aliases;
    }

    public function streams(): Streams
    {
        return $this->streams;
    }

    public function readInput(?string $path): string
    {
        return $this->streams->read($path);
    }

    public function writeResult(string $path, string $contents): void
    {
        $this->streams->writeResult($path, $contents, '.mdoffice_');
    }

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
        $this->streams->write($this->streams->err(), self::NAME . ': ' . $message . PHP_EOL);
    }

    public function print(string $text): void
    {
        $this->streams->write($this->streams->out(), $text);
    }

    /**
     * @throws ConsoleException on an unknown option, a missing value, or a value given
     *         to an option that takes none.
     */
    public static function parse(array $argv): CommandLine
    {
        return CommandLine::parse(
            $argv,
            values: ['output'],
            flags: ['help', 'strict'],
            repeated: ['image-base'],
            aliases: self::aliasMap(),
        );
    }
}