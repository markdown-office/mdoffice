<?php

declare(strict_types=1);

namespace MarkdownOffice\Console\Slides\Diff;

use DOMDocument;
use DOMElement;
use MarkdownOffice\Console\ConsoleException;

/**
 * A deck, read out of its package for the questions a diff asks.
 *
 * What is read is the text of every shape, where every shape is and what colour
 * every fill and every run is drawn in — and nothing else. The theme part and
 * the master are read for one reason only: to say what colour a shape that
 * names a slot of the theme actually comes out in.
 *
 * The order of the slides is the presentation's own and not the order the parts
 * are named in. `ppt/slides/slide1.xml` is not necessarily the first slide of a
 * deck, and a diff that paired the slides by their part names would pair the
 * wrong slides.
 */
final class Deck
{
    private const PRESENTATIONML = 'http://schemas.openxmlformats.org/presentationml/2006/main';
    private const DRAWINGML = 'http://schemas.openxmlformats.org/drawingml/2006/main';
    private const RELATIONSHIPS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /**
     * The shapes of a slide that are compared, by the name the format gives them.
     */
    private const SHAPES = ['sp', 'pic', 'graphicFrame', 'cxnSp'];

    /**
     * @param list<Slide> $slides The slides of the deck, in the order the deck holds them.
     */
    private function __construct(
        private readonly string $path,
        private readonly int $width,
        private readonly int $height,
        private readonly array $slides,
    ) {
    }

