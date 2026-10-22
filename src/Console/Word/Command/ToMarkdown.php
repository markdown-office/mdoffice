<?php

declare(strict_types=1);

namespace MarkdownOffice\Console\Word\Command;

use MarkdownOffice\Console\Word\WordApplication;
use MarkdownOffice\Console\CommandLine;
use MarkdownOffice\Console\ConsoleException;
use MarkdownWord\Reverse\Options;

/**
 * `mdoffice word to-markdown` — a Word document in, Markdown out.
 */
final class ToMarkdown extends BaseCommand
{
    /**
     * @param list<string> $argv
     */
    public function execute(array $argv): int
    {
        $command = $this->parseOptions($argv, 'convert a Word document to Markdown.');

        if ($command === null) {
            return WordApplication::SUCCESS;
        }

        $input = $command->input();
        $output = $this->outputPath($command, '.md');

        $markdown = $this->convert($command, $input, $output);

        // The second guard: reading and converting the document is the slow part.
        $this->guardAgainstOverwrite($input, $output);

        $this->application->writeResult($output, $markdown);

        $this->report($input, $output);

        return WordApplication::SUCCESS;
    }

    protected static function other(): string
    {
        return ToDocx::class;
    }

    protected static function formats(): array
    {
        return ['markdown', 'md'];
    }

    /**
     * Read the document and return its Markdown.
     *
     * A `.docx` is a zip archive, and reading one from memory means writing it to a
     * scratch file first. `readInput()` hands back contents either way, so both
     * branches pay for that round trip and the file's own path is good for nothing
     * beyond the direction check the application has already made.
     */
    private function convert(CommandLine $command, ?string $input, string $output): string
    {
        $reader = $this->application->reader($this->readingOptions($command), $this->readDocument($input));

        return $this->application->withMedia($command, $output, static fn (): string => $reader->convert());
    }

    private function readDocument(?string $input): string
    {
        if ($input === null || $input === '-') {
            return $this->application->readInput(null);
        }

        if (!is_file($input)) {
            throw new ConsoleException(sprintf('The file "%s" does not exist.', $input));
        }

        return $this->application->readInput($input);
    }

    private function readingOptions(CommandLine $command): Options
    {
        $overrides = ['headingSetext' => $command->flag('setext')];

        if ($command->flag('no-fence')) {
            $overrides['fenceCodeBlocks'] = false;
        }

        if ($command->flag('no-header')) {
            $overrides['tableHeader'] = false;
        }

        $media = $command->value('media');

        if ($media !== null) {
            // {@see self::withMedia()} runs the reader from the output's own
            // directory, so the reference and the file land on the same path.
            $overrides['mediaDirectory'] = rtrim($media, '/');
        }

        $ending = $command->value('line-ending');

        if ($ending !== null) {
            $overrides['lineEnding'] = self::lineEnding($ending);
        }

        return Options::fromArray($overrides);
    }

    private static function lineEnding(string $name): string
    {
        return match (strtolower($name)) {
            'lf', 'unix', 'n' => "\n",
            'crlf', 'dos', 'windows', 'r' => "\r\n",
            default => throw new ConsoleException(
                sprintf('Unknown line ending "%s".', $name),
                ['Use lf or crlf.'],
            ),
        };
    }

    public static function spec(): array
    {
        return [
            'values' => ['output', 'media', 'line-ending', 'to'],
            'flags' => ['help', 'setext', 'no-fence', 'no-header'],
            'repeated' => [],
            'aliases' => ['o' => 'output', 'h' => 'help'],
        ];
    }

    public static function options(): array
    {
        return [
            ['-o, --output <file>', 'where the Markdown goes; "-" for standard output'],
            ['--media <dir>', 'take the images out of the document into this directory'],
            ['--line-ending <lf|crlf>', 'what the output file uses between lines (default: lf)'],
            ['--setext', 'write first- and second-level headings underlined'],
            ['--no-fence', 'leave monospaced paragraphs as text rather than a code block'],
            ['--no-header', 'do not treat the first table row as a header'],
            ['--to <format>', 'which way to convert; detected from the file otherwise'],
            ['-h, --help', 'this text'],
        ];
    }
}
