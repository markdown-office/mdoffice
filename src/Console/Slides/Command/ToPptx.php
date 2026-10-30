<?php

declare(strict_types=1);

namespace MarkdownOffice\Console\Slides\Command;

use MarkdownOffice\Console\Slides\SlidesApplication;
use MarkdownOffice\Console\Streams;
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
final class ToPptx extends BaseCommand
{
    /**
     * @param list<string> $argv The arguments after the command name.
     */
    public function execute(array $argv): int
    {
        $command = $this->parseOptions($argv, 'convert Markdown to a PowerPoint deck.');

        if ($command === null) {
            return SlidesApplication::SUCCESS;
        }

        $input = $command->input();
        $markdown = $this->application->readInput($input);

        // Relative image paths in a deck resolve against the deck's own directory,
        // which is the rule a Markdown renderer in an editor would follow — and for a
        // deck read from a pipe there is no directory, so the working directory stands.
        $directory = Streams::directoryOf($input) ?? (getcwd() ?: '.');

        $renderer = new PresentationRenderer(
            directory: $directory,
            strict: $command->flag('strict'),
            imageRoots: $command->repeated('image-base'),
        );

        $deck = (new DeckParser())->parse($markdown);

        $output = $this->outputPath($command, '.pptx');

        // The deck is drawn once, whichever way it goes: a run that asked for the
        // bytes and the file would draw it twice.
        if ($output === '-') {
            $bytes = $renderer->toString($deck);
        } else {
            $renderer->render($deck, $output);
            $bytes = null;
        }

        $this->reportOverflows($renderer);
        $this->reportUnknownFonts($renderer);

        if ($bytes !== null) {
            $this->application->writeResult('-', $bytes);
        }

        $this->report($input, $output);

        return SlidesApplication::SUCCESS;
    }

    /**
     * The command on the other end of the conversion.
     *
     * @return class-string<\MarkdownOffice\Console\Command\Command>
     */
    protected static function other(): string
    {
        return ToMarkdown::class;
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
     * The faces a theme named that the renderer had no table of advance widths for.
     *
     * The same trade the renderer makes, said here rather than hidden in it: the deck was
     * written, and its text was measured with the reference face's widths instead of its
     * own. A reader of the output can see that the file exists and that its measurements
     * were not the deck's own.
     *
     * The renderer says *where* the face was named rather than which slot of the theme it
     * was, because a face may be named in the theme, in a text style of it, in a box of the
     * deck, in the chrome of a template or on the number of a slide — and a reader who is
     * told the place can find it.
     */
    private function reportUnknownFonts(PresentationRenderer $renderer): void
    {
        foreach ($renderer->unknownFonts() as $font) {
            $this->application->progress(sprintf(
                'the face "%s" has no table of advance widths, so its text was measured '
                . 'with the reference face\'s widths. It is named by %s.',
                $font['face'],
                $font['where'],
            ));
        }
    }

    public static function options(): array
    {
        return [
            ['-o, --output <file>', 'where to write the deck; "-" for standard output'],
            ['--strict', 'refuse a deck that does not fit rather than shrink it to fit'],
            ['--image-base <path>', 'a further directory images resolve against; may be repeated'],
            ['-h, --help', 'this help'],
        ];
    }

    public static function spec(): array
    {
        return [
            'values' => ['output'],
            'flags' => ['help', 'strict'],
            'repeated' => ['image-base'],
            'aliases' => ['o' => 'output', 'h' => 'help'],
        ];
    }

}
