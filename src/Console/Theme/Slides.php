<?php

declare(strict_types=1);

namespace MarkdownOffice\Console\Theme;

use DOMDocument;
use DOMElement;

/**
 * The slides of a deck: what is on them.
 *
 * The colours they are drawn in, the backgrounds they set, the type they carry and
 * the layouts they use. A deck that names the slots of its own theme rather than
 * the colours themselves is drawn in that theme, and this is where that is found
 * out.
 */
final class Slides
{
    /**
     * @param list<Paragraph> $paragraphs The paragraphs of the slides.
     * @param array<array-key, int> $pages The background colours and how many slides set each.
     * @param array<array-key, array{fills: int, outlines: int, text: int}> $colours The colours the deck uses.
     * @param list<array{size: int, paragraphs: int}> $sizes The largest type on each slide, and how much text it carries.
     * @param int $schemeUses How often the deck names its theme's own slots.
     * @param array<string, array{slides: int, words: int}> $usage The slides and words, by layout part.
     * @param int $count How many slides the deck has.
     */
    private function __construct(
        private readonly array $paragraphs,
        private readonly array $pages,
        private readonly array $colours,
        private readonly array $sizes,
        private readonly int $schemeUses,
        private readonly array $usage,
        private readonly int $count,
    ) {
    }

    /**
     * The slides of a deck.
     */
    public static function of(Package $package): self
    {
        $slides = self::slideParts($package);
        $paragraphs = [];
        $pages = [];
        $colours = [];
        $sizes = [];
        $schemeUses = 0;
        $usage = [];

        foreach ($slides as $part) {
            $document = $package->document($part);
            $words = 0;
            $largest = 0;
            $read = self::paragraphsOf($document);

            foreach ($read as $paragraph) {
                $paragraphs[] = $paragraph;
                $words += $paragraph->words();
                $largest = max($largest, $paragraph->size());
            }

            $sizes[] = ['size' => $largest, 'paragraphs' => count($read)];

            $pages = self::withBackground($pages, self::backgroundOf($document));
            [$colours, $uses] = self::countColours($colours, $document);
            $schemeUses += $uses;

            $layout = self::layoutPartOf($package, $part);

            if ($layout !== '') {
                $usage[$layout]['slides'] = ($usage[$layout]['slides'] ?? 0) + 1;
                $usage[$layout]['words'] = ($usage[$layout]['words'] ?? 0) + $words;
            }
        }

        return new self($paragraphs, $pages, $colours, $sizes, $schemeUses, $usage, count($slides));
    }

    /**
     * The paragraphs of the slides, in the order the deck holds them.
     *
     * @return list<Paragraph>
     */
    public function paragraphs(): array
    {
        return $this->paragraphs;
    }

    /**
     * The background colours the slides set, and how many slides set each.
     *
     * A mapping rather than a list, because the colour is the key: a deck with a
     * `#102234` page has a key that is all digits, and PHP turns such a key into
     * a number.
     *
     * @return array<array-key, int>
     */
    public function pages(): array
    {
        return $this->pages;
    }

    /**
     * The colours the deck uses, and what it uses them for: a shape filled with the
     * colour, a shape outlined with it, or type drawn in it.
     *
     * A mapping rather than a list, for the reason {@see self::pages()} gives.
     *
     * @return array<array-key, array{fills: int, outlines: int, text: int}>
     */
    public function colours(): array
    {
        return $this->colours;
    }

    /**
     * The largest type on each slide, and how many paragraphs the slide carries.
     *
     * The two together say what a slide is: a title and a little else is a
     * divider, a title and a page of text is a content slide.
     *
     * @return list<array{size: int, paragraphs: int}>
     */
    public function sizes(): array
    {
        return $this->sizes;
    }

    /**
     * How often the deck names the slots of its own theme rather than literal
     * colours.
     *
     * A deck that does that is drawn in its theme, and one that does not is not.
     */
    public function schemeUses(): int
    {
        return $this->schemeUses;
    }

    /**
     * The slides and the words of them, by the layout they use.
     *
     * @return array<string, array{slides: int, words: int}>
     */
    public function usage(): array
    {
        return $this->usage;
    }

    /**
     * How many slides the deck has.
     */
    public function count(): int
    {
        return $this->count;
    }

    /**
     * The slide parts, in the order the deck holds them.
     *
     * @return list<string>
     */
    private static function slideParts(Package $package): array
    {
        $slides = [];

        foreach ($package->parts() as $name) {
            if (preg_match('~^ppt/slides/slide(\d+)\.xml$~', $name, $matches) === 1) {
                $slides[(int) $matches[1]] = $name;
            }
        }

        ksort($slides);

        return array_values($slides);
    }

