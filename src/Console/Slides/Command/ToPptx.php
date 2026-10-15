<?php

declare(strict_types=1);

namespace MarkdownOffice\Console\Slides\Command;

use MarkdownOffice\Console\Command\Command;
use MarkdownOffice\Console\ConsoleException;
use MarkdownOffice\Console\Slides\SlidesApplication;
use MarkdownPresentation\Document\DeckParser;
use MarkdownPresentation\Render\PresentationRenderer;

/**
 * `mdoffice slides to-pptx` — Markdown in, a PowerPoint deck out.
 *
 * Two steps and nothing in between: the deck is split into slides, and the renderer
 * writes them. The renderer resolves the theme itself, from the deck's own `theme:`,
 * so there is no `--theme` option here to override it — a flag that looked like the
 * Word half's and quietly did something else would be worse than not having it.
 *
 * The renderer clamps a block that does not fit rather than drawing it over whatever
 * comes next, which means the file itself says nothing about what was lost. That is
 * reported on standard error, the same way the Word half reports a format that cannot
 * carry what the document asked for.
 */
final class ToPptx implements Command
{
    public function __construct(private readonly SlidesApplication $application)
    {
    }

    /**
     * @param list<string> $argv The arguments after the command name.
     */
    public function execute(array $argv): int
    {
        $command = SlidesApplication::parse($argv);

        if ($command->flag('help')) {
            $this->application->print(self::help());

            return SlidesApplication::SUCCESS;
        }

        $input = $command->input();
        $markdown = $this->application->readInput($input);

        // Relative image paths in a deck resolve against the deck's own directory,
        // which is the rule a Markdown renderer in an editor would follow — and for a
        // deck read from a pipe there is no directory, so the working directory stands.
        $directory = \MarkdownOffice\Console\Streams::directoryOf($input) ?? (getcwd() ?: '.');

        $renderer = new PresentationRenderer(
            directory: $directory,
            strict: $command->flag('strict'),
            imageRoots: $command->repeated('image-base'),
        );

        $deck = (new DeckParser())->parse($markdown);

        // The renderer writes the file rather than returning its bytes, so to standard
        // output means to a temporary file that is read back and removed. A `.pptx` is
        // a zip archive, and building one in memory would mean carrying PHPWord's
        // writer around a second path for the sake of a pipe.
        $output = $this->outputPath($input, $command->value('output'));
        $written = $renderer->render($deck, $output === '-' ? $this->temporary() : $output);

        $this->reportOverflows($renderer);

        if ($output === '-') {
            $this->application->writeResult('-', (string) file_get_contents($written));
            @unlink($written);
        }

        $this->application->progress(sprintf(
            '%s → %s',
            $input === null || $input === '-' ? 'standard input' : $input,
            $output,
        ));

        return SlidesApplication::SUCCESS;
    }

    private function outputPath(?string $input, ?string $requested): string
    {
        return $this->application->outputPath($input, '.pptx', $requested);
    }

    /**
     * Somewhere to render to when the result is going to standard output.
     *
     * Beside the working directory rather than the system temp directory, because
     * `tempnam()` there gives a path on a different filesystem from the one being
     * read, and the rename a `pptx` needs then crosses that boundary and can fail for
     * a reason the caller cannot see.
     */
    private function temporary(): string
    {
        $directory = sys_get_temp_dir();

        $path = tempnam($directory, '.mdoffice_');

        if ($path === false) {
            throw new ConsoleException('Unable to create a temporary file for the deck.');
        }

        return $path;
    }

    /**
     * What the renderer had to make smaller than the deck asked for.
     */
    private function reportOverflows(PresentationRenderer $renderer): void
    {
        foreach ($renderer->overflows() as $overflow) {
            $this->application->progress(sprintf(
                'slide %d, step %d: the %s block wanted %d but had %d, and was made to fit',
                $overflow['slide'],
                $overflow['step'],
                $overflow['block'],
                $overflow['wanted'],
                $overflow['available'],
            ));
        }
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function options(): array
    {
        return [
            ['-o, --output <file>', 'where to write the deck; "-" for standard output'],
            ['--strict', 'refuse a deck that does not fit rather than shrink it to fit'],
            ['--image-base <path>', 'a further directory images resolve against; may be repeated'],
            ['-h, --help', 'this help'],
        ];
    }

    /**
     * @return array{values: list<string>, flags: list<string>, repeated: list<string>, aliases: array<string, string>}
     */
    public static function spec(): array
    {
        return [
            'values' => ['output'],
            'flags' => ['help', 'strict'],
            'repeated' => ['image-base'],
            'aliases' => ['o' => 'output', 'h' => 'help'],
        ];
    }

    private static function help(): string
    {
        $lines = [
            SlidesApplication::NAME . ' to-pptx — Markdown in, a PowerPoint deck out.',
            '',
            'USAGE',
            '  ' . SlidesApplication::NAME . ' to-pptx [<deck>] [options]',
            '',
            'OPTIONS',
            ...SlidesApplication::describeCommand(self::class),
            '',
        ];

        return implode(PHP_EOL, $lines);
    }
}