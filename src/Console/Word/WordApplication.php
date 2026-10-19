<?php

declare(strict_types=1);

namespace MarkdownOffice\Console\Word;

use League\CommonMark\Extension\FrontMatter\FrontMatterExtension;
use MarkdownWord\Configuration;
use MarkdownWord\Converter;
use MarkdownOffice\Console\CommandLine;
use MarkdownOffice\Console\ConsoleException;
use MarkdownOffice\Console\PartApplication;
use MarkdownOffice\Console\Command\Command;
use MarkdownOffice\Console\Word\Command\ToDocx;
use MarkdownOffice\Console\Word\Command\ToMarkdown;
use MarkdownOffice\Console\Word\Command\ToOdt;
use MarkdownOffice\Console\Word\Command\ToRtf;
use MarkdownWord\Exception\InvalidInput;
use MarkdownWord\Input;
use MarkdownWord\MarkdownToWord;
use MarkdownWord\Parser\CommonMarkParser;
use MarkdownWord\Reverse\Options as ReverseOptions;
use MarkdownWord\Template\MarkdownTemplate;
use MarkdownWord\WordToMarkdown;

/**
 * The `mdoffice word` command: two commands, one per direction, so that the name of the
 * command says what the run does.
 *
 * The whole interface lives here rather than in a script so that a run can be driven
 * from a test without a process, and so the same code serves both the phar and a plain
 * checkout of the source. What this half and the slides half do alike is in
 * {@see PartApplication}; what is here is what makes this half the Word half — the
 * direction a file has to travel in, the three formats it can travel as, and the
 * configuration the run is rendered from.
 *
 * The result goes to standard output and everything else to standard error, so
 * `mdoffice word to-docx in.md -o - | pbcopy` does what it looks like it does.
 */
final class WordApplication extends PartApplication
{
    public const NAME = 'mdoffice word';

    /**
     * The package whose version {@see self::version()} reports.
     *
     * Not {@see \MarkdownOffice\Console\Application::VERSION}, which is this tool's own
     * and moves for reasons that have nothing to do with the Word half. Two versions
     * on one command line is the point: a bug report naming the version beside
     * `mdoffice word` says which library produced the document.
     */
    public const PACKAGE = 'markdown-office/markdown-word';

    /**
     * The commands, by the name they are typed under.
     *
     * @var array<string, class-string<Command>>
     */
    protected const COMMANDS = [
        'to-docx' => ToDocx::class,
        'to-odt' => ToOdt::class,
        'to-rtf' => ToRtf::class,
        'to-markdown' => ToMarkdown::class,
    ];

    /**
     * Bytes read from the input to work out its direction, handed on again by
     * {@see self::readInput()}.
     */
    private string $peeked = '';

    /**
     * Run a command and return the exit code, with the one known upstream deprecation
     * kept off the terminal for the whole of it.
     *
     * @param list<string> $argv The arguments, without the program name.
     */
    public function run(array $argv): int
    {
        // One filter for the whole run, because a document is written in several
        // places and it has to span all of them. Wrapped rather than installed
        // and taken off again, so a host that installed the filter itself before
        // embedding this keeps it: taking it off would leave the rest of that
        // process with nothing between it and PHPWord's warnings.
        return UpstreamDeprecations::quietly(fn (): int => parent::run($argv));
    }

    public static function summary(): string
    {
        return 'Markdown to .docx, .odt and .rtf, and .docx back to Markdown';
    }

    protected static function invalidInput(): string
    {
        return InvalidInput::class;
    }

