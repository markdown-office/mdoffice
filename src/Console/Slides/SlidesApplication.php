<?php

declare(strict_types=1);

namespace MarkdownOffice\Console\Slides;

use MarkdownOffice\Console\CommandLine;
use MarkdownOffice\Console\ConsoleException;
use MarkdownOffice\Console\PartApplication;
use MarkdownOffice\Console\Slides\Command\ToPptx;
use MarkdownPresentation\Exception\InvalidInput;

/**
 * The `mdoffice slides` command: Markdown to `.pptx`.
 *
 * The same shape as the Word half and for the same reason — a run can be driven from
 * a test without a process — and what the two share is in {@see PartApplication}. Where
 * it differs is that there is exactly one direction: a deck is Markdown on the way in
 * and a `.pptx` on the way out, so there is nothing to detect from a file's first four
 * bytes and `mdoffice slides deck.md` names the one command rather than working it out.
 *
 * The result goes to standard output and everything else to standard error, so
 * `mdoffice slides to-pptx deck.md -o - | pbcopy` does what it looks like it does.
 */
final class SlidesApplication extends PartApplication
{
    public const NAME = 'mdoffice slides';

    /**
     * The package this part drives, and so whose version it reports.
     *
     * There is no version of this part's own, and there is not going to be one: this
     * library has never been released, so a constant would name a release that never
     * was and would go stale without anything noticing. {@see self::version()} reads
     * the install that is actually there instead.
     */
    public const PACKAGE = 'markdown-office/markdown-presentation';

    /**
     * The commands, by the name they are typed under.
     *
     * @var array<string, class-string<\MarkdownOffice\Console\Command\Command>>
     */
    protected const COMMANDS = [
        'to-pptx' => ToPptx::class,
    ];

    public static function summary(): string
    {
        return 'Markdown to .pptx';
    }

    protected static function invalidInput(): string
    {
        return InvalidInput::class;
    }

    /**
     * The text `mdoffice slides help` prints.
     */
    public function usage(): string
    {
        return $this->helpPage(
            'convert Markdown to a PowerPoint deck.',
            [
                '  ' . static::NAME . ' <deck>            # the only command, so it is the default',
                '  ' . static::NAME . ' to-pptx  [<deck>] [options]',
                '  ' . static::NAME . ' help',
                '  ' . static::NAME . ' --version',
            ],
            [
                'The deck is read with the Slidev-compatible dialect, so a `---` line closes',
                'the slide being read and `---` on its own opens the frontmatter block.',
            ],
            [
                '  ' . static::NAME . ' deck.md                       # -> deck.pptx',
                '  ' . static::NAME . ' to-pptx deck.md -o talk.pptx',
                '  ' . static::NAME . ' cat deck.md | ' . static::NAME . ' to-pptx - -o - | pbcopy',
            ],
        );
    }

    /**
     * Nothing is stripped: whatever is in front is either the deck to read or an
     * option, and the whole line goes to the only command there is.
     */
    protected function defaultCommand(array $argv): int
    {
        return (new ToPptx($this))->execute($argv);
    }

    /**
     * @param list<string> $argv The arguments, without the program name.
     *
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
