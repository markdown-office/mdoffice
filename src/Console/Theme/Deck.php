<?php

declare(strict_types=1);

namespace MarkdownOffice\Console\Theme;

use DOMDocument;
use DOMElement;
use MarkdownOffice\Console\ConsoleException;
use ZipArchive;

/**
 * A deck, read out of its package.
 *
 * A `.pptx` is a zip archive of XML parts, and everything a theme is made of is in
 * there: the colour scheme and the fonts in the theme part, the colours and the
 * type on the slides, the placeholders of the layouts, and the title in the
 * document's own properties.
 *
 * Read from the package rather than through PHPPresentation because the parts that
 * matter here are the ones PHPPresentation does not keep: it reads a deck into
 * slides and shapes and leaves the theme part's font scheme and the layouts'
 * placeholders behind. A theme is made of those.
 */
final class Deck
{
    private const PRESENTATIONML = 'http://schemas.openxmlformats.org/presentationml/2006/main';
    private const DRAWINGML = 'http://schemas.openxmlformats.org/drawingml/2006/main';
    private const CORE = 'http://purl.org/dc/elements/1.1/';

    /**
     * @param array<string, string>                       $scheme     The theme part's colour slots.
     * @param array{heading: ?string, body: ?string}     $fonts      The theme part's latin typefaces.
     * @param array<string, string>                       $colourMap  The master's colour map.
     * @param array<array-key, int>                       $pages      The background colours and how many slides set each.
     * @param array<array-key, array{fills: int, outlines: int, text: int}> $colours The colours the deck uses.
     * @param list<Paragraph>                             $paragraphs The paragraphs of the slides.
     * @param list<Layout>                                $layouts    The layouts of the deck.
     * @param int                                         $slides     How many slides the deck has.
     * @param int                                         $schemeUses How often the deck names its theme's own slots.
     * @param list<array{size: int, paragraphs: int}>    $slideSizes The largest type on each slide, and how much text it carries.
     * @param string|null                                 $title      The deck's own title.
     * @param string|null                                 $creator    The deck's own author.
     */
    private function __construct(
        private readonly string $path,
        private readonly array $scheme,
        private readonly array $fonts,
        private readonly array $colourMap,
        private readonly array $pages,
        private readonly array $colours,
        private readonly array $paragraphs,
        private readonly array $layouts,
        private readonly int $slides,
        private readonly int $schemeUses,
        private readonly array $slideSizes,
        private readonly ?string $title,
        private readonly ?string $creator,
    ) {
    }

    /**
     * Read a deck.
     *
     * @throws ConsoleException when the file is not there, or not a presentation.
     */
    public static function open(string $path): self
    {
        if (!is_file($path)) {
            throw new ConsoleException(
                sprintf('The deck "%s" does not exist.', $path),
                ['Name the deck to read.'],
            );
        }

        $zip = new ZipArchive();

        if ($zip->open($path) !== true) {
            throw new ConsoleException(
                sprintf('The deck "%s" could not be opened.', $path),
                ['A .pptx is a zip archive; this file is not one.'],
            );
        }

        try {
            $parts = self::parts($zip);

            if (! isset($parts['ppt/presentation.xml'])) {
                throw new ConsoleException(
                    sprintf('The deck "%s" is not a presentation.', $path),
                    ['A .pptx holds a "ppt/presentation.xml"; this archive does not.'],
                );
            }

            $slides = self::slideParts($parts);
            $usage = [];
            $paragraphs = [];
            $pages = [];
            $colours = [];
            $schemeUses = 0;
            $slideSizes = [];

            foreach ($slides as $part) {
                $document = self::document($zip, $part, $path);
                $words = 0;
                $largest = 0;
                $read = self::paragraphsOf($document);

                foreach ($read as $paragraph) {
                    $paragraphs[] = $paragraph;
                    $words += $paragraph->words();
                    $largest = max($largest, $paragraph->size());
                }

                $slideSizes[] = ['size' => $largest, 'paragraphs' => count($read)];

                $pages = self::count($pages, self::backgroundOf($document));
                [$colours, $uses] = self::countColours($colours, $document);
                $schemeUses += $uses;

                $layout = self::layoutPartOf($zip, $part, $path);

                if ($layout !== '') {
                    $usage[$layout]['slides'] = ($usage[$layout]['slides'] ?? 0) + 1;
                    $usage[$layout]['words'] = ($usage[$layout]['words'] ?? 0) + $words;
                }
            }

            return new self(
                $path,
                self::schemeOf($zip, $parts, $path),
                self::fontsOf($zip, $parts, $path),
                self::colourMapOf($zip, $parts, $path),
                $pages,
                $colours,
                $paragraphs,
                self::layoutsOf($zip, $parts, $usage, $path),
                count($slides),
                $schemeUses,
                $slideSizes,
                self::property($zip, $parts, 'title'),
                self::property($zip, $parts, 'creator'),
            );
        } finally {
            $zip->close();
        }
    }

