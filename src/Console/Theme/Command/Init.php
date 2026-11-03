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
use MarkdownPresentation\Exception\RetiredThemeFile;
use MarkdownPresentation\Files\DiskFinder;
use MarkdownPresentation\Render\TemplateGeometry;
use MarkdownPresentation\Theme\StyleMapping;
use MarkdownPresentation\Theme\ThemeLoader;

/**
 * `mdoffice theme init` — a deck in, a theme directory out.
 *
 * The theme is written from one reading of the deck, and the file says what it could
 * not decide: the colours that were left at the library's default, the gradient a
 * theme has no place for, the type scale that was inferred, and the layout roles the
 * deck has no layout for. A scaffold that quietly guessed would be a theme nobody
 * could trust, and the point of a theme is that a person edits it.
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

    /**
     * The files of the old format, and the section each one's content goes into.
     *
     * The library refuses these when they are beside a theme, so a theme written into
     * a directory that holds one is a theme no deck can be drawn in. The library's own
     * list of the two that moved is private, and the two lists have to stay in step.
     */
    private const RETIRED = [
        ThemeLoader::LAYOUTS_FILE => 'layouts',
        ThemeLoader::TEXT_STYLES_FILE => 'textStyles',
    ];

    /**
     * What the user can do about a file of the old format.
     */
    private const RETIRED_HINTS = [
        'The command will not write a theme beside a file of the old format.',
        'Delete it, or move it out of the way.',
    ];

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

        $this->refuseARetiredFile($directory);
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
     * Refuse to write into a directory that holds a file of the old format.
     *
     * The library refuses a theme that has one of these beside it, so a theme written
     * into such a directory is a theme no deck can be drawn in. The file the command
     * reads back is the one it wrote and not the directory around it, so the read-back
     * cannot catch this — and `--force` replaces a theme rather than deleting a file
     * somebody has edited.
     *
     * The library's own sentence is the one that is used, so that the two answers to
     * the same question are the same words.
     *
     * @throws ConsoleException when the directory holds a file of the old format.
     */
    private function refuseARetiredFile(string $directory): void
    {
        foreach (self::RETIRED as $file => $section) {
            if (is_file($directory . '/' . $file)) {
                throw new ConsoleException(
                    RetiredThemeFile::moved($directory, $file, $section)->getMessage(),
                    self::RETIRED_HINTS,
                );
            }
        }

        if (is_file($directory . '/' . ThemeLoader::STYLES_FILE)) {
            throw new ConsoleException(
                RetiredThemeFile::otherHalf($directory, ThemeLoader::STYLES_FILE)->getMessage(),
                self::RETIRED_HINTS,
            );
        }
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
     * The files of the theme.
     *
     * They are written into a directory of their own and moved into place only once
     * the library's own reader has read them back. A theme that cannot be read must not
     * be left on disk: the next run would refuse to replace it without `--force`, and
     * the reader would be told to force their way past a theme that was never usable.
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
                Manifest::theme(
                    $this->comment($deck, $palette, $roles),
                    $this->data($deck, $palette, $scale, $roles),
                ),
            );

            $files = [ThemeLoader::THEME_FILE];

            // The template is the deck's own layouts, and a deck's layouts belong to a
            // master: a deck with no master has no template to write.
            if ($deck->hasMaster()) {
                Template::write($deck->path(), $staging . '/' . ThemeLoader::TEMPLATE_FILE);

                $files[] = ThemeLoader::TEMPLATE_FILE;
            }

            $placed = $this->readBack($staging);

            $this->moveIntoPlace($staging, $directory, $files);

            return $placed;
        } finally {
            $this->discard($staging);

            // A failed run must not leave a directory the reader has to clean up by hand.
            if (! $existed && self::isEmpty($directory)) {
                @rmdir($directory);
            }
        }
    }

    /**
     * The directory the theme is written into first.
     *
     * Inside the theme's own directory rather than in the system temp directory,
     * because the files are moved into place afterwards and a move across two
     * filesystems is a copy.
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
     * @param list<string> $files The files that were written.
     */
    private function moveIntoPlace(string $staging, string $directory, array $files): void
    {
        foreach ($files as $file) {
            if (! @rename($staging . '/' . $file, $directory . '/' . $file)) {
                throw new ConsoleException(sprintf('Unable to write "%s".', $directory . '/' . $file));
            }
        }
    }

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

    private static function isEmpty(string $directory): bool
    {
        $entries = scandir($directory);

        return $entries !== false && count($entries) === 2;
    }

    /**
     * The theme, as the library's keys.
     *
     * @return Data
     */
    private function data(Deck $deck, Palette $palette, TypeScale $scale, Roles $roles): array
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
            'name' => $deck->name(),
            'description' => sprintf('Scaffolded from %s.', basename($deck->path())),
            'author' => $deck->creator(),
            'dark' => $palette->isDark(),
            'colors' => $palette->colours(),
            'fonts' => $fonts,
            'style' => $style,
            'layouts' => $roles->complete(),
        ];
    }

    /**
     * The comment at the top of the theme's file.
     *
     * The reader of a theme is a person deciding whether to keep the numbers in
     * it, and the first thing such a reader wants to know is where they came
     * from.
     */
    private function comment(Deck $deck, Palette $palette, Roles $roles): string
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
            $lines[] = 'below were read off its slides: the ink and the quieter ink from';
            $lines[] = 'the type, the panel from the filled blocks, and the accents from';
            $lines[] = 'the colours the deck draws with, in the order it uses them.';
        }

        // Said in both cases: a deck drawn in its own theme may still leave its page
        // to the library, and the file has to say which of the two it is.
        $lines[] = '';
        $lines[] = sprintf('The page is %s.', $palette->pageFrom());

        foreach ($palette->omissions() as $omission) {
            $lines[] = '';
            $lines[] = wordwrap(ucfirst($omission) . '.', 60);
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
        $lines[] = '';
        $lines[] = 'The roles are mapped by the placeholders the layouts hold, not by their';
        $lines[] = 'names: a deck calls its layouts whatever it likes, and the same layout';
        $lines[] = 'is called different things in two offices.';
        $lines[] = '';

        if ($roles->unmapped() === []) {
            $lines[] = 'The deck has a layout for every role, so every name below is the';
            $lines[] = 'deck\'s own.';
        } else {
            $lines[] = sprintf('The deck has no layout for %s.', implode(', ', $roles->unmapped()));
            $lines[] = 'Those roles take the library\'s own names: the names the library draws';
            $lines[] = 'the layouts it generates under.';

            if ($roles->renamed() !== []) {
                $lines[] = '';
                $lines[] = 'Where the deck\'s layout is called the library\'s name for a role,';
                $lines[] = 'that role takes a name of its own: two roles may not share a name.';

                foreach ($roles->renamed() as $role => $library) {
                    $lines[] = '';
                    $lines[] = wordwrap(sprintf(
                        '%s takes a name of its own: the deck\'s layout is called %s.',
                        $role,
                        $library,
                    ), 60);
                }
            }
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
     * The loader resolves a theme by name rather than by directory, so the name is
     * the directory's own and the finder is told where that name is to be found.
     *
     * @return array<string, array{title?: \MarkdownPresentation\Render\Box, body?: non-empty-list<\MarkdownPresentation\Render\Box>}>
     *         The boxes the template gives, by layout name.
     */
    private function readBack(string $directory): array
    {
        try {
            $theme = (new ThemeLoader())->load(
                basename($directory),
                $directory,
                new DiskFinder(theme: [dirname($directory)]),
            );

            $template = $theme->directory() . '/' . ThemeLoader::TEMPLATE_FILE;

            // A theme with no master carries no template, and a theme with no
            // template has no boxes to read.
            $boxes = is_file($template)
                ? (new TemplateGeometry($template))->boxes()
                : [];
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
            sprintf(
                '  %-13s %s',
                ThemeLoader::THEME_FILE,
                'the colours, the fonts, the type scale and the layout roles',
            ),
        ];

        if ($deck->hasMaster()) {
            $lines[] = sprintf(
                '  %-13s the deck\'s %d layouts, without its %d slides',
                ThemeLoader::TEMPLATE_FILE,
                count($deck->layouts()),
                $deck->slides(),
            );
        }

        $lines[] = '';

        $lines[] = '  colours';

        foreach ($palette->notes() as $note) {
            $lines[] = sprintf('    %-10s %-9s %s', $note['slot'], $note['value'], $note['where']);
        }

        if ($palette->omissions() !== []) {
            $lines[] = '';
            $lines[] = '  not in the theme';

            foreach ($palette->omissions() as $omission) {
                $lines[] = '    ' . $omission;
            }
        }

        $lines[] = '';
        $lines[] = '  type';

        foreach ($scale->notes() as $note) {
            $lines[] = sprintf('    %-14s %-24s %s', $note['what'], $note['value'], $note['where']);
        }

        $lines[] = '';
        $lines[] = '  roles';

        $reasons = [];

        foreach ($roles->notes() as $note) {
            $reasons[$note['role']] = $note['why'];
        }

        foreach ($roles->renamed() as $role => $library) {
            $reasons[$role] = sprintf('a name of its own: the deck\'s layout is called %s', $library);
        }

        foreach ($roles->complete() as $role => $name) {
            $lines[] = sprintf(
                '    %-11s %-26s %s',
                $role,
                $name,
                $reasons[$role] ?? "the library's own name",
            );
        }

        $lines[] = '';
        $lines[] = $this->split($roles);

        $colours = $palette->defaultColours();
        $numbers = $this->defaults($scale);

        if ($colours !== [] || $numbers !== []) {
            $lines[] = '';
            $lines[] = '  left at the library\'s default';
        }

        if ($colours !== []) {
            // The colour with the name, because what a person edits is the colour: a slot
            // that says only that it was left alone does not say what it was left as.
            $lines[] = sprintf('    %-8s %s', 'colours', implode(', ', array_map(
                static fn (string $slot, string $value): string => sprintf('%s %s', $slot, $value),
                array_keys($colours),
                array_values($colours),
            )));
        }

        if ($numbers !== []) {
            $lines[] = sprintf('    %-8s %s', 'type', implode(', ', $numbers));
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

        if (! $deck->hasMaster()) {
            $lines[] = '';
            $lines[] = '  the deck has no master, so the theme carries no template.';
        } else {
            $covering = 0;

            foreach ($roles->mapped() as $name) {
                if (isset($placed[$name])) {
                    $covering++;
                }
            }

            $lines[] = '';
            $lines[] = sprintf(
                '  the template places %d of the %d roles the deck\'s own layouts map.',
                $covering,
                count($roles->mapped()),
            );
        }

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    /**
     * How the roles were split between the deck's own layouts and the library's.
     *
     * A reader of the file has to be able to tell which names are the deck's and which
     * are the library's, so the two are counted apart — and a role that had to take a
     * name of its own is counted apart from them too, because its name is neither.
     */
    private function split(Roles $roles): string
    {
        $total = count($roles->complete());
        $own = count($roles->mapped());
        $renamed = count($roles->renamed());

        if ($renamed === 0) {
            return sprintf(
                '  the deck\'s own layouts map %d of the %d roles; the library\'s own names map the other %d.',
                $own,
                $total,
                $total - $own,
            );
        }

        return sprintf(
            '  the deck\'s own layouts map %d of the %d roles; the library\'s own names map %d, and the rest take a name of their own.',
            $own,
            $total,
            $total - $own - $renamed,
        );
    }

    /**
     * The numbers the deck did not answer.
     *
     * The type scale and the spacing are the same kind of thing — a number the deck did
     * not carry — and the colours are another kind of thing altogether, so the two are
     * reported apart rather than in one list that mixes a colour with four measurements.
     *
     * @return list<string>
     */
    private function defaults(TypeScale $scale): array
    {
        $defaults = $scale->defaults();

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
