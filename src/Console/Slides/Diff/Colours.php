<?php

declare(strict_types=1);

namespace MarkdownOffice\Console\Slides\Diff;

use DOMDocument;
use DOMElement;

/**
 * The colours of a deck, as far as a diff cares.
 *
 * The theme part and the master are read for one reason only: to say what colour
 * a shape that names a slot of the theme actually comes out in. A deck that paints
 * in `accent1` and a deck that paints in the colour `accent1` holds are the same
 * deck, and a diff that said otherwise would report a difference that is not one.
 *
 * A colour that carries a change on it — shaded, made transparent — is compared as
 * the colour it names together with the change, because the change is not carried
 * out here.
 */
final class Colours
{
    private const PRESENTATIONML = 'http://schemas.openxmlformats.org/presentationml/2006/main';
    private const DRAWINGML = 'http://schemas.openxmlformats.org/drawingml/2006/main';

    /**
     * @param array<string, string> $theme The colours the deck's own theme part declares.
     * @param array<string, string> $map   The master's colour map: which slot paints what.
     */
    private function __construct(
        private readonly array $theme,
        private readonly array $map,
    ) {
    }

    /**
     * The colours of a deck.
     */
    public static function of(Package $package): self
    {
        return new self(self::theme($package), self::colourMap($package));
    }

    /**
     * The colour map a slide is drawn in.
     *
     * A slide may paint in a map of its own rather than the master's, and a
     * colour read through the wrong one is the wrong colour.
     *
     * @return array<string, string>
     */
    public function mapOf(DOMDocument $document): array
    {
        $over = self::child($document->documentElement, self::PRESENTATIONML, 'clrMapOvr');

        if ($over === null) {
            return $this->map;
        }

        $own = self::child($over, self::DRAWINGML, 'overrideClrMapping');

        if ($own === null) {
            return $this->map;
        }

        return self::attributes($own);
    }

    /**
     * The colour a fill is painted in.
     *
     * A colour that names a slot of the theme is the colour that slot holds, so
     * two decks that paint the same colour in two ways are the same deck.
     *
     * @param array<string, string> $map The colour map the shape is drawn in.
     */
    public function colour(DOMElement $fill, array $map): ?string
    {
        $literal = self::child($fill, self::DRAWINGML, 'srgbClr');

        if ($literal !== null) {
            return self::written($literal, 'val');
        }

        $slot = self::child($fill, self::DRAWINGML, 'schemeClr');

        if ($slot !== null) {
            $name = $slot->getAttribute('val');

            return self::withChanges($slot, $this->theme[$map[$name] ?? $name] ?? $name);
        }

        $system = self::child($fill, self::DRAWINGML, 'sysClr');

        return $system === null ? null : self::written($system, 'lastClr');
    }

    /**
     * The colours of the deck's own theme part.
     *
     * @return array<string, string>
     */
    private static function theme(Package $package): array
    {
        $part = self::firstOf($package, '~^ppt/theme/theme(\d+)\.xml$~');

        if ($part === '') {
            return [];
        }

        $document = $package->document($part);
        $elements = self::child(
            self::child($document->documentElement, self::DRAWINGML, 'themeElements'),
            self::DRAWINGML,
            'clrScheme',
        );

        if ($elements === null) {
            return [];
        }

        $colours = [];

        foreach ($elements->childNodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }

            $colour = self::slotColour($node);
            $slot = $node->localName;

            if ($colour !== null && $slot !== null) {
                $colours[$slot] = $colour;
            }
        }

        return $colours;
    }

    /**
     * The colour a slot of the theme holds.
     *
     * A system colour carries the colour it last was in `lastClr`, which is what
     * DrawingML records beside it for exactly this: the slot's colour is not
     * readable from the system colour alone.
     */
    private static function slotColour(DOMElement $slot): ?string
    {
        $colour = self::child($slot, self::DRAWINGML, 'srgbClr');

        if ($colour !== null) {
            $value = $colour->getAttribute('val');

            return $value === '' ? null : strtoupper($value);
        }

        $system = self::child($slot, self::DRAWINGML, 'sysClr');

        if ($system === null) {
            return null;
        }

        $value = $system->getAttribute('lastClr');

        return $value === '' ? null : strtoupper($value);
    }

    /**
     * The master's colour map: which slot of the theme paints what.
     *
     * @return array<string, string>
     */
    private static function colourMap(Package $package): array
    {
        $part = self::firstOf($package, '~^ppt/slideMasters/slideMaster(\d+)\.xml$~');

        if ($part === '') {
            return [];
        }

        $document = $package->document($part);
        $element = self::child($document->documentElement, self::PRESENTATIONML, 'clrMap');

        if ($element === null) {
            return [];
        }

        return self::attributes($element);
    }

    /**
     * The colour a colour element writes out, with the changes made to it.
     *
     * @param string $attribute The attribute the colour is written in.
     */
    private static function written(DOMElement $colour, string $attribute): ?string
    {
        $value = $colour->getAttribute($attribute);

        return $value === '' ? null : self::withChanges($colour, strtoupper($value));
    }

    /**
     * A colour together with the changes made to it, if it carries any.
     *
     * A change of a hundred per cent changes nothing, and a deck written by
     * PowerPoint says `alpha 100%` on every run it draws, so a colour that
     * carries such a change is the colour it names.
     */
    private static function withChanges(DOMElement $colour, string $value): ?string
    {
        $changes = [];

        foreach ($colour->childNodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }

            $amount = (int) $node->getAttribute('val');

            if ($amount === 100000) {
                continue;
            }

            $changes[] = sprintf(
                '%s %s%%',
                $node->localName,
                number_format($amount / 1000, 1, '.', ''),
            );
        }

        if ($changes === []) {
            return $value === '' ? null : $value;
        }

        return sprintf('%s (%s)', $value, implode(', ', $changes));
    }

    /**
     * The first part of the package whose name matches, in the order the numbers
     * in the names run.
     */
    private static function firstOf(Package $package, string $pattern): string
    {
        $found = [];

        foreach ($package->parts() as $name) {
            if (preg_match($pattern, $name, $matches) === 1) {
                $found[(int) $matches[1]] = $name;
            }
        }

        ksort($found);

        $first = reset($found);

        return $first === false ? '' : $first;
    }

    /**
     * The attributes of an element, by name.
     *
     * @return array<string, string>
     */
    private static function attributes(DOMElement $element): array
    {
        $found = [];

        foreach ($element->attributes as $attribute) {
            $found[$attribute->nodeName] = $attribute->nodeValue ?? '';
        }

        return $found;
    }

    /**
     * The first child element with this name in this namespace, or null.
     */
    private static function child(?DOMElement $parent, string $namespace, string $name): ?DOMElement
    {
        if ($parent === null) {
            return null;
        }

        foreach ($parent->childNodes as $node) {
            if ($node instanceof DOMElement && $node->namespaceURI === $namespace && $node->localName === $name) {
                return $node;
            }
        }

        return null;
    }
}