    public function path(): string
    {
        return $this->path;
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
     * The master's colour map: which slot paints the page, which the type, and so
     * on. A deck that uses its theme says in the map which of its slots is the
     * background.
     *
     * @return array<string, string>
     */
    public function colourMap(): array
    {
        return $this->colourMap;
    }

    /**
     * The background colours the slides set, and how many slides set each.
     *
     * A list rather than a mapping, because a colour may be all digits — a deck
     * with a `#102234` page — and PHP turns such a key into a number.
     *
     * @return list<array{colour: string, slides: int}>
     */
    public function pages(): array
    {
        $pages = [];

        foreach ($this->pages as $colour => $slides) {
            $pages[] = ['colour' => (string) $colour, 'slides' => $slides];
        }

        usort($pages, static fn (array $left, array $right): int => $right['slides'] <=> $left['slides']);

        return $pages;
    }

    /**
     * The colours the deck uses, and what it uses them for: a shape filled with the
     * colour, a shape outlined with it, or type drawn in it.
     *
     * A list rather than a mapping, for the reason {@see self::pages()} gives.
     *
     * @return list<array{colour: string, fills: int, outlines: int, text: int}>
     */
    public function colours(): array
    {
        $colours = [];

        foreach ($this->colours as $colour => $uses) {
            $colours[] = [
                'colour' => (string) $colour,
                'fills' => $uses['fills'],
                'outlines' => $uses['outlines'],
                'text' => $uses['text'],
            ];
        }

        usort(
            $colours,
            static fn (array $left, array $right): int => self::drawn($right) <=> self::drawn($left),
        );

        return $colours;
    }

    /**
     * How often a colour is drawn with, in any of the ways there are.
     *
     * @param array{colour: string, fills: int, outlines: int, text: int} $use
     */
    private static function drawn(array $use): int
    {
        return $use['fills'] + $use['outlines'] + $use['text'];
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
     * The layouts of the deck, in the order the package lists them.
     *
     * @return list<Layout>
     */
    public function layouts(): array
    {
        return $this->layouts;
    }

    /**
     * How many slides the deck has.
     */
    public function slides(): int
    {
        return $this->slides;
    }

    /**
     * Whether the deck is drawn in the theme its theme part declares.
     *
     * A deck that names its theme's colour slots is drawn in that theme, and its
     * palette is the theme's. A deck that names literal colours everywhere is not:
     * it carries the theme part it was given and never drew in it, and the
     * palette in that part describes nothing on the page.
     */
    public function usesTheme(): bool
    {
        return $this->schemeUses > 0;
    }

    /**
     * The largest type on each slide, and how many paragraphs the slide carries.
     *
     * The two together say what a slide is: a title and a little else is a
     * divider, a title and a page of text is a content slide.
     *
     * @return list<array{size: int, paragraphs: int}>
     */
    public function slideSizes(): array
    {
        return $this->slideSizes;
    }

    /**
     * The deck's own title, or null when it has none.
     */
    public function title(): ?string
    {
        return $this->title;
    }

    /**
     * The deck's own author, or null when it has none.
     */
    public function creator(): ?string
    {
        return $this->creator;
    }

    /**
     * @return array<string, string>
     */
    private static function parts(ZipArchive $zip): array
    {
        $parts = [];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string) $zip->getNameIndex($index);

            if (str_ends_with($name, '/')) {
                continue;
            }

            $parts[$name] = $name;
        }

        return $parts;
    }

