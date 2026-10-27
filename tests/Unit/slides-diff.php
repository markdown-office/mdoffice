<?php

declare(strict_types=1);

use MarkdownOffice\Console\Slides\SlidesApplication;
use MarkdownOffice\Tests\Support\DeckFactory;
use MarkdownOffice\Tests\Support\Packages;
use MarkdownOffice\Tests\Support\Scratch;

/*
 * The diff: two decks in, a report of what changed out.
 *
 * The decks are built in the test rather than checked in, because a deck is a
 * fixture and a fixture nobody can read is a fixture that cannot be reviewed.
 */

/**
 * A deck with the given shapes on each of its slides.
 *
 * @param list<list<array{name: string, id?: int, at?: array{x: int, y: int, cx: int, cy: int}, fill?: ?string, outline?: ?string, alpha?: ?int, kind?: string, runs?: list<array{text: string, colour: string}>}>> $slides
 * @param array{created?: string, modified?: string, title?: string, creator?: string, written?: int} $properties
 *        The document's own properties, and the time the archive says it was written at.
 * @param array{size?: array{int, int}, list?: bool} $options
 *        The page the slides are, and whether the deck lists them.
 */
function diffDeck(array $slides, array $properties = [], array $options = []): string
{
    $deck = [];

    foreach ($slides as $shapes) {
        $deck[] = [
            'text' => '',
            'size' => 18,
            'colour' => '000000',
            'face' => 'Arial',
            'background' => null,
            'layout' => 0,
            'shapes' => $shapes,
        ];
    }

    return DeckFactory::deck(
        [['name' => 'Title and Content', 'placeholders' => ['title', 'body']]],
        $deck,
        [],
        ['heading' => 'Arial', 'body' => 'Arial'],
        $properties,
        $options,
    );
}

/**
 * A deck with one slide carrying one shape at a place of its own.
 *
 * @param array{x: int, y: int, cx: int, cy: int} $at
 * @return list<list<array{name: string, id?: int, at?: array{x: int, y: int, cx: int, cy: int}, fill?: ?string, runs?: list<array{text: string, colour: string}>}>>
 */
function oneShape(array $at, string $text = 'Ergebnis', string $colour = '00B4D8', ?string $fill = null): array
{
    return [[[
        'name' => 'Body 1',
        'at' => $at,
        'fill' => $fill,
        'runs' => [['text' => $text, 'colour' => $colour]],
    ]]];
}

/**
 * A deck whose one slide paints its text in a colour of its own.
 *
 * The colour is a literal one unless a slot of the theme is named, which is how a
 * deck that is drawn in its theme says so.
 *
 * @param array<string, string> $scheme The colour slots of the deck's theme.
 */
function plainDeck(string $text = 'Ergebnis', ?string $slot = null, array $scheme = []): string
{
    return DeckFactory::deck(
        [['name' => 'Title and Content', 'placeholders' => ['title', 'body']]],
        [[
            'text' => $text,
            'size' => 18,
            'colour' => '00B4D8',
            'face' => 'Arial',
            'background' => null,
            'layout' => 0,
            'scheme' => $slot,
        ]],
        $scheme,
        ['heading' => 'Arial', 'body' => 'Arial'],
    );
}

it('reports nothing when the two decks are the same', function () {
    $shapes = oneShape(['x' => 360000, 'y' => 180000, 'cx' => 4000000, 'cy' => 1000000]);

    $run = runCli(['slides', 'diff', diffDeck($shapes), diffDeck($shapes)]);

    expect($run['code'])->toBe(SlidesApplication::SUCCESS)
        ->and($run['err'])->toContain('Nothing changed.')
        // Nothing was found, so nothing is named: a line about a slide would be
        // a difference, and there is none.
        ->and($run['err'])->not->toContain('slide 1:');
});

