<?php

declare(strict_types=1);

use MarkdownOffice\Console\ConsoleException;
use MarkdownOffice\Console\Theme\Template;
use MarkdownOffice\Tests\Support\DeckFactory;
use MarkdownOffice\Tests\Support\Packages;
use MarkdownOffice\Tests\Support\Scratch;

/*
 * The theme part read from the outside: the deck it reads, the template it writes
 * and the sentences it prints when it cannot.
 *
 * The deck that cannot be read is the case that matters here, because it is the
 * one a user meets: a file with the wrong extension, an archive that was never a
 * presentation, a deck whose slide part was written by something that does not
 * write XML. Each of them has a sentence, and the sentence is the contract.
 */

/**
 * A deck with the given layouts and slides.
 *
 * @param list<array{name: string, placeholders: list<string>}> $layouts
 * @param list<array{text: string, size: int, colour: string, face: string, background: ?string, layout: int}> $slides
 * @param array{created?: string, modified?: string, title?: string, creator?: string, written?: int} $properties
 * @return string The path of the deck.
 */
function themeDeckOf(array $layouts, array $slides = [], array $properties = []): string
{
    return DeckFactory::deck($layouts, $slides, [], ['heading' => 'Cambria', 'body' => 'Calibri'], $properties);
}

/**
 * A directory the theme is written into.
 */
function themeOutput(): string
{
    return Scratch::path('theme', '');
}

it('runs the theme command when no command is named', function () {
    // The part has one command, so naming it is optional: `mdoffice theme deck.pptx`
    // is the same run as `mdoffice theme init deck.pptx`.
    $deck = themeDeckOf(
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

    $run = runCli(['theme', $deck]);

    $expected = substr($deck, 0, -strlen('.pptx'));

    expect($run['code'])->toBe(0)
        ->and($run['out'])->toContain('wrote the theme for')
        ->and(is_file($expected . '/theme.yml'))->toBeTrue()
        ->and(is_file($expected . '/layouts.yml'))->toBeTrue()
        ->and(is_file($expected . '/template.pptx'))->toBeTrue();
});

it('reports a file that is not a deck as a sentence rather than a stack trace', function () {
    // A file with the wrong extension is the likeliest thing a user hands this
    // command, and it is a refusal rather than a defect.
    $path = Scratch::path('notes', '.txt');
    file_put_contents($path, 'This is not a deck.');

    $run = runCli(['theme', 'init', $path, '-o', themeOutput()]);

    expect($run['code'])->toBe(1)
        ->and($run['err'])->toContain('could not be opened')
        ->and($run['err'])->toContain('A .pptx is a zip archive')
        ->and($run['err'])->not->toContain('at /');
});

it('reports an archive that is not a presentation as a sentence', function () {
    $path = Packages::package(['readme.txt' => 'This archive was never a deck.']);

    $run = runCli(['theme', 'init', $path, '-o', themeOutput()]);

    expect($run['code'])->toBe(1)
        ->and($run['err'])->toContain('is not a presentation')
        ->and($run['err'])->toContain('ppt/presentation.xml')
        ->and($run['err'])->not->toContain('at /');
});

it('reports a part of the deck that is not XML as a sentence', function () {
    $deck = themeDeckOf(
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

    $broken = Packages::copy($deck, ['ppt/slides/slide1.xml' => 'This is not XML.']);

    $run = runCli(['theme', 'init', $broken, '-o', themeOutput()]);

    expect($run['code'])->toBe(1)
        ->and($run['err'])->toContain('is not XML')
        ->and($run['err'])->toContain('ppt/slides/slide1.xml')
        ->and($run['err'])->not->toContain('at /');
});

it('takes the deck\'s own title and author into the theme', function () {
    // A deck carries the name it was given and who made it, and a theme scaffolded
    // from it should say so rather than take its name from the file it was read
    // from.
    $deck = themeDeckOf(
        [['name' => 'Titelfolie', 'placeholders' => ['title', 'subTitle']]],
        [[
            'text' => 'The heading of the slide',
            'size' => 26,
            'colour' => 'F8FAFC',
            'face' => 'Cambria',
            'background' => '0D1B2A',
            'layout' => 0,
        ]],
        ['title' => 'The Quarterly Report', 'creator' => 'Ada Lovelace'],
    );

    $directory = themeOutput();
    runCli(['theme', 'init', $deck, '-o', $directory]);

    $theme = (string) file_get_contents($directory . '/theme.yml');

    expect($theme)->toContain('name: The Quarterly Report')
        ->and($theme)->toContain('author: Ada Lovelace');
});

it('writes a template that is a package of its own', function () {
    $directory = themeOutput();
    runCli(['theme', 'init', themeDeckOf(
        [['name' => 'Titelfolie', 'placeholders' => ['title', 'subTitle']]],
        [[
            'text' => 'The heading of the slide',
            'size' => 26,
            'colour' => 'F8FAFC',
            'face' => 'Cambria',
            'background' => '0D1B2A',
            'layout' => 0,
        ]],
    ), '-o', $directory]);

    $zip = new ZipArchive();
    $zip->open($directory . '/template.pptx');

    $names = [];

    for ($index = 0; $index < $zip->numFiles; $index++) {
        $names[] = (string) $zip->getNameIndex($index);
    }

    $types = (string) $zip->getFromName('[Content_Types].xml');
    $zip->close();

    // A package without its content types is not a package: PowerPoint refuses to
    // open it, and the theme it belongs to is no use to anybody.
    expect($names)->toContain('[Content_Types].xml')
        // And the content types must not name the slides the template drops.
        ->and($types)->not->toContain('ppt/slides/slide1.xml');
});

it('reports a template that cannot be read', function () {
    $path = Scratch::path('notes', '.txt');
    file_put_contents($path, 'This is not a deck.');

    expect(fn () => Template::write($path, Scratch::path('template', '.pptx')))
        ->toThrow(ConsoleException::class, 'could not be opened');
});

it('refuses a deck that holds more parts than a deck may hold', function () {
    // A zip says how its contents are laid out and nothing about how much room
    // they will take up, so the number of parts is read out of the directory
    // rather than out of the parts themselves.
    $path = Packages::many(4097);

    $run = runCli(['theme', 'init', $path, '-o', themeOutput()]);

    expect($run['code'])->toBe(1)
        ->and($run['err'])->toContain('has 4097 parts, which is more than a deck may hold')
        ->and($run['err'])->not->toContain('at /');
});

it('refuses a deck with a part that is larger than a part may be', function () {
    // The same deck the diff refuses, and the same sentence: a part of a few
    // hundred kilobytes can hold a gigabyte once it is decompressed, and the
    // theme half reads parts the diff does not.
    $path = Packages::oversized();

    $run = runCli(['theme', 'init', $path, '-o', themeOutput()]);

    expect($run['code'])->toBe(1)
        ->and($run['err'])->toContain('is 268435457 bytes, which is more than a part may be')
        ->and($run['err'])->toContain('ppt/presentation.xml')
        ->and($run['err'])->not->toContain('at /');
});

it('refuses a template of a deck with a part that is larger than a part may be', function () {
    // The template inflates the parts of the deck into a package of its own, so
    // it is bounded as well: the deck it is given may not be one the theme half
    // would have read.
    $path = Packages::oversized();

    expect(fn () => Template::write($path, Scratch::path('template', '.pptx')))
        ->toThrow(ConsoleException::class, 'which is more than a part may be');
});