    /**
     * The slide parts, in the order the deck holds them.
     *
     * @param array<string, string> $parts
     * @return array<int, string>
     */
    private static function slideParts(array $parts): array
    {
        $slides = [];

        foreach ($parts as $name) {
            if (preg_match('~^ppt/slides/slide(\d+)\.xml$~', $name, $matches) === 1) {
                $slides[(int) $matches[1]] = $name;
            }
        }

        ksort($slides);

        return $slides;
    }

    /**
     * The colour scheme of the deck's theme part.
     *
     * @param array<string, string> $parts
     * @return array<string, string>
     */
    private static function schemeOf(ZipArchive $zip, array $parts, string $path): array
    {
        $part = self::themePart($parts);

        if ($part === '') {
            return [];
        }

        $document = self::document($zip, $part, $path);
        $elements = self::child($document->documentElement, self::DRAWINGML, 'themeElements');
        $colours = self::child($elements, self::DRAWINGML, 'clrScheme');

        if ($colours === null) {
            return [];
        }

        $scheme = [];

        foreach ($colours->childNodes as $node) {
            if (! $node instanceof DOMElement || $node->namespaceURI !== self::DRAWINGML) {
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
     * The latin typefaces of the deck's theme part.
     *
     * @param array<string, string> $parts
     * @return array{heading: ?string, body: ?string}
     */
    private static function fontsOf(ZipArchive $zip, array $parts, string $path): array
    {
        $part = self::themePart($parts);

        if ($part === '') {
            return ['heading' => null, 'body' => null];
        }

        $document = self::document($zip, $part, $path);
        $elements = self::child($document->documentElement, self::DRAWINGML, 'themeElements');
        $fonts = self::child($elements, self::DRAWINGML, 'fontScheme');

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
        $group = self::child($fonts, self::DRAWINGML, $face);
        $latin = self::child($group, self::DRAWINGML, 'latin');
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
     * The colour map of the deck's master.
     *
     * @param array<string, string> $parts
     * @return array<string, string>
     */
    private static function colourMapOf(ZipArchive $zip, array $parts, string $path): array
    {
        $map = [];

        foreach ($parts as $name) {
            if (preg_match('~^ppt/slideMasters/slideMaster(\d+)\.xml$~', $name) !== 1) {
                continue;
            }

            $document = self::document($zip, $name, $path);
            $element = self::child($document->documentElement, self::PRESENTATIONML, 'clrMap');

            if ($element === null) {
                continue;
            }

            foreach ($element->attributes as $attribute) {
                $map[$attribute->nodeName] = $attribute->nodeValue ?? '';
            }
        }

        return $map;
    }

    /**
     * The layouts of the deck, with the slides that use them.
     *
     * @param array<string, string>                                  $parts
     * @param array<string, array{slides: int, words: int}>          $usage The slides and words, by layout part.
     * @return list<Layout>
     */
    private static function layoutsOf(ZipArchive $zip, array $parts, array $usage, string $path): array
    {
        $layouts = [];

        foreach ($parts as $name) {
            if (preg_match('~^ppt/slideLayouts/slideLayout(\d+)\.xml$~', $name, $matches) !== 1) {
                continue;
            }

            $document = self::document($zip, $name, $path);
            $layouts[(int) $matches[1]] = self::layoutFrom($document, $name, $usage);
        }

        ksort($layouts);

        return array_values($layouts);
    }

    /**
     * One layout: its name and its placeholders.
     *
     * @param array<string, array{slides: int, words: int}> $usage
     */
    private static function layoutFrom(DOMDocument $document, string $part, array $usage): Layout
    {
        $root = self::child($document->documentElement, self::PRESENTATIONML, 'cSld');
        $name = $root?->getAttribute('name') ?? '';
        $placeholders = [];

        $tree = self::child($root, self::PRESENTATIONML, 'spTree');

        if ($tree !== null) {
            foreach ($tree->childNodes as $node) {
                if (! $node instanceof DOMElement || $node->namespaceURI !== self::PRESENTATIONML) {
                    continue;
                }

                $properties = self::child(self::child($node, self::PRESENTATIONML, 'nvSpPr'), self::PRESENTATIONML, 'nvPr');
                $placeholder = self::child($properties, self::PRESENTATIONML, 'ph');

                if ($placeholder === null) {
                    continue;
                }

                // A placeholder states no type when it is an ordinary content
                // placeholder, which is what the default means.
                $type = $placeholder->getAttribute('type');

                $placeholders[] = $type === '' ? 'body' : $type;
            }
        }

        return new Layout(
            $name,
            $placeholders,
            $usage[$part]['slides'] ?? 0,
            $usage[$part]['words'] ?? 0,
        );
    }

    /**
     * The layout part a slide uses.
     */
    private static function layoutPartOf(ZipArchive $zip, string $part, string $path): string
    {
        $rels = dirname($part) . '/_rels/' . basename($part) . '.rels';

        if ($zip->getFromName($rels) === false) {
            return '';
        }

        $document = self::document($zip, $rels, $path);

        foreach (self::elements($document) as $node) {
            if ($node->localName !== 'Relationship') {
                continue;
            }

            if (str_ends_with($node->getAttribute('Type'), '/slideLayout')) {
                return self::resolve(dirname($part), $node->getAttribute('Target'));
            }
        }

        return '';
    }

    /**
     * A target against the part it is written in, as the path of a part.
     *
     * `../slideLayouts/slideLayout1.xml` written in `ppt/slides` is
     * `ppt/slideLayouts/slideLayout1.xml`.
     */
    private static function resolve(string $base, string $target): string
    {
        $path = $base;

        foreach (explode('/', $target) as $segment) {
            if ($segment === '..') {
                $path = dirname($path);

                continue;
            }

            if ($segment !== '' && $segment !== '.') {
                $path .= '/' . $segment;
            }
        }

        return $path;
    }

    /**
     * The background colour a slide sets, or null when it sets none.
     */
    private static function backgroundOf(DOMDocument $document): ?string
    {
        $common = self::child($document->documentElement, self::PRESENTATIONML, 'cSld');
        $background = self::child($common, self::PRESENTATIONML, 'bg');
        $properties = self::child($background, self::PRESENTATIONML, 'bgPr');
        $fill = self::child($properties, self::DRAWINGML, 'solidFill');

        if ($fill === null) {
            return null;
        }

        $colour = self::child($fill, self::DRAWINGML, 'srgbClr');

        return $colour?->getAttribute('val') ?: null;
    }

    /**
     * The paragraphs of a slide, read the way the type scale wants them.
     *
     * @return list<Paragraph>
     */
    private static function paragraphsOf(DOMDocument $document): array
    {
        $paragraphs = [];

        foreach ($document->getElementsByTagNameNS(self::DRAWINGML, 'p') as $node) {
            $size = 0;
            $bold = false;
            $face = null;
            $faceCharacters = 0;
            $colour = null;
            $colourCharacters = 0;
            $words = 0;
            $characters = 0;

            $properties = self::child($node, self::DRAWINGML, 'pPr');
            $spacing = self::child(self::child($properties, self::DRAWINGML, 'lnSpc'), self::DRAWINGML, 'spcPct');
            $leading = $spacing === null ? null : (int) $spacing->getAttribute('val');

            foreach ($node->childNodes as $child) {
                if (! $child instanceof DOMElement || $child->localName !== 'r') {
                    continue;
                }

                $text = '';
                $runSize = 0;
                $runBold = false;
                $runFace = null;
                $runColour = null;

                foreach ($child->childNodes as $runChild) {
                    if (! $runChild instanceof DOMElement) {
                        continue;
                    }

                    if ($runChild->localName === 't') {
                        $text .= $runChild->textContent;

                        continue;
                    }

                    if ($runChild->localName !== 'rPr') {
                        continue;
                    }

                    $runSize = (int) ($runChild->getAttribute('sz') ?: 0);
                    $runBold = $runChild->getAttribute('b') === '1';
                    $latin = self::child($runChild, self::DRAWINGML, 'latin');
                    $typeface = $latin?->getAttribute('typeface') ?? '';

                    // `+mn-lt` and `+mj-lt` name the theme's own faces rather than a
                    // face, and the theme part says which those are.
                    $runFace = $typeface === '' || str_starts_with($typeface, '+') ? null : $typeface;

                    $fill = self::child($runChild, self::DRAWINGML, 'solidFill');
                    $srgb = self::child($fill, self::DRAWINGML, 'srgbClr');
                    $runColour = $srgb?->getAttribute('val') ?: '';
                    $runColour = $runColour === '' ? null : strtoupper($runColour);
                }

                $length = mb_strlen($text);

                if ($length === 0) {
                    continue;
                }

                $words += self::words($text);
                $characters += $length;

                if ($runSize > $size) {
                    $size = $runSize;
                }

                $bold = $bold || $runBold;

                if ($runFace !== null && $length > $faceCharacters) {
                    $face = $runFace;
                    $faceCharacters = $length;
                }

                if ($runColour !== null && $length > $colourCharacters) {
                    $colour = $runColour;
                    $colourCharacters = $length;
                }
            }

            if ($characters === 0) {
                continue;
            }

            $paragraphs[] = new Paragraph(
                intdiv($size, 100),
                $bold,
                $face,
                $colour,
                $words,
                $characters,
                $leading,
            );
        }

        return $paragraphs;
    }

    /**
     * The words a text holds.
     */
    private static function words(string $text): int
    {
        $parts = preg_split('/\s+/u', trim($text)) ?: [];

        return count(array_filter($parts, static fn (string $word): bool => $word !== ''));
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

        foreach ($document->getElementsByTagNameNS(self::PRESENTATIONML, 'spPr') as $node) {
            $fill = self::child($node, self::DRAWINGML, 'solidFill');
            $outline = self::lineOf($node);

            if ($fill !== null) {
                $scheme += self::countColour($colours, $fill, 'fills');
            }

            if ($outline !== null) {
                $scheme += self::countColour($colours, $outline, 'outlines');
            }
        }

        foreach ($document->getElementsByTagNameNS(self::DRAWINGML, 'rPr') as $node) {
            $fill = self::child($node, self::DRAWINGML, 'solidFill');

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
        $colour = self::child($fill, self::DRAWINGML, 'srgbClr');
        $value = $colour?->getAttribute('val') ?: '';

        if ($value === '') {
            return self::child($fill, self::DRAWINGML, 'schemeClr') === null ? 0 : 1;
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
        $line = self::child($shape, self::DRAWINGML, 'ln');

        return self::child($line, self::DRAWINGML, 'solidFill');
    }

    /**
     * @param array<string, int> $counts
     * @return array<string, int>
     */
    private static function count(array $counts, ?string $value): array
    {
        if ($value === null) {
            return $counts;
        }

        $value = strtoupper($value);
        $counts[$value] = ($counts[$value] ?? 0) + 1;

        return $counts;
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
     * The title or the author the deck's own properties carry.
     *
     * @param array<string, string> $parts
     */
    private static function property(ZipArchive $zip, array $parts, string $name): ?string
    {
        if (! isset($parts['docProps/core.xml'])) {
            return null;
        }

        $document = self::document($zip, 'docProps/core.xml', '');
        $element = self::child($document->documentElement, self::CORE, $name);
        $value = $element === null ? '' : trim($element->textContent);

        return $value === '' ? null : $value;
    }

    /**
     * @param array<string, string> $parts
     */
    private static function themePart(array $parts): string
    {
        foreach ($parts as $name) {
            if (preg_match('~^ppt/theme/theme(\d+)\.xml$~', $name) === 1) {
                return $name;
            }
        }

        return '';
    }

    /**
     * A part of the package, as a document.
     *
     * @throws ConsoleException when the part is not XML.
     */
    private static function document(ZipArchive $zip, string $part, string $path): DOMDocument
    {
        $source = $zip->getFromName($part);

        if ($source === false) {
            throw new ConsoleException(sprintf('The deck "%s" has no "%s" part.', $path, $part));
        }

        $quiet = libxml_use_internal_errors(true);
        $document = new DOMDocument();
        $loaded = $document->loadXML($source, LIBXML_NONET);

        libxml_clear_errors();
        libxml_use_internal_errors($quiet);

        if (! $loaded) {
            throw new ConsoleException(sprintf('The part "%s" of the deck "%s" is not XML.', $part, $path));
        }

        return $document;
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