it('reports the text that was added and the text that was removed', function () {
    $before = diffDeck([[[
        'name' => 'Body 1',
        'runs' => [['text' => 'Volumen niedrig', 'colour' => '000000']],
    ]]]);
    $after = diffDeck([[[
        'name' => 'Body 1',
        'runs' => [['text' => 'Varianz niedrig', 'colour' => '000000']],
    ]]]);

    $run = runCli(['slides', 'diff', $before, $after]);

    expect($run['code'])->toBe(SlidesApplication::SUCCESS)
        ->and($run['err'])->toContain('slide 1: the text "Volumen niedrig" was removed')
        ->and($run['err'])->toContain('slide 1: the text "Varianz niedrig" was added');
});

it('reports a shape that moved, and where it moved to', function () {
    $before = diffDeck(oneShape(['x' => 360000, 'y' => 180000, 'cx' => 4000000, 'cy' => 1000000]));
    $after = diffDeck(oneShape(['x' => 360000, 'y' => 216000, 'cx' => 4000000, 'cy' => 1000000]));

    $run = runCli(['slides', 'diff', $before, $after]);

    expect($run['code'])->toBe(SlidesApplication::SUCCESS)
        // Both places, and in millimetres: the EMU a package holds are a hundredth
        // of a millimetre divided by 360, and nobody reads them.
        ->and($run['err'])->toContain('slide 1: the shape "Body 1" moved from 10.00mm, 5.00mm to 10.00mm, 6.00mm')
        ->and($run['err'])->toContain('Positions and sizes are in millimetres');
});

it('reports a shape that changed size', function () {
    $before = diffDeck(oneShape(['x' => 360000, 'y' => 180000, 'cx' => 4000000, 'cy' => 1000000]));
    $after = diffDeck(oneShape(['x' => 360000, 'y' => 180000, 'cx' => 4000000, 'cy' => 1200000]));

    $run = runCli(['slides', 'diff', $before, $after]);

    expect($run['err'])->toContain('slide 1: the shape "Body 1" changed size from 111.11mm, 27.78mm to 111.11mm, 33.33mm');
});

it('reports a colour that changed', function () {
    $before = diffDeck(oneShape(['x' => 360000, 'y' => 180000, 'cx' => 4000000, 'cy' => 1000000], 'Ergebnis', '00B4D8', '00B4D8'));
    $after = diffDeck(oneShape(['x' => 360000, 'y' => 180000, 'cx' => 4000000, 'cy' => 1000000], 'Ergebnis', '087995', '087995'));

    $run = runCli(['slides', 'diff', $before, $after]);

    expect($run['code'])->toBe(SlidesApplication::SUCCESS)
        ->and($run['err'])->toContain('slide 1: the shape "Body 1" changed its fill colour from 00B4D8 to 087995')
        ->and($run['err'])->toContain('slide 1: the text "Ergebnis" changed colour from 00B4D8 to 087995');
});

it('reports two decks that differ only in their timestamps as the same', function () {
    // The one that matters most. A `.pptx` carries a time in every part it holds
    // and another in the document's own properties, so two renderings of the same
    // deck are two different files, and a diff that compared the bytes would say
    // that every deck ever written had changed.
    $shapes = oneShape(['x' => 360000, 'y' => 180000, 'cx' => 4000000, 'cy' => 1000000]);

    $before = diffDeck($shapes, [
        'created' => '2026-10-01T09:00:00Z',
        'modified' => '2026-10-01T09:00:00Z',
        'written' => 1790000000,
    ]);
    $after = diffDeck($shapes, [
        'created' => '2026-10-27T17:00:00Z',
        'modified' => '2026-10-27T17:00:00Z',
        'written' => 1790000000 + 86400,
    ]);

    // The two files really are different files, or the test would prove nothing.
    expect(file_get_contents($before))->not->toBe(file_get_contents($after));

    $run = runCli(['slides', 'diff', $before, $after]);

    expect($run['code'])->toBe(SlidesApplication::SUCCESS)
        ->and($run['err'])->toContain('Nothing changed.')
        ->and($run['err'])->not->toContain('slide 1:');
});