    /**
     * The text `mdoffice word help` prints: one section per command over the option table
     * each one publishes for readers. {@see Command::options()} says why that is a
     * second list.
     */
    public function usage(): string
    {
        return $this->helpPage(
            'convert Markdown to Word, and back.',
            [
                '  ' . static::NAME . ' <file>            # the direction is worked out from the file',
                '  ' . static::NAME . ' to-docx     [<markdown>] [options]',
                '  ' . static::NAME . ' to-odt      [<markdown>] [options]',
                '  ' . static::NAME . ' to-rtf      [<markdown>] [options]',
                '  ' . static::NAME . ' to-markdown [<docx>]    [options]',
                '  ' . static::NAME . ' help',
                '  ' . static::NAME . ' --version',
            ],
            [
                'A Word document is a zip archive and Markdown is text, so the file says',
                'which way it has to go. Naming the command anyway is allowed and is what',
                'a script should do; `--to docx`, `--to odt`, `--to rtf` or `--to markdown`',
                'says it in one word and is the only way to be explicit when reading from',
                'standard input.',
                '',
                'to-docx, to-odt and to-rtf are one conversion in three formats, and .docx',
                'is the default of all three. What the other two cannot carry is set out in',
                'the README, and a run that loses any of it says so on standard error.',
                '',
                'The input is read from standard input when no file is named. The result is',
                'written to standard output when the output is "-", or when there is no input',
                'file to derive a name from.',
            ],
            [
                '  ' . static::NAME . ' README.md                    # -> README.docx',
                '  ' . static::NAME . ' README.docx                  # -> README.md',
                '  ' . static::NAME . ' to-docx README.md -o README.docx',
                '  ' . static::NAME . ' to-odt README.md',
                '  ' . static::NAME . ' to-docx notes.md --template report.dotx',
                '  ' . static::NAME . ' to-docx notes.md --plain -o notes.docx',
                '  ' . static::NAME . ' to-markdown report.docx --media assets -o report.md',
                '  ' . static::NAME . ' README.md --to rtf',
                '  ' . static::NAME . ' cat notes.md | ' . static::NAME . ' to-docx - -o - | pbcopy',
            ],
        );
    }

    /**
     * The parser specification of one command, for telling a person which command
     * an option they used actually belongs to.
     *
     * @param class-string<Command> $command
     * @return array<string, string>
     */
    public static function specFor(string $command): array
    {
        $foreign = [];
        $name = self::commandName($command);

        foreach (self::names($command::spec()) as $option) {
            $foreign[$option] = $name;
        }

        return $foreign;
    }

    /**
     * Every option name a specification mentions.
     *
     * @param array{values: list<string>, flags: list<string>, repeated: list<string>, aliases: array<string, string>} $spec
     * @return list<string>
     */
    public static function names(array $spec): array
    {
        return array_values(array_unique(array_merge(
            $spec['values'],
            $spec['flags'],
            $spec['repeated'],
            array_values($spec['aliases']),
        )));
    }

    /**
     * @param class-string<Command> $command
     */
    public static function commandHelp(string $command, string $summary): string
    {
        // The name as it is typed, not as the heading spells it out: the heading
        // reads "TO DOCX" and the usage line has to read `to-docx`.
        $name = self::commandName($command);

        return implode(PHP_EOL, [
            self::NAME . ' ' . $name . ' — ' . $summary,
            '',
            'USAGE',
            '  ' . self::NAME . ' ' . $name . ' [<file>] [options]',
            '',
            'OPTIONS',
            ...self::describeCommand($command),
            '',
        ]) . PHP_EOL;
    }

    /**
     * @param class-string<Command> $command
     */
    private static function commandName(string $command): string
    {
        // `ToMarkdown` reads as `to-markdown`. The matched letter is kept by
        // referring to it in the replacement; replacing it outright would give
        // `to-arkdown`.
        $short = substr($command, (int) strrpos($command, '\\') + 1);

        return strtolower((string) preg_replace('/(?<!^)([A-Z])/', '-$1', $short));
    }

