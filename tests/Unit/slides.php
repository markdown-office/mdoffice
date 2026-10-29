<?php

declare(strict_types=1);

/*
 * The slides part: Markdown in, a PowerPoint deck out.
 *
 * The deck is read with the Slidev-compatible dialect, so a `---` line closes the slide
 * being read — the opposite of what it does everywhere else in this tool, and the
 * reason these fixtures cannot be shared with the Word half's.
 */

use MarkdownOffice\Console\Application;
use MarkdownOffice\Console\Slides\SlidesApplication;
use MarkdownOffice\Tests\Support\Scratch;

const DECK = "---\ntitle: A deck\n---\n\n# One\n\nFirst.\n\n---\n\n# Two\n\nSecond.\n\n---\n\n# Three\n\nThird.\n";

/**
 * A deck with far more on one slide than fits on it.
 *
 * Sixty lines of prose into one slide, which is comfortably past the box whatever the
 * theme's metrics turn out to be. Its exact height is not the point and must not be
 * asserted: the point is that it does not fit, and that is checked by the run rather
 * than by reading a number out of the renderer.
 */
function tallDeck(): string
{
    return "---\ntitle: Tall\n---\n\n# Tall\n\n"
        . str_repeat("A line of prose that will certainly not fit on one slide.\n\n", 60);
}

/**
 * The text on each slide of a written deck, in order.
 *
 * Read out of the package rather than measured on it: a `.pptx` is a zip archive whose
 * slide parts are `ppt/slides/slide1.xml` onward, so the slide count and the text are
 * two facts read the way a reader would find them — and neither depends on the library
 * that wrote the file agreeing about its own output.
 *
 * @return list<string>
 */
function deckSlides(string $path): array
{
    $zip = new ZipArchive();

    if ($zip->open($path) !== true) {
        throw new RuntimeException('Not a package: ' . $path);
    }

    $names = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string) $zip->getNameIndex($i);

        if (preg_match('#^ppt/slides/slide(\d+)\.xml$#', $name, $match) === 1) {
            $names[(int) $match[1]] = $name;
        }
    }

    ksort($names);

    $texts = [];

    foreach ($names as $name) {
        preg_match_all('#<a:t>(.*?)</a:t>#s', (string) $zip->getFromName($name), $found);
        $texts[] = implode(' ', array_map('trim', $found[1]));
    }

    $zip->close();

    return $texts;
}

/**
 * Write a deck into the scratch area and hand its path back.
 */
function deckFile(string $markdown, string $prefix = 'deck'): string
{
    $path = Scratch::path($prefix, '.md');
    file_put_contents($path, $markdown);

    return $path;
}

/**
 * The slide parts of a package, by name.
 *
 * The parts rather than the archive: a zip stores 2-second timestamps and
 * `docProps/core.xml` carries creation and modification times, so two correct
 * renderings of one deck are two different archives and the same slides.
 *
 * @return array<string, string> The part's name to the bytes it holds.
 */
function deckParts(string $bytes): array
{
    $path = Scratch::path('parts', '.pptx');
    file_put_contents($path, $bytes);

    $zip = new ZipArchive();

    if ($zip->open($path) !== true) {
        throw new RuntimeException('Not a package.');
    }

    $parts = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string) $zip->getNameIndex($i);

        if (preg_match('#^ppt/slides/slide\d+\.xml$#', $name) === 1) {
            $parts[$name] = (string) $zip->getFromName($name);
        }
    }

    $zip->close();

    ksort($parts);

    return $parts;
}

it('turns a deck into a package with one slide per source slide', function () {
    $output = Scratch::path('deck', '.pptx');
    $run = runCli(['slides', deckFile(DECK), '-o', $output]);

    expect($run['code'])->toBe(SlidesApplication::SUCCESS)
        ->and($run['err'])->toContain('→')
        ->and(is_file($output))->toBeTrue();

    // `PK`, because a `.pptx` is a zip archive. A file of the right name and the wrong
    // bytes is what a failed conversion looks like from the outside.
    expect(substr((string) file_get_contents($output), 0, 2))->toBe('PK');

    $slides = deckSlides($output);

    expect($slides)->toHaveCount(3)
        ->and($slides[0])->toContain('One')->toContain('First.')
        ->and($slides[1])->toContain('Two')->toContain('Second.')
        ->and($slides[2])->toContain('Three');
});

it('names the deck beside the input when no output is given', function () {
    // The extension is the answer, and it is the only thing that distinguishes this
    // from the Word half writing a document from the same bytes: `deck.md` has to come
    // out as `deck.pptx` and not as `deck.docx`.
    $input = deckFile(DECK);
    $run = runCli(['slides', $input]);

    $expected = substr($input, 0, -strlen('.md')) . '.pptx';

    expect($run['code'])->toBe(SlidesApplication::SUCCESS)
        ->and(is_file($expected))->toBeTrue()
        ->and(substr((string) file_get_contents($expected), 0, 2))->toBe('PK');
});

