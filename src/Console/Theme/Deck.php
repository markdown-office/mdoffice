<?php

declare(strict_types=1);

namespace MarkdownOffice\Console\Theme;

use MarkdownOffice\Console\ConsoleException;

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
 *
 * The deck itself is read by the readers that know what they are looking for —
 * {@see ThemePart} for the theme part, {@see Slides} for the slides and
 * {@see Layouts} for the layouts — and this is the one place that asks them all
 * and holds what they answered.
 */
final class Deck
{
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
     * Read a deck out of an opened package.
     *
     * @throws ConsoleException when the package is not a presentation.
     */
    private static function read(Package $package): self
    {
        $path = $package->path();

        if (!$package->has('ppt/presentation.xml')) {
            throw new ConsoleException(
                sprintf('The deck "%s" is not a presentation.', $path),
                ['A .pptx holds a "ppt/presentation.xml"; this archive does not.'],
            );
        }

        $slides = Slides::of($package);
        $theme = ThemePart::of($package);

        return new self(
            $path,
            $theme->scheme(),
            $theme->fonts(),
            self::colourMapOf($package),
            $slides->pages(),
            $slides->colours(),
            $slides->paragraphs(),
            Layouts::of($package, $slides->usage())->layouts(),
            $slides->count(),
            $slides->schemeUses(),
            $slides->sizes(),
            self::property($package, 'title'),
            self::property($package, 'creator'),
        );
    }

    /**
     * The colour map of the deck's master.
     *
     * @return array<string, string>
     */
    private static function colourMapOf(Package $package): array
    {
        $map = [];

        foreach ($package->parts() as $name) {
            if (preg_match('~^ppt/slideMasters/slideMaster(\d+)\.xml$~', $name) !== 1) {
                continue;
            }

            $document = $package->document($name);
            $element = Xml::child($document->documentElement, Xml::PRESENTATIONML, 'clrMap');

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
     * The title or the author the deck's own properties carry.
     */
    private static function property(Package $package, string $name): ?string
    {
        if (! in_array('docProps/core.xml', $package->parts(), true)) {
            return null;
        }

        $document = $package->document('docProps/core.xml');
        $element = Xml::child($document->documentElement, Xml::CORE, $name);
        $value = $element === null ? '' : trim($element->textContent);

        return $value === '' ? null : $value;
    }
}