it('compares a colour that names a slot of the theme as the colour that slot holds', function () {
    // A deck drawn in its theme names the theme's slots rather than spelling the
    // colours out, and a diff that compared the two spellings would report a
    // difference between two decks that are painted the same colour.
    $slots = ['accent1' => '00B4D8', 'accent2' => '087995'];

    $run = runCli(['slides', 'diff', plainDeck('Ergebnis', 'accent1', $slots), plainDeck('Ergebnis', null, $slots)]);

    expect($run['code'])->toBe(SlidesApplication::SUCCESS)
        ->and($run['err'])->toContain('Nothing changed.');
});

it('reports a colour that changed, when the slot it names holds another colour', function () {
    $slots = ['accent1' => '00B4D8', 'accent2' => '087995'];

    $run = runCli(['slides', 'diff', plainDeck('Ergebnis', 'accent1', $slots), plainDeck('Ergebnis', 'accent2', $slots)]);

    expect($run['err'])->toContain('slide 1: the text "Ergebnis" changed colour from 00B4D8 to 087995');
});

it('pairs the slides by their id rather than by their place', function () {
    // A slide inserted at the front moves every slide after it, and a diff that
    // paired the slides by their place would report the whole deck as changed.
    // The id a deck gives a slide survives the insertion, so the slides are
    // paired by it and the inserted one is the only thing that is new.
    $before = diffDeck([
        [['name' => 'Body 1', 'runs' => [['text' => 'One', 'colour' => '000000']]]],
        [['name' => 'Body 1', 'runs' => [['text' => 'Two', 'colour' => '000000']]]],
    ]);

    $after = DeckFactory::deck(
        [['name' => 'Title and Content', 'placeholders' => ['title', 'body']]],
        [
            [
                'text' => '',
                'size' => 18,
                'colour' => '000000',
                'face' => 'Arial',
                'background' => null,
                'layout' => 0,
                'id' => 258,
                'shapes' => [['name' => 'Body 1', 'runs' => [['text' => 'New', 'colour' => '000000']]]],
            ],
            [
                'text' => '',
                'size' => 18,
                'colour' => '000000',
                'face' => 'Arial',
                'background' => null,
                'layout' => 0,
                'id' => 256,
                'shapes' => [['name' => 'Body 1', 'runs' => [['text' => 'One', 'colour' => '000000']]]],
            ],
            [
                'text' => '',
                'size' => 18,
                'colour' => '000000',
                'face' => 'Arial',
                'background' => null,
                'layout' => 0,
                'id' => 257,
                'shapes' => [['name' => 'Body 1', 'runs' => [['text' => 'Two', 'colour' => '000000']]]],
            ],
        ],
        [],
        ['heading' => 'Arial', 'body' => 'Arial'],
    );

    $run = runCli(['slides', 'diff', $before, $after]);

    expect($run['code'])->toBe(SlidesApplication::SUCCESS)
        ->and($run['err'])->toContain('slide 1 of the second deck was added')
        // The slides that were there are still there: no text was reported as
        // removed or added, because none of it was.
        ->and($run['err'])->not->toContain('the text "One" was')
        ->and($run['err'])->not->toContain('the text "Two" was');
});

it('reports a slide that was removed', function () {
    $before = diffDeck([
        [['name' => 'Body 1', 'runs' => [['text' => 'One', 'colour' => '000000']]]],
        [['name' => 'Body 1', 'runs' => [['text' => 'Two', 'colour' => '000000']]]],
    ]);
    $after = diffDeck([
        [['name' => 'Body 1', 'runs' => [['text' => 'One', 'colour' => '000000']]]],
    ]);

    $run = runCli(['slides', 'diff', $before, $after]);

    expect($run['err'])->toContain('slide 2 was removed');
});

it('says what it compared and what it did not', function () {
    $shapes = oneShape(['x' => 360000, 'y' => 180000, 'cx' => 4000000, 'cy' => 1000000]);

    $run = runCli(['slides', 'diff', diffDeck($shapes), diffDeck($shapes)]);

    // A reader told "no differences" by a tool that looked at half the deck is
    // worse off than one told what was looked at.
    expect($run['err'])->toContain('Compared the text of every shape')
        ->and($run['err'])->toContain('on the one slide the two decks have in common')
        ->and($run['err'])->toContain('Not compared:')
        ->and($run['err'])->toContain('the notes')
        ->and($run['err'])->toContain('the document\'s own properties');
});