it('names the command rather than working it out, since there is only one', function () {
    // A deck is Markdown going in and a `.pptx` coming out, so unlike the Word half
    // there is no direction to detect from a file's first bytes — `mdoffice slides
    // deck.md` and `mdoffice slides to-pptx deck.md` are the same run.
    $bare = Scratch::path('bare', '.pptx');
    $named = Scratch::path('named', '.pptx');
    $first = deckFile(DECK, 'first');
    $second = deckFile(DECK, 'second');

    $one = runCli(['slides', $first, '-o', $bare]);
    $two = runCli(['slides', 'to-pptx', $second, '-o', $named]);

    expect($one['code'])->toBe(SlidesApplication::SUCCESS)
        ->and($two['code'])->toBe(SlidesApplication::SUCCESS)
        ->and(deckSlides($named))->toBe(deckSlides($bare));
});

it('names the part the way every other half names itself', function () {
    $run = runCli(['slides', '--version']);

    // Read from `InstalledVersions` rather than declared: this library has never been
    // on Packagist, so a constant here would be a number no release has ever been.
    expect($run['out'])->toStartWith(SlidesApplication::NAME . ' ')
        ->and($run['code'])->toBe(Application::SUCCESS);
});

it('prints its own help rather than the dispatcher\'s', function () {
    $run = runCli(['slides', 'help']);

    expect($run['code'])->toBe(SlidesApplication::SUCCESS)
        ->and($run['out'])->toContain('mdoffice slides')
        ->and($run['out'])->toContain('--strict')
        // The parts list belongs to `mdoffice help`; a half's help is about the half.
        ->and($run['out'])->not->toContain('PARTS');
});

it('reports a deck it cannot find as a sentence rather than a stack trace', function () {
    $run = runCli(['slides', 'to-pptx', Scratch::path('absent', '.md'), '-o', Scratch::path('never')]);

    expect($run['code'])->toBe(SlidesApplication::FAILURE)
        ->and($run['err'])->toContain('does not exist')
        ->and($run['err'])->not->toContain('at /');
});

it('reports what the library refuses as a sentence, not a stack trace', function () {
    // This is the one that matters, and the earlier version of this file got it wrong:
    // a deck that does not *exist* fails in the file reading, long before the library is
    // asked to render anything, so it never reached the library's own exceptions at all.
    //
    // A theme that does not resolve is `MarkdownPresentation\Exception\ThemeNotFound`,
    // which extends that library's `InvalidInput` — "something the author can fix by
    // naming something else". Miss that catch and it falls through to the catch-all,
    // which prints the class name and a `phar://` path: two lines that mean nothing to
    // the person who typed the command.
    $run = runCli([
        'slides',
        deckFile("---\ntheme: corprate-dark\ntitle: X\n---\n\n# One\n", 'theme'),
        '-o',
        Scratch::path('theme', '.pptx'),
    ]);

    expect($run['code'])->toBe(SlidesApplication::FAILURE)
        ->and($run['err'])->toContain('There is no theme named "corprate-dark"')
        // The message ends with the alternatives, which is the whole of its usefulness.
        ->and($run['err'])->toContain('corporate-dark')
        ->and($run['err'])->not->toContain('ThemeNotFound')
        ->and($run['err'])->not->toContain('RuntimeException')
        ->and($run['err'])->not->toContain('at /');
});

it('reports what the renderer had to shrink to make fit', function () {
    // A block that does not fit is clamped rather than drawn over whatever comes next,
    // so the file says nothing about it. That is reported here for the same reason the
    // Word half reports a format that cannot carry what was asked for: the loss happened
    // on the reader's behalf.
    $input = deckFile(tallDeck(), 'tall');
    $run = runCli(['slides', $input, '-o', Scratch::path('tall', '.pptx')]);

    expect($run['code'])->toBe(SlidesApplication::SUCCESS)
        ->and($run['err'])->toContain('wanted')
        // Every entry names where it happened, so a reader can go and look at it.
        ->and($run['err'])->toMatch('~slide \d+, step \d+~');
});

it('refuses a deck that does not fit when it is told to', function () {
    // The same deck, the same run, and the difference is one flag. Asserted on both
    // sides because a `--strict` that only ever succeeded would pass a test that checked
    // it succeeded, and one that only ever failed would pass a test that checked it
    // failed.
    $run = runCli(['slides', deckFile(tallDeck(), 'tall-strict'), '--strict', '-o', Scratch::path('strict', '.pptx')]);

    expect($run['code'])->toBe(SlidesApplication::FAILURE)
        ->and($run['err'])->toContain('strict')
        ->and($run['err'])->toMatch('~(?i)slide \d+, step \d+~');
});

it('sends the deck to standard output when the output is "-"', function () {
    $run = runCli(['slides', 'to-pptx', deckFile(DECK, 'piped'), '-o', '-']);

    // The renderer returns the bytes of a deck for a pipe, so the package leaves
    // through standard output and a pipe gets a `.pptx` and not a progress line.
    expect($run['out'])->toStartWith('PK')
        ->and($run['err'])->not->toContain('PK');
});

