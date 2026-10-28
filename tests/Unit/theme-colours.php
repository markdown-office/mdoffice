<?php

declare(strict_types=1);

use MarkdownOffice\Tests\Support\DeckFactory;
use MarkdownOffice\Tests\Support\Scratch;

/*
 * The colours of a theme, read out of a deck.
 *
 * A deck has two answers to the question "what colours are you in?", and they do
 * not always agree: the theme part holds a palette of twelve slots, and the slides
 * hold the colours that are actually on the page. A deck that names the theme's
 * slots is drawn in that theme and its palette is the theme's; a deck that spells
 * its colours out is read for what it shows.
 *
 * The decks here are fixtures, and they are built in the test rather than checked
 * in: a deck whose theme part names a colour the slides never use is a fixture,
 * and a fixture nobody can read is a fixture that cannot be reviewed.
 */

/**
 * The twelve slots of a theme part, in the order the format lists them.
 *
 * @return array<string, string>
 */
function themeScheme(): array
{
    return [
        'dk1' => '0D1B2A', 'lt1' => 'F8FAFC', 'dk2' => '14263A', 'lt2' => 'F0F6FA',
        'accent1' => '00B4D8', 'accent2' => '087995', 'accent3' => '0096B7',
        'accent4' => 'E63946', 'accent5' => '90E0EF', 'accent6' => '1C2F45',
        'hlink' => '00B4D8', 'folHlink' => 'F0F6FA',
    ];
}

/**
 * The colour map of a master, in the two ways a deck has one.
 *
 * @param bool $dark Whether the page is painted with the dark slot.
 * @return array<string, string>
 */
function themeMap(bool $dark = false): array
{
    return [
        'bg1' => $dark ? 'dk1' : 'lt1',
        'tx1' => $dark ? 'lt1' : 'dk1',
        'bg2' => $dark ? 'dk2' : 'lt2',
        'tx2' => $dark ? 'lt2' : 'dk2',
        'accent1' => 'accent1', 'accent2' => 'accent2',
        'accent3' => 'accent3', 'accent4' => 'accent4',
        'accent5' => 'accent5', 'accent6' => 'accent6',
        'hlink' => 'hlink', 'folHlink' => 'folHlink',
    ];
}

/**
 * A deck that is drawn in its own theme: its slides name the theme's slots.
 *
 * @param array<string, string> $scheme The theme part's colour slots.
 * @param array<string, string> $map The colour map of the master.
 * @param array{slots?: list<string>} $options
 * @return string The path of the deck.
 */
function deckInTheme(array $scheme, array $map, array $options = []): string
{
    return DeckFactory::deck(
        [['name' => 'Titelfolie', 'placeholders' => ['title', 'subTitle']]],
        [[
            'text' => 'The heading of the slide',
            'size' => 26,
            'colour' => '000000',
            'scheme' => 'dk1',
            'face' => 'Cambria',
            'background' => null,
            'layout' => 0,
        ]],
        $scheme,
        ['heading' => 'Cambria', 'body' => 'Calibri'],
        [],
        $options + ['map' => $map],
    );
}

/**
 * A directory the theme is written into.
 */
function colourDirectory(): string
{
    return Scratch::path('colours', '');
}

it('says a deck that is drawn in a dark theme of its own is dark', function () {
    // The deck's colour map paints the page with the dark slot, and the deck is
    // drawn in the theme it carries — so the theme it is scaffolded into is a dark
    // one. A theme that said otherwise would draw its ink on a dark page.
    $deck = deckInTheme(themeScheme(), themeMap(dark: true));

    $directory = colourDirectory();
    $run = runCli(['theme', 'init', $deck, '-o', $directory]);

    expect((string) file_get_contents($directory . '/theme.yml'))->toContain('dark: true')
        ->and($run['out'])->toContain('dk1')
        ->and($run['out'])->toContain('#0D1B2A')
        ->and($run['out'])->toContain("the deck's own theme part");
});

it('reports the colour of a theme of its own as the theme states it', function () {
    // The colour is written with a hash in the theme file and without one in the
    // package, and a colour that keeps its hash is five characters long rather
    // than six.
    $deck = deckInTheme(themeScheme(), themeMap(dark: true));

    $directory = colourDirectory();
    $run = runCli(['theme', 'init', $deck, '-o', $directory]);

    expect($run['out'])->toContain('#0D1B2A')
        ->and($run['out'])->not->toContain('##0D1B2A');
});

it('reads a slot the theme part holds as a system colour', function () {
    // A system colour is written with the colour it last was in beside it, and
    // that is the colour the slot holds: the system colour itself says nothing
    // about the colour on the page.
    $deck = deckInTheme(themeScheme(), themeMap(), ['sys' => ['lt1']]);

    $directory = colourDirectory();
    runCli(['theme', 'init', $deck, '-o', $directory]);

    $theme = (string) file_get_contents($directory . '/theme.yml');

    expect($theme)->toContain('lt1: "#F8FAFC"')
        // The slot is answered, so it does not keep the library's own colour.
        ->and($theme)->not->toContain('lt1: "#FFFFFF"');
});

