<?php

declare(strict_types=1);

use MarkdownOffice\Console\Theme\ThemeApplication;
use MarkdownOffice\Tests\Support\DeckFactory;
use MarkdownOffice\Tests\Support\Scratch;
use MarkdownPresentation\Files\DiskFinder;
use MarkdownPresentation\Theme\LoadedTheme;
use MarkdownPresentation\Theme\ThemeLoader;
use MarkdownPresentation\Theme\ThemeResolver;

/*
 * The theme part: a deck in, a theme directory out.
 *
 * A theme is three files — `theme.yml`, `layouts.yml` and `template.pptx` — and
 * every one of them is read back here with the library's own reader, so a test
 * that says the theme carries the deck's colours is saying that the library can
 * read them.
 *
 * The decks are fixtures built in the test rather than files checked in: a deck
 * whose layouts are named in German is a fixture, and a fixture nobody can read
 * is a fixture that cannot be reviewed.
 */

/**
 * A deck with a navy page, a light ink and a teal accent.
 *
 * @param list<array{name: string, placeholders: list<string>}> $layouts
 * @return string The path of the deck.
 */
function themeDeck(array $layouts = [], array $slides = []): string
{
    return DeckFactory::deck(
        $layouts === [] ? [
            ['name' => 'Titelfolie', 'placeholders' => ['title', 'subTitle']],
            ['name' => 'Titel und Inhalt', 'placeholders' => ['title', 'body']],
            ['name' => 'Leer', 'placeholders' => []],
        ] : $layouts,
        $slides === [] ? [
            [
                'text' => 'The heading of the slide',
                'size' => 26,
                'colour' => 'F8FAFC',
                'face' => 'Cambria',
                'background' => '0D1B2A',
                'layout' => 1,
                'fill' => '00B4D8',
            ],
            [
                'text' => 'A paragraph of the body copy',
                'size' => 14,
                'colour' => 'F8FAFC',
                'face' => 'Calibri',
                'background' => '0D1B2A',
                'layout' => 1,
            ],
        ] : $slides,
    );
}

/**
 * A directory the theme is written into.
 */
function themeDirectory(): string
{
    return Scratch::path('theme', '');
}

/**
 * The theme in a directory, read with the library's own reader.
 *
 * The loader resolves a theme by name and asks a finder where that name is, so a
 * theme in a directory of its own is named by the directory and the finder is told
 * the directory it is in.
 */
function themeOf(string $directory): LoadedTheme
{
    return (new ThemeLoader())->load(
        basename($directory),
        $directory,
        new DiskFinder(theme: [dirname($directory)]),
    );
}

it('writes the three files of a theme into the directory it is told to', function () {
    $directory = themeDirectory();
    $run = runCli(['theme', 'init', themeDeck(), '-o', $directory]);

    expect($run['code'])->toBe(ThemeApplication::SUCCESS)
        ->and(is_file($directory . '/theme.yml'))->toBeTrue()
        ->and(is_file($directory . '/layouts.yml'))->toBeTrue()
        ->and(is_file($directory . '/template.pptx'))->toBeTrue();
});

it('takes the deck\'s own colours into the theme it writes', function () {
    // The deck carries Office's own theme part and does not draw in it, so the
    // colours have to come off its slides: the navy page from the background, the
    // light ink from the type and the teal from the shape that is filled with it.
    $directory = themeDirectory();
    runCli(['theme', 'init', themeDeck(), '-o', $directory]);

    $theme = themeOf($directory)->theme();

    expect($theme->colour('dk1'))->toBe('#0D1B2A')
        ->and($theme->colour('lt1'))->toBe('#F8FAFC')
        ->and($theme->colour('accent1'))->toBe('#00B4D8')
        // The page is dark, which is what says which of the two plays which part.
        ->and($theme->isDark())->toBeTrue();
});

it('takes the deck\'s own theme part when the deck is drawn in it', function () {
    // A deck whose shapes name the theme's own slots is drawn in that theme, and
    // its palette is the theme's rather than what the slides happen to show.
    $deck = DeckFactory::deck(
        [['name' => 'Title Slide', 'placeholders' => ['title', 'subTitle']]],
        [[
            'text' => 'The heading of the slide',
            'size' => 26,
            'colour' => '000000',
            'scheme' => 'dk1',
            'face' => 'Cambria',
            'background' => null,
            'layout' => 0,
        ]],
        [
            'dk1' => '0D1B2A', 'lt1' => 'F8FAFC', 'dk2' => '14263A', 'lt2' => 'F0F6FA',
            'accent1' => '00B4D8', 'accent2' => '087995', 'accent3' => '0096B7',
            'accent4' => 'E63946', 'accent5' => '90E0EF', 'accent6' => '1C2F45',
            'hlink' => '00B4D8', 'folHlink' => 'F0F6FA',
        ],
        ['heading' => 'Cambria', 'body' => 'Calibri'],
    );

    $directory = themeDirectory();
    runCli(['theme', 'init', $deck, '-o', $directory]);

    $theme = themeOf($directory)->theme();

    expect($theme->colour('accent4'))->toBe('#E63946')
        ->and($theme->colour('accent6'))->toBe('#1C2F45');
});

