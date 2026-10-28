<?php

declare(strict_types=1);

namespace MarkdownOffice\Console\Theme\Command;

use MarkdownOffice\Console\Command\Command;
use MarkdownOffice\Console\CommandLine;
use MarkdownOffice\Console\CommandReference;
use MarkdownOffice\Console\ConsoleException;
use MarkdownOffice\Console\Overwrite;
use MarkdownOffice\Console\Theme\Deck;
use MarkdownOffice\Console\Theme\Manifest;
use MarkdownOffice\Console\Theme\Palette;
use MarkdownOffice\Console\Theme\Roles;
use MarkdownOffice\Console\Theme\Template;
use MarkdownOffice\Console\Theme\ThemeApplication;
use MarkdownOffice\Console\Theme\TypeScale;
use MarkdownPresentation\Exception\Exception as PresentationException;
use MarkdownPresentation\Render\TemplateGeometry;
use MarkdownPresentation\Theme\LayoutRole;
use MarkdownPresentation\Theme\StyleMapping;
use MarkdownPresentation\Theme\ThemeLoader;

/**
 * `mdoffice theme init` — a deck in, a theme directory out.
 *
 * The three files are written from one reading of the deck, and each of them says
 * what it could not decide: the colours that were left at the library's default,
 * the type scale that was inferred, and the layout roles the deck has no layout
 * for. A scaffold that quietly guessed would be a theme nobody could trust, and
 * the point of a theme is that a person edits it.
 *
 * The theme is written and then read back with the library's own reader, so that
 * a file this command cannot read is a failure here rather than a theme that
 * nobody can use.
 *
 * @phpstan-import-type Data from Manifest
 */
final class Init implements Command
{
    /**
     * The line a list of names is written on, under the heading that names the list.
     */
    private const LISTED_NAMES = '    %s';