    /**
     * Read a deck.
     *
     * @throws ConsoleException when the file is not there, or is not a presentation.
     */
    public static function open(string $path): self
    {
        $package = Package::open($path);

        try {
            return self::read($package);
        } finally {
            $package->close();
        }
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * How wide the slides are, in EMU.
     */
    public function width(): int
    {
        return $this->width;
    }

    /**
     * How tall the slides are, in EMU.
     */
    public function height(): int
    {
        return $this->height;
    }

    /**
     * The slides of the deck, in the order the deck holds them.
     *
     * @return list<Slide>
     */
    public function slides(): array
    {
        return $this->slides;
    }

    private static function read(Package $package): self
    {
        $path = $package->path();

        if (!$package->has('ppt/presentation.xml')) {
            throw new ConsoleException(
                sprintf('The deck "%s" is not a presentation.', $path),
                ['A .pptx holds a "ppt/presentation.xml"; this archive does not.'],
            );
        }

        $presentation = $package->document('ppt/presentation.xml');
        $size = self::child($presentation->documentElement, self::PRESENTATIONML, 'sldSz');
        $theme = self::theme($package);
        $map = self::colourMap($package);

        $slides = [];
        $number = 0;

        foreach (self::slideParts($package, $presentation) as $part) {
            $number++;

            $slides[] = self::slide($package, $part['part'], $part['id'], $number, $theme, $map);
        }

        return new self(
            $path,
            $size === null ? 0 : (int) $size->getAttribute('cx'),
            $size === null ? 0 : (int) $size->getAttribute('cy'),
            $slides,
        );
    }

    /**
     * The slides of the deck, with the id each one carries.
     *
     * @return list<array{part: string, id: string}>
     */
    private static function slideParts(Package $package, DOMDocument $presentation): array
    {
        $relations = self::relations($package);
        $list = self::child($presentation->documentElement, self::PRESENTATIONML, 'sldIdLst');
        $found = [];

        if ($list !== null) {
            foreach ($list->childNodes as $node) {
                if (!$node instanceof DOMElement || $node->localName !== 'sldId') {
                    continue;
                }

                $target = $relations[$node->getAttributeNS(self::RELATIONSHIPS, 'id')] ?? '';

                if ($target !== '') {
                    $found[] = ['part' => $target, 'id' => $node->getAttribute('id')];
                }
            }
        }

        if ($found !== []) {
            return $found;
        }

        // A package with no list of slides still has slide parts, and they are
        // taken in the order they are numbered in.
        $parts = [];

        foreach ($package->parts() as $name) {
            if (preg_match('~^ppt/slides/slide(\d+)\.xml$~', $name, $matches) === 1) {
                $parts[(int) $matches[1]] = $name;
            }
        }

        ksort($parts);

        $found = [];

        foreach ($parts as $name) {
            $found[] = ['part' => $name, 'id' => ''];
        }

        return $found;
    }

    /**
     * The relationships of the presentation part, by id.
     *
     * @return array<string, string>
     */
    private static function relations(Package $package): array
    {
        if (!$package->has('ppt/_rels/presentation.xml.rels')) {
            return [];
        }

        $document = $package->document('ppt/_rels/presentation.xml.rels');
        $found = [];

        foreach (self::elements($document) as $node) {
            if ($node->localName !== 'Relationship') {
                continue;
            }

            $found[$node->getAttribute('Id')] = self::resolve('ppt', $node->getAttribute('Target'));
        }

        return $found;
    }

    /**
     * A target against the part it is written in, as the path of a part.
     */
    private static function resolve(string $base, string $target): string
    {
        $segments = explode('/', $base);

        foreach (explode('/', $target) as $segment) {
            if ($segment === '..') {
                array_pop($segments);

                continue;
            }

            if ($segment !== '' && $segment !== '.') {
                $segments[] = $segment;
            }
        }

        return implode('/', $segments);
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
     * The colour map a slide states for itself.
     *
     * A slide may paint in a map of its own rather than the master's, and a
     * colour read through the wrong one is the wrong colour.
     *
     * @param array<string, string> $master
     * @return array<string, string>
     */
    private static function colourMapOf(DOMDocument $document, array $master): array
    {
        $over = self::child($document->documentElement, self::PRESENTATIONML, 'clrMapOvr');

        if ($over === null) {
            return $master;
        }

        $own = self::child($over, self::DRAWINGML, 'overrideClrMapping');

        if ($own === null) {
            return $master;
        }

        return self::attributes($own);
    }

    /**
     * @param array<string, string> $theme
     * @param array<string, string> $map
     */
    private static function slide(
        Package $package,
        string $part,
        string $id,
        int $number,
        array $theme,
        array $map,
    ): Slide {
        $document = $package->document($part);
        $own = self::colourMapOf($document, $map);
        $shapes = [];

        foreach (self::shapesOf($document) as $node) {
            $shapes[] = self::shape($node, $theme, $own);
        }

        return new Slide($number, $id, $shapes);
    }

    /**
     * The shapes of a slide.
     *
     * A group of shapes is taken apart and its shapes compared as shapes of the
     * slide, because the shapes in it are the ones that carry the text and the
     * colour. The frame of the group itself is not compared.
     *
     * @return list<DOMElement>
     */
    private static function shapesOf(DOMDocument $document): array
    {
        $tree = self::child(
            self::child($document->documentElement, self::PRESENTATIONML, 'cSld'),
            self::PRESENTATIONML,
            'spTree',
        );

        if ($tree === null) {
            return [];
        }

        return self::shapesIn($tree);
    }

    /**
     * @return list<DOMElement>
     */
    private static function shapesIn(DOMElement $tree): array
    {
        $found = [];

        foreach ($tree->childNodes as $node) {
            if (!$node instanceof DOMElement || $node->namespaceURI !== self::PRESENTATIONML) {
                continue;
            }

            if ($node->localName === 'grpSp') {
                foreach (self::shapesIn($node) as $inside) {
                    $found[] = $inside;
                }

                continue;
            }

            if (in_array($node->localName, self::SHAPES, true)) {
                $found[] = $node;
            }
        }

        return $found;
    }

    /**
     * @param array<string, string> $theme
     * @param array<string, string> $map
     */
    private static function shape(DOMElement $node, array $theme, array $map): Shape
    {
        $own = $node->getElementsByTagNameNS(self::PRESENTATIONML, 'cNvPr')->item(0);
        $name = '';
        $id = 0;

        if ($own instanceof DOMElement) {
            $name = $own->getAttribute('name');
            $id = (int) $own->getAttribute('id');
        }

        $properties = self::child($node, self::PRESENTATIONML, 'spPr')
            ?? self::child($node, self::PRESENTATIONML, 'grpSpPr');

        return new Shape(
            $name,
            $id,
            self::geometry($node, $properties),
            self::fill($properties, $theme, $map),
            self::paragraphs($node),
            self::runs($node, $theme, $map),
        );
    }

    /**
     * Where a shape is, when it says so itself.
     */
    private static function geometry(DOMElement $node, ?DOMElement $properties): Geometry
    {
        $transform = $properties === null ? null : self::child($properties, self::DRAWINGML, 'xfrm');

        if ($transform === null) {
            // A table or a chart states its own frame, and it does so in the
            // presentation's own namespace rather than the drawing one.
            $transform = self::child($node, self::PRESENTATIONML, 'xfrm');
        }

        if ($transform === null) {
            return new Geometry();
        }

        $offset = self::child($transform, self::DRAWINGML, 'off');
        $extent = self::child($transform, self::DRAWINGML, 'ext');

        return new Geometry(
            $offset === null ? null : (int) $offset->getAttribute('x'),
            $offset === null ? null : (int) $offset->getAttribute('y'),
            $extent === null ? null : (int) $extent->getAttribute('cx'),
            $extent === null ? null : (int) $extent->getAttribute('cy'),
        );
    }

    /**
     * The colour a shape is filled with, when it says so itself.
     *
     * @param array<string, string> $theme
     * @param array<string, string> $map
     */
    private static function fill(?DOMElement $properties, array $theme, array $map): ?string
    {
        if ($properties === null) {
            return null;
        }

        $solid = self::child($properties, self::DRAWINGML, 'solidFill');

        if ($solid === null) {
            return null;
        }

        return self::colour($solid, $theme, $map);
    }

    /**
     * The colour a fill is painted in.
     *
     * A colour that names a slot of the theme is the colour that slot holds, so
     * two decks that paint the same colour in two ways are the same deck. A colour
     * that carries a change on it — shaded, made transparent — is compared as the
     * colour it names together with the change, because the change is not carried
     * out here.
     *
     * @param array<string, string> $theme
     * @param array<string, string> $map
     */
    private static function colour(DOMElement $fill, array $theme, array $map): ?string
    {
        $literal = self::child($fill, self::DRAWINGML, 'srgbClr');

        if ($literal !== null) {
            $value = $literal->getAttribute('val');

            return $value === '' ? null : self::withChanges($literal, strtoupper($value));
        }

        $slot = self::child($fill, self::DRAWINGML, 'schemeClr');

        if ($slot !== null) {
            $name = $slot->getAttribute('val');
            $resolved = $theme[$map[$name] ?? $name] ?? null;

            return self::withChanges($slot, $resolved ?? $name);
        }

        $system = self::child($fill, self::DRAWINGML, 'sysClr');

        if ($system === null) {
            return null;
        }

        $value = $system->getAttribute('lastClr');

        return $value === '' ? null : self::withChanges($system, strtoupper($value));
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
     * The text of the paragraphs of a shape.
     *
     * @return list<string>
     */
    private static function paragraphs(DOMElement $node): array
    {
        $body = self::child($node, self::PRESENTATIONML, 'txBody');

        if ($body === null) {
            return [];
        }

        $found = [];

        foreach ($body->getElementsByTagNameNS(self::DRAWINGML, 'p') as $paragraph) {
            $text = '';

            foreach ($paragraph->getElementsByTagNameNS(self::DRAWINGML, 't') as $piece) {
                $text .= $piece->textContent;
            }

            $text = trim($text);

            if ($text !== '') {
                $found[] = $text;
            }
        }

        return $found;
    }

    /**
     * The runs a shape is drawn in, and the colour each is drawn in.
     *
     * @param array<string, string> $theme
     * @param array<string, string> $map
     * @return list<array{text: string, colour: string|null}>
     */
    private static function runs(DOMElement $node, array $theme, array $map): array
    {
        $found = [];

        foreach ($node->getElementsByTagNameNS(self::DRAWINGML, 'r') as $run) {
            $text = '';
            $colour = null;
            $properties = self::child($run, self::DRAWINGML, 'rPr');

            foreach ($run->getElementsByTagNameNS(self::DRAWINGML, 't') as $piece) {
                $text .= $piece->textContent;
            }

            if ($properties !== null) {
                $solid = self::child($properties, self::DRAWINGML, 'solidFill');

                if ($solid !== null) {
                    $colour = self::colour($solid, $theme, $map);
                }
            }

            $found[] = ['text' => $text, 'colour' => $colour];
        }

        return $found;
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
     * The child elements of a document's root.
     *
     * @return list<DOMElement>
     */
    private static function elements(DOMDocument $document): array
    {
        $found = [];
        $root = $document->documentElement;

        if ($root === null) {
            return $found;
        }

        foreach ($root->childNodes as $node) {
            if ($node instanceof DOMElement) {
                $found[] = $node;
            }
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
