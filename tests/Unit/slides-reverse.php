<?php

declare(strict_types=1);

use MarkdownOffice\Console\Slides\SlidesApplication;
use MarkdownOffice\Tests\Support\Scratch;

/*
 * The slides part, the other way round: a PowerPoint deck in, the Markdown this
 * tool writes decks in out.
 *
 * The deck is written by the tool itself, so the two ends of the round trip are
 * the same code and the claim being checked is that a deck survives the journey
 * through the text.
 */

const ROUND_TRIP = "# One\n\nFirst.\n\n---\n\n# Two\n\nSecond.\n";

/**
 * A deck written by the tool, and its path.
 */
function writtenDeck(string $markdown = ROUND_TRIP): string
{
    $output = Scratch::path('deck', '.pptx');
    $source = Scratch::path('source', '.md');

    file_put_contents($source, $markdown);

    $run = runCli(['slides', $source, '-o', $output]);

    expect($run['code'])->toBe(SlidesApplication::SUCCESS);

    return $output;
}

it('turns a deck into the Markdown the tool writes decks in', function () {
    $output = Scratch::path('deck', '.md');
    $run = runCli(['slides', 'to-markdown', writtenDeck(), '-o', $output]);

    expect($run['code'])->toBe(SlidesApplication::SUCCESS)
        ->and(is_file($output))->toBeTrue();

    $markdown = (string) file_get_contents($output);

    expect($markdown)->toContain('# One')
        ->and($markdown)->toContain('First.')
        ->and($markdown)->toContain('# Two')
        ->and($markdown)->toContain('Second.');
});

it('writes the Markdown to standard output when it is asked for', function () {
    $run = runCli(['slides', 'to-markdown', writtenDeck(), '-o', '-']);

    expect($run['code'])->toBe(SlidesApplication::SUCCESS)
        ->and($run['out'])->toContain('# One');
});

it('names the deck beside the input when no output is given', function () {
    $input = writtenDeck();
    $run = runCli(['slides', 'to-markdown', $input]);

    $expected = substr($input, 0, -strlen('.pptx')) . '.md';

    expect($run['code'])->toBe(SlidesApplication::SUCCESS)
        ->and(is_file($expected))->toBeTrue()
        ->and((string) file_get_contents($expected))->toContain('# One');
});

it('carries a deck through the text and back', function () {
    $first = writtenDeck();
    $markdown = Scratch::path('deck', '.md');

    expect(runCli(['slides', 'to-markdown', $first, '-o', $markdown])['code'])
        ->toBe(SlidesApplication::SUCCESS);

    $second = Scratch::path('again', '.pptx');

    expect(runCli(['slides', 'to-pptx', $markdown, '-o', $second])['code'])
        ->toBe(SlidesApplication::SUCCESS);

    $one = deckSlides($first);
    $two = deckSlides($second);

    expect($two)->toHaveCount(count($one))
        ->and($two[0])->toContain('One')->toContain('First.')
        ->and($two[1])->toContain('Two')->toContain('Second.');
});

it('refuses to write the Markdown over the deck it was made from', function () {
    $input = writtenDeck();
    $run = runCli(['slides', 'to-markdown', $input, '-o', $input]);

    expect($run['code'])->toBe(SlidesApplication::FAILURE)
        ->and($run['err'])->toContain('would overwrite the input file');
});

it('says which command an option belongs to when it is given to the other one', function () {
    $run = runCli(['slides', 'to-pptx', writtenDeck(), '--media', 'media']);

    expect($run['code'])->toBe(SlidesApplication::FAILURE)
        ->and($run['err'])->toContain('to-markdown');
});

it('prints the help of the command it is asked about', function () {
    $run = runCli(['slides', 'to-markdown', '--help']);

    expect($run['code'])->toBe(SlidesApplication::SUCCESS)
        ->and($run['out'])->toContain('mdoffice slides to-markdown')
        ->and($run['out'])->toContain('--media');
});

it('lists both directions in the part\'s own help', function () {
    $run = runCli(['slides', 'help']);

    expect($run['code'])->toBe(SlidesApplication::SUCCESS)
        ->and($run['out'])->toContain('to-pptx')
        ->and($run['out'])->toContain('to-markdown');
});

it('reads a deck from standard input', function () {
    $deck = (string) file_get_contents(writtenDeck());
    $run = runCli(['slides', 'to-markdown', '-', '-o', '-'], $deck);

    expect($run['code'])->toBe(SlidesApplication::SUCCESS)
        ->and($run['out'])->toContain('# One');
});

it('says so when the deck is not there', function () {
    $run = runCli(['slides', 'to-markdown', '/nowhere/deck.pptx']);

    expect($run['code'])->toBe(SlidesApplication::FAILURE)
        ->and($run['err'])->toContain('does not exist');
});

it('writes the Markdown with the line ending it is asked for', function () {
    $output = Scratch::path('deck', '.md');
    $run = runCli(['slides', 'to-markdown', writtenDeck(), '-o', $output, '--line-ending', 'crlf']);

    expect($run['code'])->toBe(SlidesApplication::SUCCESS)
        ->and((string) file_get_contents($output))->toContain("\r\n");
});

it('refuses a line ending it does not know', function () {
    $run = runCli(['slides', 'to-markdown', writtenDeck(), '--line-ending', 'cr']);

    expect($run['code'])->toBe(SlidesApplication::FAILURE)
        ->and($run['err'])->toContain('Unknown line ending "cr"');
});

it('takes the pictures out of the deck into the media directory', function () {
    // A deck with a picture in it, written by the tool from a deck that names one.
    $source = Scratch::path('pictures', '.md');
    $media = Scratch::directory() . '/media';

    // The picture is in the scratch directory, which is where the deck is written
    // from and where a relative name in it resolves.
    Scratch::image('dot.png');
    file_put_contents($source, "![A dot](dot.png)\n");

    $deck = Scratch::path('with-picture', '.pptx');
    expect(runCli(['slides', $source, '-o', $deck])['code'])->toBe(SlidesApplication::SUCCESS);

    $output = Scratch::path('deck', '.md');

    $run = runCli(['slides', 'to-markdown', $deck, '-o', $output, '--media', $media]);

    expect($run['code'])->toBe(SlidesApplication::SUCCESS)
        ->and((string) file_get_contents($output))->toContain('![')
        ->and(glob($media . '/*'))->not->toBeEmpty();
});
