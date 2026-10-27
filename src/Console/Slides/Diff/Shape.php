<?php

declare(strict_types=1);

namespace MarkdownOffice\Console\Slides\Diff;

/**
 * One shape of a slide: what it says, where it is and what colour it is drawn in.
 *
 * The text is held twice over, as the paragraphs a reader sees and as the runs
 * the type is drawn in, because the two answer different questions. The
 * paragraphs say what the slide says, and the runs say what colour each piece of
 * it is drawn in.
 */
final class Shape
{
    /**
     * @param list<string> $paragraphs The text of its paragraphs.
     * @param list<array{text: string, colour: string|null}> $runs The runs it is drawn in.
     */
    public function __construct(
        public readonly string $name,
        public readonly int $id,
        public readonly Geometry $geometry,
        public readonly ?string $fill,
        public readonly array $paragraphs,
        public readonly array $runs,
    ) {
    }
}
