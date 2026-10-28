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
 * names a slot of the theme actually comes out in, and that is what
 * {@see Colours} is for.
 *
 * The order of the slides is the presentation's own and not the order the parts
 * are named in. `ppt/slides/slide1.xml` is not necessarily the first slide of a
 * deck, and a diff that paired the slides by their part names would pair the
 * wrong slides.
 */
final class Deck
{
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

        if (!$package->has(Package::PRESENTATION)) {
            throw new ConsoleException(
                sprintf('The deck "%s" is not a presentation.', $path),
                ['A .pptx holds a "ppt/presentation.xml"; this archive does not.'],
            );
        }

        $presentation = $package->document(Package::PRESENTATION);
        $size = Xml::child($presentation->documentElement, Xml::PRESENTATIONML, 'sldSz');
        $colours = Colours::of($package);

        $slides = [];
        $number = 0;

        foreach (self::slideParts($package, $presentation) as $part) {
            $number++;

            $slides[] = self::slide($package, $part['part'], $part['id'], $number, $colours);
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
        $relations = $package->relationships(Package::PRESENTATION);
        $list = Xml::child($presentation->documentElement, Xml::PRESENTATIONML, 'sldIdLst');
        $found = [];

        if ($list !== null) {
            foreach ($list->childNodes as $node) {
                if (!$node instanceof DOMElement || $node->localName !== 'sldId') {
                    continue;
                }

                $target = $relations[$node->getAttributeNS(Xml::RELATIONSHIPS, 'id')] ?? '';

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

    private static function slide(
        Package $package,
        string $part,
        string $id,
        int $number,
        Colours $colours,
    ): Slide {
        $document = $package->document($part);
        $map = $colours->mapOf($document);
        $shapes = [];

        foreach (self::shapesOf($document) as $node) {
            $shapes[] = self::shape($node, $colours, $map);
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
        $tree = Xml::child(
            Xml::child($document->documentElement, Xml::PRESENTATIONML, 'cSld'),
            Xml::PRESENTATIONML,
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
            if (!$node instanceof DOMElement || $node->namespaceURI !== Xml::PRESENTATIONML) {
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
     * @param array<string, string> $map The colour map the slide is drawn in.
     */
    private static function shape(DOMElement $node, Colours $colours, array $map): Shape
    {
        $own = $node->getElementsByTagNameNS(Xml::PRESENTATIONML, 'cNvPr')->item(0);
        $name = '';
        $id = 0;

        if ($own instanceof DOMElement) {
            $name = $own->getAttribute('name');
            $id = (int) $own->getAttribute('id');
        }

        $properties = Xml::child($node, Xml::PRESENTATIONML, 'spPr')
            ?? Xml::child($node, Xml::PRESENTATIONML, 'grpSpPr');

        return new Shape(
            $name,
            $id,
            self::geometry($node, $properties),
            self::fill($properties, $colours, $map),
            self::paragraphs($node),
            self::runs($node, $colours, $map),
        );
    }

    /**
     * Where a shape is, when it says so itself.
     */
    private static function geometry(DOMElement $node, ?DOMElement $properties): Geometry
    {
        $transform = $properties === null ? null : Xml::child($properties, Xml::DRAWINGML, 'xfrm');

        if ($transform === null) {
            // A table or a chart states its own frame, and it does so in the
            // presentation's own namespace rather than the drawing one.
            $transform = Xml::child($node, Xml::PRESENTATIONML, 'xfrm');
        }

        if ($transform === null) {
            return new Geometry();
        }

        $offset = Xml::child($transform, Xml::DRAWINGML, 'off');
        $extent = Xml::child($transform, Xml::DRAWINGML, 'ext');

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
     * @param array<string, string> $map The colour map the slide is drawn in.
     */
    private static function fill(?DOMElement $properties, Colours $colours, array $map): ?string
    {
        if ($properties === null) {
            return null;
        }

        $solid = Xml::child($properties, Xml::DRAWINGML, 'solidFill');

        if ($solid === null) {
            return null;
        }

        return $colours->colour($solid, $map);
    }

    /**
     * The text of the paragraphs of a shape.
     *
     * @return list<string>
     */
    private static function paragraphs(DOMElement $node): array
    {
        $body = Xml::child($node, Xml::PRESENTATIONML, 'txBody');

        if ($body === null) {
            return [];
        }

        $found = [];

        foreach ($body->getElementsByTagNameNS(Xml::DRAWINGML, 'p') as $paragraph) {
            $text = '';

            foreach ($paragraph->getElementsByTagNameNS(Xml::DRAWINGML, 't') as $piece) {
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
     * @param array<string, string> $map The colour map the slide is drawn in.
     * @return list<array{text: string, colour: string|null}>
     */
    private static function runs(DOMElement $node, Colours $colours, array $map): array
    {
        $found = [];

        foreach ($node->getElementsByTagNameNS(Xml::DRAWINGML, 'r') as $run) {
            $text = '';
            $colour = null;
            $properties = Xml::child($run, Xml::DRAWINGML, 'rPr');

            foreach ($run->getElementsByTagNameNS(Xml::DRAWINGML, 't') as $piece) {
                $text .= $piece->textContent;
            }

            if ($properties !== null) {
                $solid = Xml::child($properties, Xml::DRAWINGML, 'solidFill');

                if ($solid !== null) {
                    $colour = $colours->colour($solid, $map);
                }
            }

            $found[] = ['text' => $text, 'colour' => $colour];
        }

        return $found;
    }
}