    public function __construct(private readonly ThemeApplication $application)
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
        );

        if ($command->flag('help')) {
            $this->application->print(CommandReference::help(
                ThemeApplication::NAME,
                static::class,
                'scaffold a theme from a deck.',
            ));

            return ThemeApplication::SUCCESS;
        }

        $input = $command->input();

        if ($input === null) {
            throw new ConsoleException(
                'Name the deck to read.',
                ['A deck is a .pptx file.'],
            );
        }

        $directory = $command->value('output') ?? self::beside($input);

        // The template is a copy of the deck, so a directory that would put the
        // copy on the deck itself is a mistake worth naming before anything is
        // written.
        Overwrite::refuse($input, $directory . '/' . ThemeLoader::TEMPLATE_FILE);

        $this->refuseAnExistingTheme($directory, $command->flag('force'));

        $deck = Deck::open($input);
        $palette = Palette::of($deck);
        $scale = TypeScale::of($deck, $palette);
        $roles = Roles::of($deck->layouts());

        $placed = $this->write($directory, $deck, $palette, $scale, $roles);

        $this->application->print($this->report($input, $directory, $deck, $palette, $scale, $roles, $placed));

        return ThemeApplication::SUCCESS;
    }

    /**
     * The directory a theme goes into when the command line does not say.
     */
    private static function beside(string $input): string
    {
        $directory = pathinfo($input, PATHINFO_DIRNAME);
        $stem = pathinfo($input, PATHINFO_FILENAME);

        // A file whose whole name is an extension has no stem to work with, and
        // the name is then used whole.
        if ($stem === '') {
            $stem = $input;
            $directory = '.';
        }

        $prefix = ($directory === '' || $directory === '.') ? '' : $directory . '/';

        return $prefix . $stem;
    }

    /**
     * Refuse to write over a theme that is already there.
     *
     * A theme is a directory of files somebody has edited, and replacing one
     * without being asked is how an afternoon of work goes missing. The way out is
     * named rather than offered: `--force` says the theme is to be replaced.
     */
    private function refuseAnExistingTheme(string $directory, bool $force): void
    {
        if ($force || ! is_file($directory . '/' . ThemeLoader::THEME_FILE)) {
            return;
        }

        throw new ConsoleException(
            sprintf('There is already a theme in "%s".', $directory),
            ['Pass --force to replace it.'],
        );
    }

    /**
     * The three files of the theme.
     *
     * They are written into a directory of their own and moved into place only
     * once the library's own reader has read them back. A theme this command
     * cannot read is a failure here rather than a theme nobody can use, and a
     * failure must not leave that theme on disk: the next run would refuse to
     * replace it without `--force`, and the reader would be told to force their
     * way past a theme that was never usable.
     *
     * @return array<string, array{title?: \MarkdownPresentation\Render\Box, body?: non-empty-list<\MarkdownPresentation\Render\Box>}>
     *         The boxes the template gives, by layout name.
     */
    private function write(
        string $directory,
        Deck $deck,
        Palette $palette,
        TypeScale $scale,
        Roles $roles,
    ): array {
        $existed = is_dir($directory);
        $staging = $this->stage($directory);

        try {
            $this->application->writeResult(
                $staging . '/' . ThemeLoader::THEME_FILE,
                Manifest::theme($this->comment($deck), $this->data($deck, $palette, $scale)),
            );

            $this->application->writeResult(
                $staging . '/' . ThemeLoader::LAYOUTS_FILE,
                Manifest::layouts($this->layoutsComment($deck, $roles), $roles->mapped()),
            );

            Template::write($deck->path(), $staging . '/' . ThemeLoader::TEMPLATE_FILE);

            $placed = $this->readBack($staging);

            $this->place($staging, $directory);

            return $placed;
        } finally {
            $this->discard($staging);

            // A directory this run created and left empty holds no theme, and a
            // failed run that leaves one behind is a directory the reader has to
            // clean up by hand.
            if (! $existed && self::isEmpty($directory)) {
                @rmdir($directory);
            }
        }
    }

    /**
     * Whether a directory holds nothing.
     */
    private static function isEmpty(string $directory): bool
    {
        $entries = scandir($directory);

        return $entries !== false && count($entries) === 2;
    }

    /**
     * The directory the theme is written into first.
     *
     * Inside the theme's own directory rather than in the system temp directory,
     * because the files are moved into place afterwards and a move across two
     * filesystems is a copy, which is a different thing with different
     * consequences.
     */
    private function stage(string $directory): string
    {
        $staging = $directory . '/.mdoffice-theme-' . bin2hex(random_bytes(6));

        if (! @mkdir($staging, 0o777, true) && ! is_dir($staging)) {
            throw new ConsoleException(sprintf('Unable to write the theme into "%s".', $directory));
        }

        return $staging;
    }

    /**
     * The three files, moved into the directory the theme goes into.
     */
    private function place(string $staging, string $directory): void
    {
        foreach ([ThemeLoader::THEME_FILE, ThemeLoader::LAYOUTS_FILE, ThemeLoader::TEMPLATE_FILE] as $file) {
            if (! @rename($staging . '/' . $file, $directory . '/' . $file)) {
                throw new ConsoleException(sprintf('Unable to write "%s".', $directory . '/' . $file));
            }
        }
    }

    /**
     * The directory the theme was written into first, and what is left of it.
     */
    private function discard(string $staging): void
    {
        foreach (scandir($staging) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            @unlink($staging . '/' . $entry);
        }

        @rmdir($staging);
    }

    /**
     * The theme, as the library's keys.
     *
     * @return Data
     */
    private function data(Deck $deck, Palette $palette, TypeScale $scale): array
    {
        $sizes = $scale->sizes();
        $faces = $scale->faces();

        $fonts = [];

        if ($faces['heading'] !== null) {
            $fonts['heading'] = $faces['heading'];
        }

        if ($faces['body'] !== null) {
            $fonts['body'] = $faces['body'];
        }

        $style = [
            'body' => $sizes['body'],
            'title' => $sizes['title'],
            'cover' => $sizes['cover'],
            'section' => $sizes['section'],
        ];

        // A deck with no type below the size of its titles has no heading scale of
        // its own, and an empty list is not one.
        if ($sizes['headings'] !== []) {
            $style['headings'] = $sizes['headings'];
        }

        $style['leading'] = $scale->leading();
        $style['heading-weight'] = $scale->weight();
        $style['heading-ink'] = $scale->ink();

        return [
            'name' => $deck->title() ?? pathinfo($deck->path(), PATHINFO_FILENAME),
            'description' => sprintf('Scaffolded from %s.', basename($deck->path())),
            'author' => $deck->creator(),
            'dark' => $palette->isDark(),
            'colors' => $palette->colours(),
            'fonts' => $fonts,
            'style' => $style,
        ];
    }

    /**
     * The comment at the top of the theme's manifest.
     *
     * The reader of a theme is a person deciding whether to keep the numbers in
     * it, and the first thing such a reader wants to know is where they came
     * from.
     */
    private function comment(Deck $deck): string
    {
        $lines = [
            sprintf(
                'Scaffolded from "%s" by `mdoffice theme init`.',
                basename($deck->path()),
            ),
            '',
        ];

        if ($deck->usesTheme()) {
            $lines[] = 'The colours are the deck\'s own theme part: the deck is drawn in';
            $lines[] = 'that theme, and its palette is the theme\'s.';
        } else {
            $lines[] = 'The deck carries a theme part it does not draw in, so the colours';
            $lines[] = 'below were read off its slides: the page from the background, the';
            $lines[] = 'ink and the quieter ink from the type, the panel from the filled';
            $lines[] = 'blocks, and the accents from the colours the deck draws with, in';
            $lines[] = 'the order it uses them.';
        }

        $lines[] = '';
        $lines[] = 'The sizes were read off the deck\'s text, because a deck records a';
        $lines[] = 'size for every paragraph rather than a scale: the title is the size';
        $lines[] = 'most slides are headed in, the cover the largest type on the first';
        $lines[] = 'slide, the body the size the prose is written at, and the heading';
        $lines[] = 'scale the sizes below the title.';
        $lines[] = '';
        $lines[] = 'The spacing (margin, gutter, block, rule) and the monospace face are';
        $lines[] = 'the library\'s own: a deck records where its boxes are, not the';
        $lines[] = 'rhythm it draws with, and it says nothing about a face it never';
        $lines[] = 'uses.';

        return implode(PHP_EOL, $lines);
    }

    /**
     * The comment at the top of the layouts file.
     */
    private function layoutsComment(Deck $deck, Roles $roles): string
    {
        $lines = [
            sprintf(
                'Scaffolded from "%s" by `mdoffice theme init`.',
                basename($deck->path()),
            ),
            '',
            'The roles are mapped by the placeholders the layouts hold, not by their',
            'names: a deck calls its layouts whatever it likes, and the same layout',
            'is called different things in two offices.',
        ];

        if ($roles->unmapped() !== []) {
            $lines[] = '';
            $lines[] = sprintf(
                'The deck has no layout for %s, so no role was pointed at one.',
                implode(', ', $roles->unmapped()),
            );
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * Read the theme back with the library's own reader.
     *
     * A theme this command cannot read is a failure here rather than a theme
     * nobody can use, and the reader is the library's because the library is what
     * will read it next.
     *
     * @return array<string, array{title?: \MarkdownPresentation\Render\Box, body?: non-empty-list<\MarkdownPresentation\Render\Box>}>
     *         The boxes the template gives, by layout name.
     */
    private function readBack(string $directory): array
    {
        try {
            $theme = (new ThemeLoader())->load($directory);
            $boxes = (new TemplateGeometry((string) $theme->template()))->boxes();
        } catch (PresentationException $e) {
            throw new ConsoleException(
                sprintf('The theme that was written cannot be read: %s', $e->getMessage()),
                ['This is a defect in `mdoffice theme init`.'],
            );
        }

        return $boxes;
    }

    /**
     * What the command did, and what it could not decide.
     *
     * @param array<string, array{title?: \MarkdownPresentation\Render\Box, body?: list<\MarkdownPresentation\Render\Box>}> $placed
     *        The boxes the template gives, by layout name.
     */
    private function report(
        string $input,
        string $directory,
        Deck $deck,
        Palette $palette,
        TypeScale $scale,
        Roles $roles,
        array $placed,
    ): string {
        $lines = [
            sprintf(
                'mdoffice theme: wrote the theme for "%s" into "%s".',
                basename($input),
                $directory,
            ),
            '',
            sprintf('  %-13s %s', ThemeLoader::THEME_FILE, 'the colours, the fonts and the type scale'),
            sprintf(
                '  %-13s %d of the %d layout roles mapped',
                ThemeLoader::LAYOUTS_FILE,
                count($roles->mapped()),
                count(LayoutRole::names()),
            ),
            sprintf(
                '  %-13s the deck\'s %d layouts, without its %d slides',
                ThemeLoader::TEMPLATE_FILE,
                count($deck->layouts()),
                $deck->slides(),
            ),
            '',
        ];

        $lines[] = '  colours';

        foreach ($palette->notes() as $note) {
            $lines[] = sprintf('    %-10s %-9s %s', $note['slot'], $note['value'], $note['where']);
        }

        $lines[] = '';
        $lines[] = '  type';

        foreach ($scale->notes() as $note) {
            $lines[] = sprintf('    %-14s %-24s %s', $note['what'], $note['value'], $note['where']);
        }

        $lines[] = '';
        $lines[] = '  roles';

        foreach ($roles->notes() as $note) {
            $lines[] = sprintf('    %-11s %-26s %s', $note['role'], $note['name'], $note['why']);
        }

        $defaults = $this->defaults($palette, $scale);

        if ($defaults !== []) {
            $lines[] = '';
            $lines[] = '  left at the library\'s default';
            $lines[] = sprintf(self::LISTED_NAMES, implode(', ', $defaults));
        }

        if ($roles->unmapped() !== []) {
            $lines[] = '';
            $lines[] = '  the deck has no layout for';
            $lines[] = sprintf(self::LISTED_NAMES, implode(', ', $roles->unmapped()));
        }

        if ($roles->unused() !== []) {
            $lines[] = '';
            $lines[] = '  no role was pointed at these layouts';
            $lines[] = sprintf(self::LISTED_NAMES, implode(', ', $roles->unused()));
        }

        $covering = 0;

        foreach ($roles->mapped() as $name) {
            if (isset($placed[$name])) {
                $covering++;
            }
        }

        $lines[] = '';
        $lines[] = sprintf(
            '  the template places %d of the %d roles it maps.',
            $covering,
            count($roles->mapped()),
        );

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    /**
     * The numbers the deck did not answer.
     *
     * @return list<string>
     */
    private function defaults(Palette $palette, TypeScale $scale): array
    {
        $defaults = $palette->defaults();

        foreach ($scale->defaults() as $key) {
            $defaults[] = $key;
        }

        foreach (StyleMapping::KEYS as $key) {
            if (! in_array($key, TypeScale::KEYS, true)) {
                $defaults[] = $key;
            }
        }

        $defaults[] = 'mono';

        return $defaults;
    }

    public static function options(): array
    {
        return [
            ['-o, --output <directory>', 'where to write the theme; beside the deck by default'],
            ['--force', 'replace a theme that is already there'],
            ['-h, --help', 'this help'],
        ];
    }

    public static function spec(): array
    {
        return [
            'values' => ['output'],
            'flags' => ['help', 'force'],
            'repeated' => [],
            'aliases' => ['o' => 'output', 'h' => 'help'],
        ];
    }
}
