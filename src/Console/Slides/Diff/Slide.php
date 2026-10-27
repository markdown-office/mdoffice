<?php

declare(strict_types=1);

namespace MarkdownOffice\Console\Slides\Diff;

/**
 * One slide of a deck.
 *
 * The number is the slide's place in the deck and the id is the one the deck
 * gives it. The two are not the same fact: the number moves when a slide is
 * inserted before it, and the id does not, which is why a diff pairs the slides
 * of two decks by their id.
 */
final class Slide
{
    /**
     * @param list<Shape> $shapes The shapes of the slide.
     */
    public function __construct(
        public readonly int $number,
        public readonly string $id,
        public readonly array $shapes,
    ) {
    }
}
