<?php

declare(strict_types=1);

namespace MarkdownOffice\Console\Slides\Command;

use MarkdownOffice\Console\CommandLine;
use MarkdownOffice\Console\ConsoleException;
use MarkdownOffice\Console\Slides\SlidesApplication;
use MarkdownPresentation\PowerPointToMarkdown;
use MarkdownPresentation\Reverse\Options;

/**
 * `mdoffice slides to-markdown` — a PowerPoint deck in, a deck Markdown out.
 *
 * The other end of `to-pptx`, and the same round trip the Word half has: what
 * comes out is the Markdown this tool writes decks in, so it can be edited as
 * text and put back in through `to-pptx` with a different theme.
 *
 * What comes out is the text and the structure. What does not is the design: the
 * type sizes, the colours and the geometry live in the deck's theme and master,
 * and neither travels to a new one.
 */
final class ToMarkdown extends BaseCommand
{
    /**
     * @param list<string> $argv The arguments after the command name.
     */
    public function execute(array $argv): int
    {
        $command = $this->parseOptions($argv, 'convert a PowerPoint deck to Markdown.');

        if ($command === null) {
            return SlidesApplication::SUCCESS;
        }

        $input = $command->input();
        $output = $this->outputPath($command, '.md');

        $markdown = $this->application->withMedia($command, $output, fn (): string => (new PowerPointToMarkdown(
            $this->readDeck($input),
            $this->readingOptions($command),
        ))->convert());

        $this->guardAgainstOverwrite($input, $output);

        $this->application->writeResult($output, $markdown);

        $this->report($input, $output);

        return SlidesApplication::SUCCESS;
    }

    /**
     * The deck, as a path or as the bytes of one.
     *
     * A deck that is a file is named rather than read: the library opens a deck
     * where it lies, and a deck read into memory and written out again is a copy
     * that can only differ from the original.
     *
     * A deck that arrives on standard input is read whole: a `.pptx` is a zip
     * archive, and a zip's directory is at its end, so the bytes of a deck that
     * arrives on a pipe are read before any of them can be placed.
     */
    private function readDeck(?string $input): string
    {
        if ($input === null || $input === '-') {
            return $this->application->readInput(null);
        }

        if (!is_file($input)) {
            throw new ConsoleException(sprintf('The file "%s" does not exist.', $input));
        }

        return $input;
    }

    /**
     * The options the reader takes.
     *
     * The media directory is relative to the deck being written, so the pictures
     * and the reference to them land on the same path: the run reads the deck
     * from the output's own directory, and the reference it writes is the one
     * that is used.
     */
    private function readingOptions(CommandLine $command): Options
    {
        $options = new Options();

        $media = $command->value('media');

        if ($media !== null) {
            $options = $options->withMediaDirectory(rtrim($media, '/'));
        }

        $ending = $command->value('line-ending');

        if ($ending !== null) {
            $options = $options->withLineEnding(self::lineEnding($ending));
        }

        return $options;
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

    /**
     * The command on the other end of the conversion.
     *
     * @return class-string<\MarkdownOffice\Console\Command\Command>
     */
    protected static function other(): string
    {
        return ToPptx::class;
    }

    public static function options(): array
    {
        return [
            ['-o, --output <file>', 'where the Markdown goes; "-" for standard output'],
            ['--media <dir>', 'take the pictures out of the deck into this directory'],
            ['--line-ending <lf|crlf>', 'what the output file uses between lines (default: lf)'],
            ['-h, --help', 'this help'],
        ];
    }

    public static function spec(): array
    {
        return [
            'values' => ['output', 'media', 'line-ending'],
            'flags' => ['help'],
            'repeated' => [],
            'aliases' => ['o' => 'output', 'h' => 'help'],
        ];
    }
}