    /**
 * Which way the file has to go when no command was named: `--to` says it in one
 * word, and the file itself otherwise — which is what makes reading from standard
 * input work at all, since a pipe has no name to go on.
 */
    protected function defaultCommand(array $argv): int
    {
        $command = CommandLine::parse(
            $argv,
            values: ['to', 'output', 'config', 'template', 'region', 'images', 'image-base', 'svg-fallback', 'table-width', 'media', 'line-ending'],
            flags: ['help', 'no-images', 'plain', 'setext', 'no-fence', 'no-header'],
            repeated: ['define'],
            aliases: self::aliasMap(),
        );

        $input = $command->input();
        $detected = $input !== null && $input !== '-' ? $this->detectDirection($input) : null;
        $forced = $command->value('to');

        if ($forced === null) {
            $direction = $detected ?? $this->detectDirection($input);
        } else {
            $direction = self::directionFor($forced);

            // Compared by which way the run reads rather than by which command it
            // ends up in: `.docx`, `.odt` and `.rtf` are three answers to the same
            // question, and a file of Markdown is Markdown whichever of them is
            // going to be written.
            if ($detected !== null && self::readsMarkdown($detected) !== self::readsMarkdown($direction)) {
                throw new ConsoleException(
                    sprintf('--to %s does not match "%s".', $forced, $input),
                    [sprintf('That file is %s.', self::readsMarkdown($detected) ? 'Markdown' : 'a Word document')],
                );
            }
        }

        $class = self::COMMANDS[$direction];

        return (new $class($this))->execute($argv);
    }

    private static function directionFor(string $asked): string
    {
        return match (strtolower($asked)) {
            'docx', 'word' => 'to-docx',
            'odt', 'opendocument' => 'to-odt',
            'rtf', 'rich text' => 'to-rtf',
            'markdown', 'md' => 'to-markdown',
            default => throw new ConsoleException(
                sprintf('Unknown format "%s".', $asked),
                ['Use docx, odt, rtf or markdown.'],
            ),
        };
    }

    /**
     * Whether a command takes Markdown in, for the one comparison that must not
     * care which of the three word formats is meant.
     */
    private static function readsMarkdown(string $command): bool
    {
        return $command !== 'to-markdown';
    }

    /**
     * Which way a file has to go, from what it contains.
     *
     * A `.docx` is a zip archive and begins `PK\x03\x04`; Markdown is text and
     * begins with readable characters. Those bytes are part of the format rather
     * than a convention, so this holds for a file with any name at all.
     */
    private function detectDirection(?string $input): string
    {
        $fromStream = $input === null || $input === '-';

        // Suppressed so a missing file gives the sentence below rather than PHP's
        // own warning followed by it.
        $handle = $fromStream ? $this->streams->in() : @fopen($input, 'rb');

        if ($handle === false) {
            throw new ConsoleException(
                sprintf('The file "%s" does not exist.', (string) $input),
                ['Name the file to read, or omit it to read standard input.'],
            );
        }

        // A file is opened separately and thrown away, so nothing is consumed from
        // the input. A stream cannot be rewound, so the bytes read are kept and
        // given back to whoever reads the input next — otherwise the document
        // would arrive four bytes short and no longer be an archive.
        $magic = (string) fread($handle, 4);

        if ($fromStream) {
            $this->peeked .= $magic;
        } else {
            fclose($handle);
        }

        return Input::looksLikeDocument($magic) ? 'to-markdown' : 'to-docx';
    }

    /**
     * @throws ConsoleException
     */
    public function readInput(?string $path): string
    {
        // Whatever the direction check read is given back here, so the command sees the
        // whole input rather than the part after it. The buffer is this half's alone:
        // a deck has one direction and has nothing to detect.
        $peeked = $this->peeked;
        $this->peeked = '';

        return $peeked . $this->streams->read($path);
    }

    /**
     * The configuration for a run: the one a `--config` file names, with
     * `--plain` applied and then the individual options layered on top.
     *
     * @param array<string, mixed> $overrides
     */
    public function configuration(?string $configFile, array $overrides, bool $plain = false): Configuration
    {
        $config = $configFile === null ? new Configuration() : $this->loadConfiguration($configFile);

        if ($plain) {
            $config = $config->withoutDecoration();
        }

        return $overrides === [] ? $config : $config->withOptions($overrides);
    }