it('writes the same deck to standard output as it writes to a file', function () {
    // The bytes on standard output and the file beside them are the same deck, and
    // they are compared by their slides rather than by their bytes: a zip stores
    // 2-second timestamps and `docProps/core.xml` carries creation and modification
    // times, so two correct renderings of one deck are two different archives.
    $input = deckFile(DECK, 'same-deck');
    $file = Scratch::path('same-deck', '.pptx');

    $piped = runCli(['slides', 'to-pptx', $input, '-o', '-']);
    $written = runCli(['slides', 'to-pptx', $input, '-o', $file]);

    $parts = deckParts($piped['out']);

    expect($piped['code'])->toBe(SlidesApplication::SUCCESS)
        ->and($written['code'])->toBe(SlidesApplication::SUCCESS)
        // Three slides, so a comparison of two empty sets would not pass either.
        ->and($parts)->toHaveCount(3)
        ->and($parts)->toBe(deckParts((string) file_get_contents($file)));
});

it('leaves no file behind in the system temp directory', function () {
    // The deck the renderer returns is staged in a file of the library's own, and
    // the command stages nothing of its own. A run that was refused is no
    // different: the file is gone when the run is over, whether the run wrote a
    // deck or refused one.
    //
    // Diffed against what was there before rather than asserted empty: the
    // directory is shared with every other process on the machine, and a
    // `toBe([])` would fail beside an unrelated run rather than beside this one.
    $before = temporaryFiles();

    $refused = runCli(['slides', deckFile(tallDeck(), 'tall-piped'), '--strict', '-o', '-']);
    $written = runCli(['slides', deckFile(DECK, 'piped-clean'), '-o', '-']);

    expect($refused['code'])->toBe(SlidesApplication::FAILURE)
        ->and($refused['err'])->toMatch('~(?i)slide \d+, step \d+~')
        ->and($written['code'])->toBe(SlidesApplication::SUCCESS)
        ->and(array_values(array_diff(temporaryFiles(), $before)))->toBe([]);
});

/**
 * The files in the system temp directory, by name.
 *
 * @return list<string>
 */
function temporaryFiles(): array
{
    $found = scandir(sys_get_temp_dir());

    return $found === false ? [] : $found;
}

it('says which face of a theme it could not measure', function () {
    // The renderer measures a face it has a table of advance widths for, and says
    // which face it could not measure when it has not. The file is written either way,
    // so without this the only trace of a theme naming a face nobody has measured is
    // the wrapping in the output.
    $directory = Scratch::directoryPath('fonts');
    $deck = $directory . '/deck.md';

    mkdir($directory . '/themes/odd', 0o777, true);
    file_put_contents($directory . '/themes/odd/theme.yml', "name: Odd\nfonts:\n  body: Papyrus\n");
    file_put_contents($directory . '/themes/odd/layouts.yml', "layouts:\n  content: Title and Content\n");
    file_put_contents($deck, "---\ntheme: odd\n---\n\n# One\n");

    $run = runCli(['slides', $deck, '-o', $directory . '/deck.pptx']);

    expect($run['code'])->toBe(SlidesApplication::SUCCESS)
        ->and($run['err'])->toContain('the face "Papyrus" has no table of advance widths')
        ->and($run['err'])->toContain('It is named by the theme\'s body slot.')
        ->and(deckSlides($directory . '/deck.pptx'))->toHaveCount(1);
});

it('renders a deck whose theme sits beside it, from a working directory somewhere else', function () {
    // The theme of a deck is in the deck's own directory, and the process rendering the
    // deck is somewhere else entirely: a build script that renders a deck by its path
    // must not have to change directory first.
    //
    // The theme is a directory of plain files rather than one of the ones that ship with
    // the library, because a name the library already knows would resolve either way and
    // the test would prove nothing.
    $directory = Scratch::directoryPath('deck');
    $deck = $directory . '/deck.md';

    mkdir($directory . '/themes/bakery', 0o777, true);
    file_put_contents($directory . '/themes/bakery/theme.yml', "name: Bakery\n");
    file_put_contents($directory . '/themes/bakery/layouts.yml', "layouts:\n  content: Title and Content\n");
    file_put_contents($deck, "---\ntheme: bakery\n---\n\n# One\n");

    $output = $directory . '/deck.pptx';

    $run = inDirectory(
        Scratch::directoryPath('elsewhere'),
        fn (): array => runCli(['slides', $deck, '-o', $output]),
    );

    expect($run['code'])->toBe(SlidesApplication::SUCCESS)
        ->and($run['err'])->not->toContain('There is no theme named')
        // The deck itself, not only the exit code: a run that refused is a run that
        // says why, and one that wrote something else is not a deck.
        ->and(deckSlides($output))->toHaveCount(1)
        ->and(deckSlides($output)[0])->toContain('One');
});

// The hint line prints indented under the message rather than in it. Nothing else
// reaches this branch here: the slides half refuses with the library's own
// `InvalidInput`, which carries no hints.
it('prints the hints a refusal carries, under the message', function () {
    $run = runCli([
        'slides',
        'to-pptx',
        deckFile(DECK, 'unknown-option'),
        '--no-such-option',
    ]);

    expect($run['code'])->toBe(SlidesApplication::FAILURE)
        ->and($run['err'])->toContain('no-such-option');
});