it('maps the layouts by their placeholders and not by their names', function () {
    // The layouts are named in German, which is what a deck made in a German
    // office is called, and the names say nothing the placeholders do not.
    $directory = themeDirectory();
    runCli(['theme', 'init', themeDeck(), '-o', $directory]);

    $layouts = themeOf($directory)->layouts();

    expect($layouts)->toBe([
        'title' => 'Titelfolie',
        'content' => 'Titel und Inhalt',
        'blank' => 'Leer',
    ]);
});

it('writes a theme the library resolves by name', function () {
    // A theme is a directory a deck names, and the library looks one up by that
    // name — through its own resolver, the way a deck beside the theme finds it.
    // The theme is written into a `themes` directory here, because that is where the
    // resolver looks for a name; the command's own default is a directory beside the
    // deck, and a theme there is named by its path.
    $directory = Scratch::directoryPath('resolved');

    $run = runCli(['theme', 'init', themeDeck(), '-o', $directory . '/themes/scaffolded']);

    expect($run['code'])->toBe(ThemeApplication::SUCCESS);

    $resolved = (new ThemeResolver(workingDirectory: $directory, deckDirectory: $directory))
        ->resolve('scaffolded');

    expect($resolved->layouts())->toBe([
        'title' => 'Titelfolie',
        'content' => 'Titel und Inhalt',
        'blank' => 'Leer',
    ]);
});

it('writes a theme beside the deck that a deck names by its path', function () {
    // The command's own default is a directory beside the deck, and the library
    // resolves a theme by name out of a `themes` directory — so the theme this
    // command writes by default is one a deck names by its path.
    $deck = themeDeck();
    $run = runCli(['theme', 'init', $deck]);

    $directory = dirname($deck);

    expect($run['code'])->toBe(ThemeApplication::SUCCESS);

    $resolved = (new ThemeResolver(workingDirectory: $directory, deckDirectory: $directory))
        ->resolve('./' . pathinfo($deck, PATHINFO_FILENAME));

    expect($resolved->layouts())->toBe([
        'title' => 'Titelfolie',
        'content' => 'Titel und Inhalt',
        'blank' => 'Leer',
    ]);
});

it('maps two columns onto the role that has them', function () {
    $directory = themeDirectory();
    runCli(['theme', 'init', themeDeck([
        ['name' => 'Titel, zwei Spalten', 'placeholders' => ['title', 'body', 'body']],
    ]), '-o', $directory]);

    expect(themeOf($directory)->layouts())
        ->toHaveKey('two-column', 'Titel, zwei Spalten');
});

it('reports a role the deck has no layout for rather than pointing one at it', function () {
    // The deck has a title and a column and nothing else, so there is no
    // picture layout and no section layout. Pointing one of them at the layout
    // that is there would be a theme that renders the wrong thing.
    $directory = themeDirectory();
    $run = runCli(['theme', 'init', themeDeck([
        ['name' => 'Titel und Inhalt', 'placeholders' => ['title', 'body']],
    ]), '-o', $directory]);

    $layouts = themeOf($directory)->layouts();

    expect($layouts)->not->toHaveKey('image-slide')
        ->and($layouts)->not->toHaveKey('image-text')
        ->and($layouts)->not->toHaveKey('section')
        // And the run says so, because a role nobody mapped is a decision
        // somebody has to make.
        ->and($run['out'])->toContain('image-slide')
        ->and($run['out'])->toContain('the deck has no layout for');
});

it('writes a layouts file the library can read for a deck with no layouts', function () {
    $deck = DeckFactory::deck(
        [],
        [[
            'text' => 'The heading of the slide',
            'size' => 26,
            'colour' => 'F8FAFC',
            'face' => 'Cambria',
            'background' => '0D1B2A',
            'layout' => 0,
        ]],
    );

    $directory = themeDirectory();
    $run = runCli(['theme', 'init', $deck, '-o', $directory]);

    expect($run['code'])->toBe(ThemeApplication::SUCCESS)
        ->and(themeOf($directory)->layouts())->toBe([])
        ->and((string) file_get_contents($directory . '/layouts.yml'))->not->toContain('layouts:');
});

it('leaves no theme behind when the theme it wrote cannot be read', function () {
    // A deck whose page is no page at all: the template written from it cannot
    // be read back.
    $deck = DeckFactory::deck(
        [['name' => 'Titelfolie', 'placeholders' => ['title', 'subTitle']]],
        [[
            'text' => 'The heading of the slide',
            'size' => 26,
            'colour' => 'F8FAFC',
            'face' => 'Cambria',
            'background' => '0D1B2A',
            'layout' => 0,
        ]],
        [],
        ['heading' => 'Cambria', 'body' => 'Calibri'],
        [],
        ['size' => [0, 0]],
    );

    $directory = themeDirectory();
    $run = runCli(['theme', 'init', $deck, '-o', $directory]);

    expect($run['code'])->toBe(ThemeApplication::FAILURE)
        ->and($run['err'])->toContain('cannot be read')
        ->and(is_file($directory . '/theme.yml'))->toBeFalse()
        ->and(is_file($directory . '/layouts.yml'))->toBeFalse()
        ->and(is_file($directory . '/template.pptx'))->toBeFalse()
        ->and(is_dir($directory))->toBeFalse();

    // The next run finds no theme to replace, so it is not told to use `--force`.
    $again = runCli(['theme', 'init', themeDeck(), '-o', $directory]);

    expect($again['code'])->toBe(ThemeApplication::SUCCESS)
        ->and($again['err'])->not->toContain('--force');
});