it('reports a file that is not a package as a sentence rather than a stack trace', function () {
    $path = Scratch::path('notes', '.txt');
    file_put_contents($path, 'This is not a deck.');

    $run = runCli(['slides', 'diff', $path, $path]);

    expect($run['code'])->toBe(SlidesApplication::FAILURE)
        ->and($run['err'])->toContain('could not be opened')
        ->and($run['err'])->toContain('A .pptx is a zip archive')
        ->and($run['err'])->not->toContain('at /');
});

it('reports a deck that is not there as a sentence rather than a stack trace', function () {
    $run = runCli(['slides', 'diff', Scratch::path('absent', '.pptx'), Scratch::path('absent', '.pptx')]);

    expect($run['code'])->toBe(SlidesApplication::FAILURE)
        // A deck, not a file: the command reads decks, and the converter's own
        // wording would name something this command never looks at.
        ->and($run['err'])->toContain('The deck')
        ->and($run['err'])->toContain('does not exist')
        ->and($run['err'])->not->toContain('at /');
});

it('asks for two decks when it is given fewer', function () {
    $run = runCli(['slides', 'diff']);

    expect($run['code'])->toBe(SlidesApplication::FAILURE)
        ->and($run['err'])->toContain('Name the two decks to compare')
        ->and($run['err'])->not->toContain('at /');
});

it('exits with 0 when the decks differ, because a report is not a refusal', function () {
    // The suite's own rule: a run that did what it was asked exits zero, and
    // only a refusal does not. A difference is what the tool was asked to find,
    // so it is in the report and not in the exit code.
    $before = diffDeck([[['name' => 'Body 1', 'runs' => [['text' => 'One', 'colour' => '000000']]]]]);
    $after = diffDeck([[['name' => 'Body 1', 'runs' => [['text' => 'Two', 'colour' => '000000']]]]]);

    $run = runCli(['slides', 'diff', $before, $after]);

    expect($run['code'])->toBe(SlidesApplication::SUCCESS)
        ->and($run['err'])->toContain('was removed')
        ->and($run['err'])->toContain('was added');
});

it('exits with 1 when the decks differ and the build asked for it', function () {
    $before = diffDeck([[['name' => 'Body 1', 'runs' => [['text' => 'One', 'colour' => '000000']]]]]);
    $after = diffDeck([[['name' => 'Body 1', 'runs' => [['text' => 'Two', 'colour' => '000000']]]]]);

    $run = runCli(['slides', 'diff', $before, $after, '--exit-code']);

    expect($run['code'])->toBe(SlidesApplication::FAILURE)
        ->and($run['err'])->toContain('was removed');
});

it('exits with 0 when the decks are the same, even when the build asked for it', function () {
    $shapes = oneShape(['x' => 360000, 'y' => 180000, 'cx' => 4000000, 'cy' => 1000000]);

    $run = runCli(['slides', 'diff', diffDeck($shapes), diffDeck($shapes), '--exit-code']);

    expect($run['code'])->toBe(SlidesApplication::SUCCESS)
        ->and($run['err'])->toContain('Nothing changed.');
});

it('prints its own help rather than the part\'s', function () {
    $run = runCli(['slides', 'diff', '--help']);

    expect($run['code'])->toBe(SlidesApplication::SUCCESS)
        ->and($run['out'])->toContain('mdoffice slides diff')
        ->and($run['out'])->toContain('OPTIONS')
        ->and($run['out'])->toContain('--exit-code')
        // Two decks, not one file.
        ->and($run['out'])->toContain('<before.pptx> <after.pptx>');
});

it('reports the slide size when it changed', function () {
    // The page is the frame of every slide on it, and a deck whose page changed
    // is a deck whose every slide moved.
    $shapes = oneShape(['x' => 360000, 'y' => 180000, 'cx' => 4000000, 'cy' => 1000000]);

    $before = diffDeck($shapes);
    $after = diffDeck($shapes, [], ['size' => [9144000, 6858000]]);

    $run = runCli(['slides', 'diff', $before, $after]);

    expect($run['code'])->toBe(SlidesApplication::SUCCESS)
        ->and($run['err'])->toContain('the slide size changed from 254.00mm × 142.88mm to 254.00mm × 190.50mm');
});

