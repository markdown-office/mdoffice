<?php

declare(strict_types=1);

use MarkdownOffice\Console\Theme\Colour;

/*
 * A colour, and what can be said about one.
 *
 * The library reads the colours a deck writes, and the two questions this program
 * asks of a colour — whether it is a colour or a shade, and whether the page it
 * paints is dark — are answered here. The spellings are the library's, so a theme
 * file holds one spelling of a colour and not two.
 */

it('writes a colour the way the theme file writes it', function () {
    // A hash and six hex digits, in upper case: a theme file that holds a colour in
    // lower case beside one in upper case is two spellings of one colour.
    expect(Colour::of('F8FAFC')->hex())->toBe('#F8FAFC')
        ->and(Colour::of('#F8FAFC')->hex())->toBe('#F8FAFC')
        ->and(Colour::of('f8fafc')->hex())->toBe('#F8FAFC');
});

it('says whether a colour is a colour rather than a shade', function () {
    expect(Colour::of('4472C4')->isChromatic())->toBeTrue()
        ->and(Colour::of('808080')->isChromatic())->toBeFalse();
});

it('says whether a colour is dark', function () {
    expect(Colour::of('0D1B2A')->isDark())->toBeTrue()
        ->and(Colour::of('000000')->isDark())->toBeTrue()
        ->and(Colour::of('F8FAFC')->isDark())->toBeFalse();
});

it('has no channels to answer with when what was written is not a colour', function () {
    // A deck that writes something else where a colour belongs is a deck that says
    // nothing about the colour, and nothing is the answer.
    expect(Colour::of('not a colour')->isDark())->toBeFalse()
        ->and(Colour::of('not a colour')->isChromatic())->toBeFalse()
        // And the colour is written as the deck wrote it: a colour that is not one
        // is not replaced with one the deck never had.
        ->and(Colour::of('ZZZZZZ')->hex())->toBe('#ZZZZZZ');
});
