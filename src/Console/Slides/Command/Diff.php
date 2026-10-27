<?php

declare(strict_types=1);

namespace MarkdownOffice\Console\Slides\Command;

use MarkdownOffice\Console\Command\Command;
use MarkdownOffice\Console\CommandLine;
use MarkdownOffice\Console\CommandReference;
use MarkdownOffice\Console\ConsoleException;
use MarkdownOffice\Console\Slides\Diff\Comparison;
use MarkdownOffice\Console\Slides\Diff\Deck;
use MarkdownOffice\Console\Slides\SlidesApplication;

/**
 * `mdoffice slides diff` — two decks in, a report of what changed out.
 *
 * The tool for the question every template workflow asks: *I changed the design,
 * what did it break?* The two decks are read as the packages they are and
 * compared part by part — the text of every shape, where every shape is, and
 * what colour every fill and every run is drawn in. The archive's own bytes are
 * never compared: a `.pptx` carries a timestamp in every entry it holds and
 * another in the document's own properties, so two correct renderings of the same
 * deck differ most of the time and comparing the bytes would report every deck
 * as changed.
 *
 * The report goes to standard error, the way the report of a deck that did not
 * fit goes, and it says what it looked at as well as what it found: a reader told
 * "no differences" by a tool that looked at half the deck is worse off than one
 * told what was looked at.
 *
 * The exit code is the program's own: a run that compared two decks and found
 * differences did what it was asked, and says so in its report. A build that
 * wants the difference to stop it asks for it with `--exit-code`.
 */
final class Diff implements Command
{
    public function __construct(private readonly SlidesApplication $application)
    {
    }

    /**
     * @param list<string> $argv The arguments after the command name.
     */
    public function execute(array $argv): int
    {
        $command = CommandLine::parse(
            $argv,
            values: static::spec()['values'],
            flags: static::spec()['flags'],
            repeated: static::spec()['repeated'],
            aliases: static::spec()['aliases'],
            foreign: self::foreign(),
        );

        if ($command->flag('help')) {
            $this->application->print(CommandReference::help(
                SlidesApplication::NAME,
                static::class,
                'compare two decks and report what changed.',
                sprintf('%s diff <before.pptx> <after.pptx> [options]', SlidesApplication::NAME),
            ));

            return SlidesApplication::SUCCESS;
        }

        $decks = $command->operands();

        if (count($decks) < 2) {
            throw new ConsoleException(
                'Name the two decks to compare.',
                ['A diff takes two: the deck before and the deck after.'],
            );
        }

        $before = Deck::open($decks[0]);
        $after = Deck::open($decks[1]);
        $comparison = Comparison::of($before, $after);

        $this->report($before, $after, $comparison);

        if ($comparison->hasDifferences() && $command->flag('exit-code')) {
            return SlidesApplication::FAILURE;
        }

        return SlidesApplication::SUCCESS;
    }

    /**
     * The options of the commands this one is not.
     *
     * So that reaching for an option of the other directions by mistake names
     * the command it does belong to.
     *
     * @return array<string, string>
     */
    private static function foreign(): array
    {
        $foreign = [];

        foreach ([ToPptx::class, ToMarkdown::class] as $command) {
            $foreign += CommandReference::specFor($command);
        }

        return $foreign;
    }

    /**
     * What changed, and what was looked at to say so.
     */
    private function report(Deck $before, Deck $after, Comparison $comparison): void
    {
        $lines = $comparison->lines();

        $this->application->progress(sprintf(
            "diff of %s against %s\n\n%s\n\n%s\n%s\n%s",
            $before->path(),
            $after->path(),
            $lines === [] ? 'Nothing changed.' : implode(PHP_EOL, $lines),
            $this->compared($comparison),
            $this->units(),
            $this->notCompared(),
        ));
    }

    /**
     * What was compared.
     */
    private function compared(Comparison $comparison): string
    {
        $paired = $comparison->paired();

        if ($paired === 0) {
            return 'No slide of the two decks could be paired, so nothing was compared.';
        }

        $slides = $paired === 1 ? 'the one slide' : sprintf('the %d slides', $paired);

        return sprintf(
            'Compared the text of every shape, the position and the size of every shape that states them,'
            . ' and the colour of every fill and every run, on %s the two decks have in common.'
            . ' A colour that names a slot of the theme is compared as the colour that slot holds.',
            $slides,
        );
    }

    /**
     * What the report counts in, and how the slides are numbered.
     */
    private function units(): string
    {
        return 'Positions and sizes are in millimetres, from the top left of the slide.'
            . ' Slides are numbered as in the first deck.';
    }

    /**
     * What was not compared.
     *
     * Said, because a diff that quietly skips what it could not compare is the
     * one thing a reader cannot see.
     */
    private function notCompared(): string
    {
        return 'Not compared: the notes, the layouts, the masters, the theme\'s own fonts, the pictures,'
            . ' the document\'s own properties, a fill that is not a single colour, the frame of a group of'
            . ' shapes, and the position or the size of a shape that takes it from the layout.';
    }

    public static function options(): array
    {
        return [
            ['--exit-code', 'exit with 1 when the decks differ, for a build to act on'],
            ['-h, --help', 'this help'],
        ];
    }

    public static function spec(): array
    {
        return [
            'values' => [],
            'flags' => ['help', 'exit-code'],
            'repeated' => [],
            'aliases' => ['h' => 'help'],
        ];
    }
}
