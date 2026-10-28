<?php

declare(strict_types=1);

use MarkdownOffice\Tests\Support\DeckFactory;
use MarkdownOffice\Tests\Support\Scratch;

/*
 * The roles of a deck's layouts: what a layout holds, and what it is called.
 *
 * A deck names its layouts in whatever language its author had, so the roles are
 * mapped on the placeholders the layouts hold and never on their names. One role
 * cannot be told from the structure alone: a section divider and a content slide
 * hold the same placeholders, and only the name the deck gives one of them or the
 * text the deck writes on the slides that use it tells them apart.
 *
 * The layouts here are fixtures, and they are built in the test rather than
 * checked in: a deck whose layouts are called "Zweite Folie" is a fixture, and a
 * fixture nobody can read is a fixture that cannot be reviewed.
 */

/**
 * A deck with the given layouts and a slide on each of them.
 *
 * @param list<array{name: string, placeholders: list<string>}> $layouts
 * @param list<string> $texts The text of each slide, so that a deck can be read as
 *        using one layout for a divider and another for a page of prose.
 * @return string The path of the deck.
 */
function rolesDeck(array $layouts, array $texts = []): string
{
    $slides = [];

    foreach (array_keys($layouts) as $index) {
        $slides[] = [
            'text' => $texts[$index] ?? 'The text of the slide',
            'size' => 18,
            'colour' => '000000',
            'face' => 'Arial',
            'background' => null,
            'layout' => $index,
        ];
    }

    return DeckFactory::deck($layouts, $slides);
}

/**
 * A directory the theme is written into.
 */
function rolesDirectory(): string
{
    return Scratch::path('roles', '');
}

/**
 * The roles the theme maps, by the name of the layout each took.
 *
 * @return array<string, string>
 */
function rolesOf(string $deck): array
{
    $directory = rolesDirectory();
    runCli(['theme', 'init', $deck, '-o', $directory]);

    $yaml = (string) file_get_contents($directory . '/layouts.yml');
    $roles = [];

    foreach (explode("\n", $yaml) as $line) {
        if (preg_match('/^  ([a-z-]+): (.+)$/', $line, $matches) === 1) {
            $roles[$matches[1]] = $matches[2];
        }
    }

    return $roles;
}

it('maps the layout the deck calls a section onto the section role', function () {
    // The deck is an Office deck and calls its divider "Section Header", which is
    // the vocabulary the library uses too. The other layout holds the same
    // placeholders, so only the name tells the two apart.
    $deck = rolesDeck([
        ['name' => 'Section Header', 'placeholders' => ['title', 'body']],
        ['name' => 'Title and Content', 'placeholders' => ['title', 'body']],
    ]);

    expect(rolesOf($deck))->toBe([
        'content' => 'Title and Content',
        'section' => 'Section Header',
    ]);
});

it('says why a layout took the section role', function () {
    $deck = rolesDeck([
        ['name' => 'Section Header', 'placeholders' => ['title', 'body']],
        ['name' => 'Title and Content', 'placeholders' => ['title', 'body']],
    ]);

    $run = runCli(['theme', 'init', $deck, '-o', rolesDirectory()]);

    // The reader of the report is deciding whether to keep the mapping, and a
    // mapping without a reason is a mapping nobody can check.
    expect($run['out'])->toContain('a title and one column, and the deck uses it for a divider');
});

it('picks the divider among several layouts by the text the deck writes on it', function () {
    // A deck in another language calls its divider something the library does not
    // know, so the name says nothing. What the deck does with the layout does: a
    // divider carries a title and little else.
    $deck = rolesDeck(
        [
            ['name' => 'Erste Folie', 'placeholders' => ['title', 'body']],
            ['name' => 'Zweite Folie', 'placeholders' => ['title', 'body']],
        ],
        [
            'A slide that carries a page of prose written on it',
            'Kurz',
        ],
    );

    expect(rolesOf($deck))->toBe([
        'content' => 'Erste Folie',
        'section' => 'Zweite Folie',
    ]);
});

it('takes the first of two layouts that fit the same role', function () {
    // Two layouts hold a title and two columns, and a role can only be pointed at
    // one of them. The first is taken, and the other is named rather than dropped
    // in silence: a layout nobody used is a decision somebody has to make.
    $deck = rolesDeck([
        ['name' => 'Zwei Spalten', 'placeholders' => ['title', 'body', 'body']],
        ['name' => 'Zweispaltig', 'placeholders' => ['title', 'body', 'body']],
    ]);

    $run = runCli(['theme', 'init', $deck, '-o', rolesDirectory()]);

    expect(rolesOf($deck))->toBe(['two-column' => 'Zwei Spalten'])
        ->and($run['out'])->toContain('no role was pointed at these layouts')
        ->and($run['out'])->toContain('Zweispaltig');
});

it('names the layouts that no role took', function () {
    // Two layouts hold nothing at all, and only one of them can be the blank one.
    $deck = rolesDeck([
        ['name' => 'Leer', 'placeholders' => []],
        ['name' => 'Weiss', 'placeholders' => []],
    ]);

    $run = runCli(['theme', 'init', $deck, '-o', rolesDirectory()]);

    expect(rolesOf($deck))->toBe(['blank' => 'Leer'])
        ->and($run['out'])->toContain('no role was pointed at these layouts')
        ->and($run['out'])->toContain('Weiss');
});

it('maps a layout with a title and a picture onto the image-text role', function () {
    $deck = rolesDeck([
        ['name' => 'Bild und Text', 'placeholders' => ['title', 'body', 'pic']],
    ]);

    $run = runCli(['theme', 'init', $deck, '-o', rolesDirectory()]);

    expect(rolesOf($deck))->toBe(['image-text' => 'Bild und Text'])
        ->and($run['out'])->toContain('a title, a picture and a column');
});

it('maps a layout with a title and nothing else onto the image-title role', function () {
    $deck = rolesDeck([
        ['name' => 'Nur Titel', 'placeholders' => ['title']],
    ]);

    $run = runCli(['theme', 'init', $deck, '-o', rolesDirectory()]);

    expect(rolesOf($deck))->toBe(['image-title' => 'Nur Titel'])
        ->and($run['out'])->toContain('a title and nothing else');
});

it('maps a layout with a picture and nothing else onto the image-slide role', function () {
    $deck = rolesDeck([
        ['name' => 'Nur Bild', 'placeholders' => ['pic']],
    ]);

    $run = runCli(['theme', 'init', $deck, '-o', rolesDirectory()]);

    expect(rolesOf($deck))->toBe(['image-slide' => 'Nur Bild'])
        ->and($run['out'])->toContain('a picture and nothing else');
});

it('reports a role the deck has no layout for rather than pointing one at it', function () {
    // The deck holds a title and a column and nothing else, so there is no picture
    // layout and no section layout. Pointing one of them at the layout that is
    // there would be a theme that renders the wrong thing.
    $deck = rolesDeck([
        ['name' => 'Titel und Inhalt', 'placeholders' => ['title', 'body']],
    ]);

    $run = runCli(['theme', 'init', $deck, '-o', rolesDirectory()]);

    expect(rolesOf($deck))->not->toHaveKey('image-slide')
        ->and(rolesOf($deck))->not->toHaveKey('image-text')
        ->and(rolesOf($deck))->not->toHaveKey('section')
        ->and($run['out'])->toContain('the deck has no layout for');
});
