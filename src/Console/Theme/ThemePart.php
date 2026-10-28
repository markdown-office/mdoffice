<?php

declare(strict_types=1);

namespace MarkdownOffice\Console\Theme;

use DOMElement;

/**
 * The theme part of a deck: the colours it declares and the faces it sets.
 *
 * A theme part is what a deck carries rather than what a deck is drawn in: a deck
 * made in a tool that does not do themes carries the one Office ships and never
 * draws in it, and what that part declares describes nothing on the page.
 */
final class ThemePart
{
    /**
     * @param array<string, string> $scheme The colour slots the part declares.
     * @param array{heading: ?string, body: ?string} $fonts The latin typefaces.
     */
    private function __construct(
        private readonly array $scheme,
        private readonly array $fonts,
    ) {
    }

    /**
     * The theme part of a deck.
     */
    public static function of(Package $package): self
    {
        $part = self::partOf($package);

        if ($part === '') {
            return new self([], ['heading' => null, 'body' => null]);
        }

        $document = $package->document($part);
        $elements = Xml::child($document->documentElement, Xml::DRAWINGML, 'themeElements');

        return new self(self::schemeOf($elements), self::fontsOf($elements));
    }

    /**
     * The colour scheme the deck's own theme part declares.
     *
     * Empty when the deck has no theme part, or when the part declares no scheme.
     *
     * @return array<string, string>
     */
    public function scheme(): array
    {
        return $this->scheme;
    }

    /**
     * The latin typefaces the deck's own theme part declares: the major face for
     * headings and the minor face for body text.
     *
     * @return array{heading: ?string, body: ?string}
     */
    public function fonts(): array
    {
        return $this->fonts;
    }

    /**
     * The theme part of a package, or an empty string when it has none.
     */
    private static function partOf(Package $package): string
    {
        foreach ($package->parts() as $name) {
            if (preg_match('~^ppt/theme/theme(\d+)\.xml$~', $name) === 1) {
                return $name;
            }
        }

        return '';
    }

    /**
     * The colour slots a theme part declares.
     *
     * @return array<string, string>
     */
    private static function schemeOf(?DOMElement $elements): array
    {
        $colours = Xml::child($elements, Xml::DRAWINGML, 'clrScheme');

        if ($colours === null) {
            return [];
        }

        $scheme = [];

        foreach ($colours->childNodes as $node) {
            if (! $node instanceof DOMElement || $node->namespaceURI !== Xml::DRAWINGML) {
                continue;
            }

            $colour = self::colourOf($node);
            $name = $node->localName;

            if ($colour !== null && $name !== null) {
                $scheme[$name] = $colour;
            }
        }

        return $scheme;
    }

    /**
     * The latin typefaces a theme part sets.
     *
     * @return array{heading: ?string, body: ?string}
     */
    private static function fontsOf(?DOMElement $elements): array
    {
        $fonts = Xml::child($elements, Xml::DRAWINGML, 'fontScheme');

        if ($fonts === null) {
            return ['heading' => null, 'body' => null];
        }

        return [
            'heading' => self::latin($fonts, 'majorFont'),
            'body' => self::latin($fonts, 'minorFont'),
        ];
    }

    /**
     * The latin typeface of a font scheme's major or minor face.
     */
    private static function latin(?DOMElement $fonts, string $face): ?string
    {
        $group = Xml::child($fonts, Xml::DRAWINGML, $face);
        $latin = Xml::child($group, Xml::DRAWINGML, 'latin');
        $typeface = $latin?->getAttribute('typeface') ?? '';

        // The theme's own faces are written as `+mj-lt` and `+mn-lt`, which name
        // the scheme rather than a face; a typeface the scheme does not name is no
        // face at all.
        if ($typeface === '' || str_starts_with($typeface, '+')) {
            return null;
        }

        return $typeface;
    }

    /**
     * The colour a scheme slot holds, as a hex value.
     *
     * A system colour carries the colour it last was in `lastClr`, which is what
     * DrawingML records beside it for exactly this: the slot's colour is not
     * readable from the system colour alone.
     */
    private static function colourOf(DOMElement $slot): ?string
    {
        $colour = Xml::child($slot, Xml::DRAWINGML, 'srgbClr');

        if ($colour !== null) {
            $value = $colour->getAttribute('val');

            return $value === '' ? null : strtoupper($value);
        }

        $system = Xml::child($slot, Xml::DRAWINGML, 'sysClr');

        if ($system === null) {
            return null;
        }

        $value = $system->getAttribute('lastClr');

        return $value === '' ? null : strtoupper($value);
    }
}