it('reports a shape that was added', function () {
    $at = ['x' => 360000, 'y' => 180000, 'cx' => 4000000, 'cy' => 1000000];

    $before = diffDeck([[['name' => 'Body 1', 'at' => $at, 'runs' => [['text' => 'One', 'colour' => '000000']]]]]);
    $after = diffDeck([[
        ['name' => 'Body 1', 'at' => $at, 'runs' => [['text' => 'One', 'colour' => '000000']]],
        ['name' => 'Body 2', 'at' => $at, 'runs' => [['text' => 'Two', 'colour' => '000000']]],
    ]]);

    $run = runCli(['slides', 'diff', $before, $after]);

    expect($run['code'])->toBe(SlidesApplication::SUCCESS)
        ->and($run['err'])->toContain('slide 1: the shape "Body 2" was added')
        // And the shape that is still there is not reported as changed.
        ->and($run['err'])->not->toContain('the shape "Body 1" was');
});

it('reports a shape that was removed', function () {
    $at = ['x' => 360000, 'y' => 180000, 'cx' => 4000000, 'cy' => 1000000];

    $before = diffDeck([[
        ['name' => 'Body 1', 'at' => $at, 'runs' => [['text' => 'One', 'colour' => '000000']]],
        ['name' => 'Body 2', 'at' => $at, 'runs' => [['text' => 'Two', 'colour' => '000000']]],
    ]]);
    $after = diffDeck([[['name' => 'Body 1', 'at' => $at, 'runs' => [['text' => 'One', 'colour' => '000000']]]]]);

    $run = runCli(['slides', 'diff', $before, $after]);

    expect($run['err'])->toContain('slide 1: the shape "Body 2" was removed')
        ->and($run['err'])->not->toContain('the shape "Body 1" was');
});

it('compares the shapes of a group as the shapes of the slide', function () {
    // A group is a frame around shapes, and the shapes in it are the ones that
    // carry the text — so the text inside a group is compared like any other.
    $at = ['x' => 360000, 'y' => 180000, 'cx' => 4000000, 'cy' => 1000000];

    $before = diffDeck([[['name' => 'Group 1', 'kind' => 'group', 'at' => $at, 'runs' => [['text' => 'Im Text', 'colour' => '000000']]]]]);
    $after = diffDeck([[['name' => 'Group 1', 'kind' => 'group', 'at' => $at, 'runs' => [['text' => 'Im anderen Text', 'colour' => '000000']]]]]);

    $run = runCli(['slides', 'diff', $before, $after]);

    expect($run['code'])->toBe(SlidesApplication::SUCCESS)
        ->and($run['err'])->toContain('slide 1: the text "Im Text" was removed')
        ->and($run['err'])->toContain('slide 1: the text "Im anderen Text" was added');
});

it('reports a picture that moved', function () {
    // A picture carries no text, so the only thing a diff can say about one is
    // where it is.
    $before = diffDeck([[['name' => 'Picture 1', 'kind' => 'picture', 'at' => ['x' => 360000, 'y' => 180000, 'cx' => 4000000, 'cy' => 1000000]]]]);
    $after = diffDeck([[['name' => 'Picture 1', 'kind' => 'picture', 'at' => ['x' => 360000, 'y' => 216000, 'cx' => 4000000, 'cy' => 1000000]]]]);

    $run = runCli(['slides', 'diff', $before, $after]);

    expect($run['code'])->toBe(SlidesApplication::SUCCESS)
        ->and($run['err'])->toContain('slide 1: the shape "Picture 1" moved from 10.00mm, 5.00mm to 10.00mm, 6.00mm');
});

