<?php

declare(strict_types=1);

namespace MarkdownOffice\Console\Theme;

use MarkdownOffice\Console\Command\Command;
use MarkdownOffice\Console\PartApplication;
use MarkdownOffice\Console\Theme\Command\Init;
use MarkdownPresentation\Exception\InvalidInput;

/**
 * The `mdoffice theme` command: a theme scaffolded from a deck.
 *
 * A theme is a directory — `theme.yml`, `layouts.yml` and `template.pptx` — and
 * making one by hand is most of the work of designing a deck again. This part
 * reads a deck and writes that directory: the colours, the fonts and the type
 * scale out of the deck, the eight layout roles mapped onto the deck's own
 * layouts, and the deck's layouts as the template.
 *
 * It is the one part of the tool that does not convert anything. It reads a
 * `.pptx` and writes a directory, and what it writes is meant to be edited: a
 * scaffold is a first draft of a theme, and the command says which of its
 * numbers it read and which it had to guess.
 */
final class ThemeApplication extends PartApplication
{
    public const NAME = 'mdoffice theme';

    /**
     * The package this part drives, and so whose version it reports.
     *
     * The theme format is the presentation library's: it reads a `theme.yml`, it
     * owns the layout roles and it draws a deck from the result. A theme written
     * here is one that library can read.
     */
    public const PACKAGE = 'markdown-office/markdown-presentation';

    /**
     * The commands, by the name they are typed under.
     *
     * @var array<string, class-string<Command>>
     */
    protected const COMMANDS = [
        'init' => Init::class,
    ];

    public static function summary(): string
    {
        return 'A theme scaffolded from a deck';
    }

    protected static function invalidInput(): string
    {
        return InvalidInput::class;
    }

    /**
     * The text `mdoffice theme help` prints.
     */
    private const PAGE = <<<'HELP'
        {name} — scaffold a theme from a deck.

        USAGE
          {name} init <deck.pptx> [-o <directory>]
          {name} <deck.pptx>            # the default command, so it is init
          {name} help
          {name} --version

        A theme is a directory: `theme.yml` for the colours, the fonts and the type
        scale, `layouts.yml` for the eight layout roles, and `template.pptx` for the
        deck's own layouts. The command reads a deck and writes all three, and the
        directory goes beside the deck unless it is told otherwise.

        The colours come from the deck's own theme part where the deck is drawn in
        that theme, and from the deck's slides where it is not — a deck made in a
        tool that does not do themes carries Office's palette without ever drawing
        in it. The type scale is read off the deck's text, because a deck records a
        size for every paragraph rather than a scale; the command says which sizes
        it found and where it read them from.

        The layouts are mapped by the placeholders they hold, not by their names: a
        deck calls its layouts whatever it likes, and the same layout is called
        different things in two offices. A role the deck has no layout for is
        reported rather than pointed at a layout that is something else.

        The template is the deck's layouts without its slides. A template places,
        and the slides of the deck it came from are not a placement.

        {commands}
        EXAMPLES
          {name} init deck.pptx                     # -> deck/
          {name} init deck.pptx -o themes/acme
          {name} init deck.pptx --force             # replace a theme that is there
        HELP;

    public function usage(): string
    {
        return $this->page(self::PAGE);
    }

    /**
     * Nothing is stripped: whatever is in front is either the deck to read or an
     * option, and the whole line goes to the only command there is.
     */
    protected function defaultCommand(array $argv): int
    {
        return (new Init($this))->execute($argv);
    }
}
