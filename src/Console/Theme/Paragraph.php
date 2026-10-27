<?php

declare(strict_types=1);

namespace MarkdownOffice\Console\Theme;

/**
 * One paragraph of a deck, as the type scale reads it.
 *
 * A paragraph rather than a run, because a run is a piece of a sentence: the brand
 * word in the accent colour inside a logo, a phrase in bold inside a line. A
 * paragraph is what carries one size — a deck sets a paragraph's type, and the runs
 * inside it are that size with the words coloured differently.
 */
final class Paragraph
{
    /**
     * @param int         $size       The size in points; 0 when the paragraph states none.
     * @param bool        $bold       Whether the paragraph's type is bold.
     * @param string|null $face       The typeface, or null when the paragraph names none.
     * @param string|null $colour     The colour, or null when the paragraph names none.
     * @param int         $words      The words the paragraph holds.
     * @param int         $characters The characters the paragraph holds.
     * @param int|null    $leading    The line spacing in hundredths of a percent, or null.
     */
    public function __construct(
        private readonly int $size,
        private readonly bool $bold,
        private readonly ?string $face,
        private readonly ?string $colour,
        private readonly int $words,
        private readonly int $characters,
        private readonly ?int $leading,
    ) {
    }

    public function size(): int
    {
        return $this->size;
    }

    public function isBold(): bool
    {
        return $this->bold;
    }

    public function face(): ?string
    {
        return $this->face;
    }

    public function colour(): ?string
    {
        return $this->colour;
    }

    public function words(): int
    {
        return $this->words;
    }

    public function characters(): int
    {
        return $this->characters;
    }

    public function leading(): ?int
    {
        return $this->leading;
    }
}