it('compares a colour that carries a change on it', function () {
    // A colour that is half transparent is not the colour it names, and the report
    // says so rather than comparing the two as the same colour.
    $at = ['x' => 360000, 'y' => 180000, 'cx' => 4000000, 'cy' => 1000000];

    $before = diffDeck([[['name' => 'Body 1', 'at' => $at, 'fill' => '00B4D8', 'runs' => [['text' => 'Ergebnis', 'colour' => '00B4D8']]]]]);
    $after = diffDeck([[['name' => 'Body 1', 'at' => $at, 'fill' => '087995', 'alpha' => 50000, 'runs' => [['text' => 'Ergebnis', 'colour' => '00B4D8']]]]]);

    $run = runCli(['slides', 'diff', $before, $after]);

    expect($run['code'])->toBe(SlidesApplication::SUCCESS)
        ->and($run['err'])->toContain('slide 1: the shape "Body 1" changed its fill colour from 00B4D8 to 087995 (alpha 50.0%)');
});

it('reads the slides of a deck that does not list them', function () {
    // A deck with no list of slides still has slides, and a diff that read none of
    // them would say that two decks of several slides are the same.
    $at = ['x' => 360000, 'y' => 180000, 'cx' => 4000000, 'cy' => 1000000];

    $before = diffDeck([[['name' => 'Body 1', 'at' => $at, 'runs' => [['text' => 'One', 'colour' => '000000']]]]], [], ['list' => false]);
    $after = diffDeck([[['name' => 'Body 1', 'at' => $at, 'runs' => [['text' => 'Two', 'colour' => '000000']]]]], [], ['list' => false]);

    $run = runCli(['slides', 'diff', $before, $after]);

    expect($run['code'])->toBe(SlidesApplication::SUCCESS)
        ->and($run['err'])->toContain('slide 1: the text "One" was removed')
        ->and($run['err'])->toContain('slide 1: the text "Two" was added');
});

it('reports an archive that is not a presentation as a sentence', function () {
    $path = Packages::package(['readme.txt' => 'This archive was never a deck.']);

    $run = runCli(['slides', 'diff', $path, $path]);

    expect($run['code'])->toBe(SlidesApplication::FAILURE)
        ->and($run['err'])->toContain('is not a presentation')
        ->and($run['err'])->toContain('ppt/presentation.xml')
        ->and($run['err'])->not->toContain('at /');
});

it('reports a part of the deck that is not XML as a sentence', function () {
    $deck = diffDeck([[['name' => 'Body 1', 'runs' => [['text' => 'One', 'colour' => '000000']]]]]);
    $broken = Packages::copy($deck, ['ppt/slides/slide1.xml' => 'This is not XML.']);

    $run = runCli(['slides', 'diff', $broken, $broken]);

    expect($run['code'])->toBe(SlidesApplication::FAILURE)
        ->and($run['err'])->toContain('is not XML')
        ->and($run['err'])->toContain('ppt/slides/slide1.xml')
        ->and($run['err'])->not->toContain('at /');
});

it('reports a deck whose slide is not there as a sentence', function () {
    // The deck names a slide it does not carry, which is a deck somebody else
    // wrote and this reader cannot open.
    $deck = diffDeck([[['name' => 'Body 1', 'runs' => [['text' => 'One', 'colour' => '000000']]]]]);
    $missing = Packages::copy($deck, [], ['ppt/slides/slide1.xml']);

    $run = runCli(['slides', 'diff', $missing, $missing]);

    expect($run['code'])->toBe(SlidesApplication::FAILURE)
        ->and($run['err'])->toContain('has no "ppt/slides/slide1.xml" part')
        ->and($run['err'])->not->toContain('at /');
});

it('refuses a deck that holds more parts than a deck may hold', function () {
    // A zip says how its contents are laid out and nothing about how much room
    // they will take up, so the number of parts is read out of the directory
    // rather than out of the parts themselves.
    $path = Packages::many(4097);

    $run = runCli(['slides', 'diff', $path, $path]);

    expect($run['code'])->toBe(SlidesApplication::FAILURE)
        ->and($run['err'])->toContain('has 4097 parts, which is more than a deck may hold')
        ->and($run['err'])->not->toContain('at /');
});