it('leaves the slots the deck does not declare at the library\'s default', function () {
    // The theme part declares five of the twelve slots, and the seven it does not
    // declare keep the library's own colours — which the report says, because a
    // number the deck did not answer is a number somebody has to answer.
    $deck = deckInTheme(
        themeScheme(),
        themeMap(),
        ['slots' => ['dk1', 'lt1', 'dk2', 'lt2', 'accent1']],
    );

    $directory = colourDirectory();
    $run = runCli(['theme', 'init', $deck, '-o', $directory]);

    $theme = (string) file_get_contents($directory . '/theme.yml');

    expect($theme)->toContain('accent2: "#ED7D31"')
        ->and($run['out'])->toContain('left at the library\'s default')
        ->and($run['out'])->toContain('accent2');
});

it('reads the quieter ink from a deck that has two inks', function () {
    // A deck draws some of its type in one ink and some in another, and the
    // quieter of the two is what a link that has been followed is drawn in.
    $deck = DeckFactory::deck(
        [['name' => 'Titelfolie', 'placeholders' => ['title', 'subTitle']]],
        [[
            'text' => '',
            'size' => 18,
            'colour' => '000000',
            'face' => 'Arial',
            'background' => '0D1B2A',
            'layout' => 0,
            'shapes' => [
                ['name' => 'Bright', 'runs' => [['text' => 'Bright', 'colour' => 'F8FAFC'], ['text' => 'Also bright', 'colour' => 'F8FAFC']]],
                ['name' => 'Quiet', 'runs' => [['text' => 'Dim', 'colour' => 'C0C0C0']]],
            ],
        ]],
    );

    $directory = colourDirectory();
    $run = runCli(['theme', 'init', $deck, '-o', $directory]);

    $theme = (string) file_get_contents($directory . '/theme.yml');

    expect($theme)->toContain('lt2: "#C0C0C0"')
        ->and($theme)->toContain('folHlink: "#C0C0C0"')
        ->and($run['out'])->toContain('the quieter type')
        ->and($run['out'])->toContain('the quieter ink');
});

it('takes six accents from a deck that draws with more colours than a theme has', function () {
    // A theme has six accents, and a deck that draws with seven colours has one
    // of them left out — which the theme says by not having it.
    $colours = ['E63946', 'F4A261', '2A9D8F', 'E9C46A', '6A4C93', '8A5CF5', 'FF00FF'];
    $shapes = [];

    foreach ($colours as $index => $colour) {
        $shapes[] = ['name' => 'Block ' . $index, 'fill' => $colour];
    }

    $deck = DeckFactory::deck(
        [['name' => 'Titelfolie', 'placeholders' => ['title', 'subTitle']]],
        [[
            'text' => '',
            'size' => 18,
            'colour' => '000000',
            'face' => 'Arial',
            'background' => '0D1B2A',
            'layout' => 0,
            'shapes' => $shapes,
        ]],
    );

    $directory = colourDirectory();
    runCli(['theme', 'init', $deck, '-o', $directory]);

    $theme = (string) file_get_contents($directory . '/theme.yml');

    expect($theme)->toContain('accent1: "#E63946"')
        ->and($theme)->toContain('accent6: "#8A5CF5"')
        // The seventh colour has no slot to go into.
        ->and($theme)->not->toContain('FF00FF');
});

it('reads no page from a deck that sets no background', function () {
    // A deck whose slides carry no background has no page colour, and the theme
    // says so by leaving the page at the library's own.
    $deck = DeckFactory::deck(
        [['name' => 'Titelfolie', 'placeholders' => ['title', 'subTitle']]],
        [[
            'text' => 'The heading of the slide',
            'size' => 26,
            'colour' => '333333',
            'face' => 'Cambria',
            'background' => null,
            'layout' => 0,
        ]],
    );

    $directory = colourDirectory();
    $run = runCli(['theme', 'init', $deck, '-o', $directory]);

    expect($run['out'])->not->toContain('the background of the slides')
        ->and((string) file_get_contents($directory . '/theme.yml'))->toContain('lt1: "#FFFFFF"');
});

it('does not take the panel from the colours the deck writes its type in', function () {
    // The fill here is a colour rather than a shade, so there is no panel at all,
    // and the colour of the type is not it either: a panel the colour of the body
    // text is a code block drawn in the colour of the words in it.
    $deck = DeckFactory::deck(
        [['name' => 'Titelfolie', 'placeholders' => ['title', 'subTitle']]],
        [[
            'text' => 'The heading of the slide',
            'size' => 26,
            'colour' => 'F8FAFC',
            'face' => 'Cambria',
            'background' => null,
            'layout' => 0,
            'fill' => '00B4D8',
        ]],
    );

    $directory = colourDirectory();
    runCli(['theme', 'init', $deck, '-o', $directory]);

    $theme = (string) file_get_contents($directory . '/theme.yml');

    expect($theme)->toContain('dk1: "#F8FAFC"')
        // The panel keeps the library's own rather than becoming the ink.
        ->and($theme)->toContain('lt2: "#E7E6E6"');
});