    /**
     * The paragraphs of a slide, read the way the type scale wants them.
     *
     * @return list<Paragraph>
     */
    private static function paragraphsOf(DOMDocument $document): array
    {
        $paragraphs = [];

        foreach ($document->getElementsByTagNameNS(Xml::DRAWINGML, 'p') as $node) {
            $paragraph = Paragraph::of($node);

            if ($paragraph !== null) {
                $paragraphs[] = $paragraph;
            }
        }

        return $paragraphs;
    }

    /**
     * The background colour a slide sets, or null when it sets none.
     */
    private static function backgroundOf(DOMDocument $document): ?string
    {
        $common = Xml::child($document->documentElement, Xml::PRESENTATIONML, 'cSld');
        $background = Xml::child($common, Xml::PRESENTATIONML, 'bg');
        $properties = Xml::child($background, Xml::PRESENTATIONML, 'bgPr');
        $fill = Xml::child($properties, Xml::DRAWINGML, 'solidFill');

        if ($fill === null) {
            return null;
        }

        $colour = Xml::child($fill, Xml::DRAWINGML, 'srgbClr');

        return $colour?->getAttribute('val') ?: null;
    }

    /**
     * The colours a slide uses, and what it uses them for.
     *
     * A shape is filled with one colour and outlined with another, and the two are
     * counted apart: a filled block is what a panel is, and an outline is not.
     * The second number the method returns is how often the slide named a colour
     * of the deck's own theme rather than a literal one: a deck that does that is
     * drawn in its theme, and one that does not is not.
     *
     * @param array<array-key, array{fills: int, outlines: int, text: int}> $colours
     * @return array{0: array<array-key, array{fills: int, outlines: int, text: int}>, 1: int}
     */
    private static function countColours(array $colours, DOMDocument $document): array
    {
        $scheme = 0;

        foreach ($document->getElementsByTagNameNS(Xml::PRESENTATIONML, 'spPr') as $node) {
            $fill = Xml::child($node, Xml::DRAWINGML, 'solidFill');
            $outline = self::lineOf($node);

            if ($fill !== null) {
                $scheme += self::countColour($colours, $fill, 'fills');
            }

            if ($outline !== null) {
                $scheme += self::countColour($colours, $outline, 'outlines');
            }
        }

        foreach ($document->getElementsByTagNameNS(Xml::DRAWINGML, 'rPr') as $node) {
            $fill = Xml::child($node, Xml::DRAWINGML, 'solidFill');

            if ($fill === null) {
                continue;
            }

            $scheme += self::countColour($colours, $fill, 'text');
        }

        return [$colours, $scheme];
    }

    /**
     * Count one colour a shape is drawn with.
     *
     * The second number the method returns is whether the colour was one of the
     * deck's own theme rather than a literal one.
     *
     * @param array<array-key, array{fills: int, outlines: int, text: int}> $colours
     * @param 'fills'|'outlines'|'text' $slot
     */
    private static function countColour(array &$colours, DOMElement $fill, string $slot): int
    {
        $colour = Xml::child($fill, Xml::DRAWINGML, 'srgbClr');
        $value = $colour?->getAttribute('val') ?: '';

        if ($value === '') {
            return Xml::child($fill, Xml::DRAWINGML, 'schemeClr') === null ? 0 : 1;
        }

        $colour = strtoupper($value);
        $colours[$colour] ??= ['fills' => 0, 'outlines' => 0, 'text' => 0];
        $colours[$colour][$slot]++;

        return 0;
    }

    /**
     * The fill of a shape's outline, or null when it has none.
     */
    private static function lineOf(DOMElement $shape): ?DOMElement
    {
        $line = Xml::child($shape, Xml::DRAWINGML, 'ln');

        return Xml::child($line, Xml::DRAWINGML, 'solidFill');
    }

    /**
     * The counts of the backgrounds, with one more in them.
     *
     * @param array<string, int> $counts
     * @return array<string, int>
     */
    private static function withBackground(array $counts, ?string $value): array
    {
        if ($value === null) {
            return $counts;
        }

        $value = strtoupper($value);
        $counts[$value] = ($counts[$value] ?? 0) + 1;

        return $counts;
    }

    /**
     * The layout part a slide uses.
     */
    private static function layoutPartOf(Package $package, string $part): string
    {
        $rels = dirname($part) . '/_rels/' . basename($part) . '.rels';

        if (!$package->has($rels)) {
            return '';
        }

        $document = $package->document($rels);

        foreach (Xml::elements($document) as $node) {
            if ($node->localName !== 'Relationship') {
                continue;
            }

            if (str_ends_with($node->getAttribute('Type'), '/slideLayout')) {
                return Package::resolve(dirname($part), $node->getAttribute('Target'));
            }
        }

        return '';
    }
}
