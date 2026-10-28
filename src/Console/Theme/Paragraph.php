<?php

declare(strict_types=1);

namespace MarkdownOffice\Console\Theme;

use DOMElement;

/**
 * One paragraph of a deck, as the type scale reads it.
 *
 * A paragraph rather than a run, because a run is a piece of a sentence: the brand
 * word in the accent colour inside a logo, a phrase in bold inside a line. A
 * paragraph is what carries one size — a deck sets a paragraph's type, and the runs
 * inside it are that size with the words coloured differently.
 *
 * @phpstan-type Run array{size: int, bold: bool, face: ?string, colour: ?string, words: int, characters: int}
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

    /**
     * One paragraph, read the way the type scale reads it.
     *
     * Null when the paragraph holds no text: a deck carries empty paragraphs, and
     * one of them says nothing about the type.
     */
    public static function of(DOMElement $node): ?self
    {
        $characters = 0;
        $words = 0;
        $size = 0;
        $bold = false;
        $face = null;
        $faceCharacters = 0;
        $colour = null;
        $colourCharacters = 0;

        foreach (self::runs($node) as $run) {
            $characters += $run['characters'];
            $words += $run['words'];

            if ($run['size'] > $size) {
                $size = $run['size'];
            }

            $bold = $bold || $run['bold'];

            if ($run['face'] !== null && $run['characters'] > $faceCharacters) {
                $face = $run['face'];
                $faceCharacters = $run['characters'];
            }

            if ($run['colour'] !== null && $run['characters'] > $colourCharacters) {
                $colour = $run['colour'];
                $colourCharacters = $run['characters'];
            }
        }

        if ($characters === 0) {
            return null;
        }

        return new self(
            intdiv($size, 100),
            $bold,
            $face,
            $colour,
            $words,
            $characters,
            self::leadingOf($node),
        );
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

    /**
     * The runs of a paragraph, with the type each is drawn in.
     *
     * @return list<Run>
     */
    private static function runs(DOMElement $node): array
    {
        $runs = [];

        foreach ($node->childNodes as $child) {
            if (! $child instanceof DOMElement || $child->localName !== 'r') {
                continue;
            }

            $run = self::run($child);

            if ($run !== null) {
                $runs[] = $run;
            }
        }

        return $runs;
    }

    /**
     * One run: its text and the type it is drawn in.
     *
     * Null when the run holds no text: a run of nothing says nothing about the
     * type, and it carries no characters either.
     *
     * @return Run|null
     */
    private static function run(DOMElement $node): ?array
    {
        $text = '';
        $size = 0;
        $bold = false;
        $face = null;
        $colour = null;

        foreach ($node->childNodes as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }

            if ($child->localName === 't') {
                $text .= $child->textContent;

                continue;
            }

            if ($child->localName !== 'rPr') {
                continue;
            }

            $size = (int) ($child->getAttribute('sz') ?: 0);
            $bold = $child->getAttribute('b') === '1';
            $face = self::faceOf($child);
            $colour = self::colourOf($child);
        }

        $characters = mb_strlen($text);

        if ($characters === 0) {
            return null;
        }

        return [
            'size' => $size,
            'bold' => $bold,
            'face' => $face,
            'colour' => $colour,
            'words' => self::wordsOf($text),
            'characters' => $characters,
        ];
    }

    /**
     * The face a run is drawn in.
     */
    private static function faceOf(DOMElement $properties): ?string
    {
        $latin = Xml::child($properties, Xml::DRAWINGML, 'latin');
        $typeface = $latin?->getAttribute('typeface') ?? '';

        // `+mn-lt` and `+mj-lt` name the theme's own faces rather than a face, and
        // the theme part says which those are.
        return $typeface === '' || str_starts_with($typeface, '+') ? null : $typeface;
    }

    /**
     * The colour a run is drawn in.
     */
    private static function colourOf(DOMElement $properties): ?string
    {
        $fill = Xml::child($properties, Xml::DRAWINGML, 'solidFill');
        $srgb = Xml::child($fill, Xml::DRAWINGML, 'srgbClr');
        $value = $srgb?->getAttribute('val') ?: '';

        return $value === '' ? null : strtoupper($value);
    }

    /**
     * The line spacing a paragraph sets, or null when it sets none.
     */
    private static function leadingOf(DOMElement $node): ?int
    {
        $properties = Xml::child($node, Xml::DRAWINGML, 'pPr');
        $spacing = Xml::child(
            Xml::child($properties, Xml::DRAWINGML, 'lnSpc'),
            Xml::DRAWINGML,
            'spcPct',
        );

        return $spacing === null ? null : (int) $spacing->getAttribute('val');
    }

    /**
     * The words a text holds.
     */
    private static function wordsOf(string $text): int
    {
        $parts = preg_split('/\s+/u', trim($text)) ?: [];

        return count(array_filter($parts, static fn (string $word): bool => $word !== ''));
    }
}
