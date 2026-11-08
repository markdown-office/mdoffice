<?php

declare(strict_types=1);

namespace MarkdownOffice\Console\Theme;

use DOMElement;

/**
 * The master of a deck: the colour map it declares and the page it paints.
 *
 * A master states its page as a colour of its own or as a reference to a background
 * style of the theme part, and only the colour is the deck's own: a deck that
 * carries a theme part it never drew in has a theme part that says nothing about
 * its page, so a reference is not followed.
 */
final class Master
{
    private const PART = '~^ppt/slideMasters/slideMaster(\d+)\.xml$~';

    /**
     * @param array<string, string> $colourMap The master's colour map.
     * @param string|null          $page      The colour the master paints its page with.
     */
    private function __construct(
        private readonly array $colourMap,
        private readonly ?string $page,
    ) {
    }

    /**
     * The master of a deck, or null where the deck has none.
     */
    public static function of(Package $package): ?self
    {
        $map = [];
        $page = null;
        $found = false;

        foreach ($package->parts() as $name) {
            if (preg_match(self::PART, $name) !== 1) {
                continue;
            }

            $found = true;
            $document = $package->document($name);
            $root = $document->documentElement;

            $map = [...$map, ...self::colourMapOf($root)];

            $colour = self::pageOf(Xml::child($root, Xml::PRESENTATIONML, 'cSld'));

            if ($colour !== null) {
                $page = $colour;
            }
        }

        return $found ? new self($map, $page) : null;
    }

    /**
     * The master's colour map: which slot paints the page, which the type, and so on.
     *
     * @return array<string, string>
     */
    public function colourMap(): array
    {
        return $this->colourMap;
    }

    /**
     * The colour the master paints its page with, or null where it states none.
     */
    public function page(): ?string
    {
        return $this->page;
    }

    /**
     * @return array<string, string>
     */
    private static function colourMapOf(?DOMElement $root): array
    {
        $element = Xml::child($root, Xml::PRESENTATIONML, 'clrMap');

        if ($element === null) {
            return [];
        }

        $map = [];

        foreach ($element->attributes as $attribute) {
            $map[$attribute->nodeName] = $attribute->nodeValue ?? '';
        }

        return $map;
    }

    private static function pageOf(?DOMElement $common): ?string
    {
        $background = Xml::child($common, Xml::PRESENTATIONML, 'bg');
        $properties = Xml::child($background, Xml::PRESENTATIONML, 'bgPr');
        $fill = Xml::child($properties, Xml::DRAWINGML, 'solidFill');
        $element = Xml::child($fill, Xml::DRAWINGML, 'srgbClr');
        $value = $element?->getAttribute('val') ?? '';

        return $value === '' ? null : strtoupper($value);
    }
}