it('says which sizes it inferred and where it read them from', function () {
    $run = runCli(['theme', 'init', themeDeck(), '-o', themeDirectory()]);

    // The type scale is read off the deck's text, and a deck records a size for
    // every paragraph rather than a scale, so the numbers are inferred and the
    // reader of the output has to be told which are which.
    expect($run['out'])->toContain('title')
        ->and($run['out'])->toContain('26pt')
        ->and($run['out'])->toContain('Cambria')
        ->and($run['out'])->toContain('Calibri')
        ->and($run['out'])->toContain('the largest type on most of the slides');
});

it('says the heading scale is none for a deck that has none', function () {
    // A deck whose type is all one size has no heading scale of its own.
    $deck = DeckFactory::deck(
        [['name' => 'Titelfolie', 'placeholders' => ['title', 'subTitle']]],
        [[
            'text' => 'The heading of the slide',
            'size' => 26,
            'colour' => 'F8FAFC',
            'face' => 'Cambria',
            'background' => '0D1B2A',
            'layout' => 0,
        ]],
    );

    $run = runCli(['theme', 'init', $deck, '-o', themeDirectory()]);

    expect($run['out'])->toMatch('/headings\s+none/')
        ->and($run['out'])->not->toMatch('/headings\s+pt/');
});

it('does not overwrite a theme that is already there', function () {
    $deck = themeDeck();
    $directory = themeDirectory();

    expect(runCli(['theme', 'init', $deck, '-o', $directory])['code'])->toBe(ThemeApplication::SUCCESS);

    $before = (string) file_get_contents($directory . '/theme.yml');
    $run = runCli(['theme', 'init', $deck, '-o', $directory]);

    expect($run['code'])->toBe(ThemeApplication::FAILURE)
        ->and($run['err'])->toContain('already a theme')
        // The way out is named rather than left to be found.
        ->and($run['err'])->toContain('--force')
        ->and(file_get_contents($directory . '/theme.yml'))->toBe($before);
});

it('replaces a theme that is there when it is told to', function () {
    $deck = themeDeck();
    $directory = themeDirectory();

    runCli(['theme', 'init', $deck, '-o', $directory]);

    $run = runCli(['theme', 'init', $deck, '-o', $directory, '--force']);

    expect($run['code'])->toBe(ThemeApplication::SUCCESS)
        ->and(is_file($directory . '/theme.yml'))->toBeTrue();
});

it('writes the theme beside the deck when it is told no directory', function () {
    $deck = themeDeck();
    $run = runCli(['theme', 'init', $deck]);

    $expected = substr($deck, 0, -strlen('.pptx'));

    expect($run['code'])->toBe(ThemeApplication::SUCCESS)
        ->and(is_file($expected . '/theme.yml'))->toBeTrue()
        ->and(is_file($expected . '/layouts.yml'))->toBeTrue()
        ->and(is_file($expected . '/template.pptx'))->toBeTrue();
});

it('writes a template without the slides of the deck', function () {
    $directory = themeDirectory();
    runCli(['theme', 'init', themeDeck(), '-o', $directory]);

    $zip = new ZipArchive();
    $zip->open($directory . '/template.pptx');

    $slides = 0;
    $layouts = 0;

    for ($index = 0; $index < $zip->numFiles; $index++) {
        $name = (string) $zip->getNameIndex($index);

        if (str_starts_with($name, 'ppt/slides/slide')) {
            $slides++;
        }

        if (str_starts_with($name, 'ppt/slideLayouts/slideLayout')) {
            $layouts++;
        }
    }

    $zip->close();

    // A template full of the original's slides is not a template: every slide of
    // the deck would be in every deck drawn against it.
    expect($slides)->toBe(0)
        ->and($layouts)->toBe(3);
});

it('reports a deck that is not there as a sentence rather than a stack trace', function () {
    $run = runCli(['theme', 'init', Scratch::path('absent', '.pptx'), '-o', themeDirectory()]);

    expect($run['code'])->toBe(ThemeApplication::FAILURE)
        ->and($run['err'])->toContain('does not exist')
        ->and($run['err'])->not->toContain('at /');
});

it('says which deck to read when it is given none', function () {
    $run = runCli(['theme', 'init']);

    expect($run['code'])->toBe(ThemeApplication::FAILURE)
        ->and($run['err'])->toContain('Name the deck to read');
});