    /**
     * Read a configuration file: a PHP file returning either a
     * {@see Configuration} or the array form {@see Configuration::fromArray()}
     * understands.
     *
     * @throws ConsoleException
     */
    private function loadConfiguration(string $path): Configuration
    {
        if (!is_file($path)) {
            throw new ConsoleException(sprintf('The configuration file "%s" does not exist.', $path));
        }

        // Read once per process, with what the file returned kept alongside it:
        // `include_once` answers `true` from the second time, so a second run of the
        // same process — which the test suite does — would reach the checks below
        // holding `true` and refuse a file it had already read once.
        /** @var array<string, mixed> $read */
        static $read = [];

        if (!array_key_exists($path, $read)) {
            $read[$path] = include_once $path;
        }

        $loaded = $read[$path];

        if ($loaded instanceof Configuration) {
            return $loaded;
        }

        if (is_array($loaded)) {
            /** @var array{styles?: array<string, mixed>, options?: array<string, mixed>} $loaded */
            return Configuration::fromArray($loaded);
        }

        throw new ConsoleException(
            sprintf('The configuration file "%s" must return an array or a Configuration.', $path),
            ['Returning `MarkdownWord\Configuration::fromArray([...])` is the usual form.'],
        );
    }

    /**
     * The dialect the command line reads Markdown with.
     *
     * Frontmatter is on here and not in the library's default parser, and that is the
     * difference between a document that carries its own configuration and one that
     * does not: without the extension a leading `---` is a thematic break and the rest
     * of the block arrives as paragraphs of text at the top of the document.
     *
     * It is on because the precedence the README gives has a row for the frontmatter
     * sitting below the command line, and that row means nothing from a terminal
     * unless the terminal reads the block at all.
     *
     * The rest is {@see CommonMarkParser::DEFAULT_FLAVOUR} rather than the `gfm` it
     * used to name, because a document converted from a terminal is a document
     * converted, and the terminal was otherwise the one way to get a parser without
     * directives — so `::toc` and a `header:` worked in a file and not in a shell.
     */
    public static function parser(): CommonMarkParser
    {
        return new CommonMarkParser([
            ...CommonMarkParser::FLAVOURS[CommonMarkParser::DEFAULT_FLAVOUR],
            FrontMatterExtension::class,
        ]);
    }

    /**
     * Typed as the class rather than the {@see Converter} interface, because this is
     * the Markdown direction and its caller asks for `convertTo()` — which the
     * interface does not have, as the reverse direction has nothing to convert to.
     * Declaring the interface here meant `ToDocx` called a method the declared type
     * did not promise, and it worked only because the object behind it is a
     * `MarkdownToWord`.
     *
     * `$overrides` is what the command line itself said, and it sits *above* the
     * frontmatter rather than with the configuration the file names — see
     * {@see \MarkdownWord\Document\ConfigurationMerger} for the order. It is a
     * separate argument rather than merged into `$config` because the two are
     * different claims: the configuration is the base a document is rendered from,
     * and an override is one that outranks what the document says about itself.
     *
     * @param array<string, mixed>|Configuration|null $overrides What the command line
     *        itself said, outranking the document.
     */
    public function converter(
        Configuration $config,
        ?string $source = null,
        Configuration|array|null $overrides = null,
        ?string $theme = null,
    ): MarkdownToWord {
        $converter = new MarkdownToWord($source, $config, self::parser(), $overrides);

        // Only when the flag named one: with no flag the converter reads the `theme:` in
        // the document's own block, which is a document travelling on its own rather than
        // an override of anything said here.
        return $theme === null ? $converter : $converter->withTheme($theme);
    }

    /**
     * The other direction, the same contract.
     *
     * @see self::converter()
     */
    public function reader(ReverseOptions $options, ?string $source = null): Converter
    {
        return new WordToMarkdown($source, $options);
    }

    /**
     * A template filled from the document's own `fields:`, or from `$values` when a
     * caller has them already.
     *
     * @param array<string, string>          $values    The values for the template's
     *        merge fields, by name.
     * @param array<string, mixed>|Configuration|null $overrides What the command line
     *        itself said, outranking the document. See {@see self::converter()}.
     */
    public function template(string $path, Configuration $config, array $values, Configuration|array|null $overrides = null, ?string $theme = null): MarkdownTemplate
    {
        return new MarkdownTemplate($path, $config, $values, self::parser(), $overrides, $theme);
    }
}
