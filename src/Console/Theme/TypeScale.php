<?php

declare(strict_types=1);

namespace MarkdownOffice\Console\Theme;

use MarkdownPresentation\Theme\StyleMapping;

/**
 * The type scale of a theme, read out of a deck.
 *
 * A `.pptx` does not record a scale. It records a size for every placeholder at
 * every level, and the levels a deck uses are the levels that deck happens to
 * use: there is no "the body size" in a presentation, only the sizes the slides
 * are written at. So the scale is read off the deck's own text and inferred, and
 * every inference is reported with where it was read from.
 *
 * The reading is this:
 *
 * - The **title** is the size the deck's slides are headed in, which is the size
 *   most slides carry as their largest type.
 * - The **cover** is the largest type on the first slide, which is the slide that
 *   introduces the deck.
 * - The **section** is the size of a divider's title: a slide that carries a
 *   title and little else.
 * - The **body** is the size the deck's prose is written at, in the face the prose
 *   is written in.
 * - The **headings** are the sizes below the title, largest first.
 */
final class TypeScale
{
    /**
     * How many paragraphs a slide may carry and still be a divider.
     *
     * A divider has a title and the logo in the corner and nothing else; a content
     * slide has a title and the text under it.
     */
    private const DIVIDER_PARAGRAPHS = 2;

    /**
     * How many entries the heading scale has.
     */
    private const HEADINGS = 5;

    /**
     * The numbers of a style a deck's own text answers for.
     *
     * The other four are the rhythm a deck draws with rather than the type it
     * sets, and a deck says nothing about them: they keep the library's own.
     */
    public const KEYS = ['body', 'title', 'cover', 'section', 'headings', 'leading', 'heading-weight', 'heading-ink'];

    /**
     * @param array{heading: ?string, body: ?string} $faces The two faces.
     * @param array{body: int, title: int, cover: int, section: int, headings: list<int>} $sizes The scale.
     * @param float                                     $leading The line spacing.
     * @param string                                    $weight  The heading's weight.
     * @param string                                    $ink     The ink a heading is drawn in.
     * @param list<array{what: string, value: string, where: string}> $notes Where each answer came from.
     * @param list<string>                              $defaults The numbers left at the library's default.
     */
    private function __construct(
        private readonly array $faces,
        private readonly array $sizes,
        private readonly float $leading,
        private readonly string $weight,
        private readonly string $ink,
        private readonly array $notes,
        private readonly array $defaults,
    ) {
    }
    /**
     * The type scale of a deck.
     */
    public static function of(Deck $deck, Palette $palette): self
    {
        $paragraphs = $deck->paragraphs();
        $slides = $deck->slideSizes();

        $title = self::titleSize($slides);
        $faces = self::facesOf($deck, $paragraphs, $title);
        $body = self::bodySize($paragraphs, $faces['body']);
        $cover = self::coverSize($slides, $title);
        $section = self::sectionSize($slides, $title);
        $headings = self::headings($paragraphs, $title, $body);
        $leading = self::leadingOf($paragraphs);
        $weight = self::weightOf($paragraphs, $faces['heading'], $title);
        $ink = self::inkOf($paragraphs, $title, $palette);

        $notes = [
            ['what' => 'heading', 'value' => $faces['heading'] ?? '?', 'where' => 'the face of the type at the title size'],
            ['what' => 'body', 'value' => $faces['body'] ?? '?', 'where' => 'the face the deck writes its prose in'],
            ['what' => 'title', 'value' => $title . 'pt', 'where' => 'the largest type on most of the slides'],
            ['what' => 'cover', 'value' => $cover . 'pt', 'where' => 'the largest type on the first slide'],
            ['what' => 'section', 'value' => $section . 'pt', 'where' => 'the title on a slide that carries little else'],
            ['what' => 'body size', 'value' => $body . 'pt', 'where' => 'the size the deck writes its prose at'],
            ['what' => 'headings', 'value' => implode(', ', $headings) . 'pt', 'where' => 'the sizes below the title'],
            ['what' => 'leading', 'value' => self::multiple($leading), 'where' => 'the line spacing the deck sets'],
            ['what' => 'heading weight', 'value' => $weight, 'where' => 'the weight of the headings'],
            ['what' => 'heading ink', 'value' => $ink, 'where' => 'the colour the headings are drawn in'],
        ];

        $defaults = [];

        // A deck with no type below the size of its titles has no heading scale
        // of its own, and an empty list is not one: the library's own scale
        // stands.
        if ($headings === []) {
            $defaults[] = 'headings';
        }

        return new self(
            $faces,
            [
                'body' => $body,
                'title' => $title,
                'cover' => $cover,
                'section' => $section,
                'headings' => $headings,
            ],
            $leading,
            $weight,
            $ink,
            $notes,
            $defaults,
        );
    }

    /**
     * The two faces: the one the headings are set in and the one the prose is.
     *
     * @return array{heading: ?string, body: ?string}
     */
    public function faces(): array
    {
        return $this->faces;
    }

    /**
     * The scale: the body, the title, the cover, the section and the headings.
     *
     * @return array{body: int, title: int, cover: int, section: int, headings: list<int>}
     */
    public function sizes(): array
    {
        return $this->sizes;
    }

    public function leading(): float
    {
        return $this->leading;
    }

    public function weight(): string
    {
        return $this->weight;
    }

    public function ink(): string
    {
        return $this->ink;
    }

    /**
     * Where each answer came from.
     *
     * @return list<array{what: string, value: string, where: string}>
     */
    public function notes(): array
    {
        return $this->notes;
    }

    /**
     * The numbers left at the library's default.
     *
     * @return list<string>
     */
    public function defaults(): array
    {
        return $this->defaults;
    }

    /**
     * The size the deck's slides are headed in.
     *
     * @param list<array{size: int, paragraphs: int}> $slides
     */
    private static function titleSize(array $slides): int
    {
        $counts = [];

        foreach ($slides as $slide) {
            if ($slide['size'] < 1) {
                continue;
            }

            $counts[$slide['size']] = ($counts[$slide['size']] ?? 0) + 1;
        }

        if ($counts === []) {
            return 28;
        }

        arsort($counts);

        return (int) array_key_first($counts);
    }

    /**
     * The largest type on the first slide.
     *
     * @param list<array{size: int, paragraphs: int}> $slides
     */
    private static function coverSize(array $slides, int $title): int
    {
        $first = $slides[0] ?? null;

        if ($first === null || $first['size'] < 1) {
            return $title;
        }

        return $first['size'];
    }

    /**
     * The size of a divider's title.
     *
     * A divider is a slide that carries a title and little else, so the size is
     * the one the deck's dividers are headed in. A deck with no divider has no
     * section of its own, and its section slides are headed like its other slides.
     *
     * @param list<array{size: int, paragraphs: int}> $slides
     */
    private static function sectionSize(array $slides, int $title): int
    {
        $counts = [];

        foreach ($slides as $slide) {
            if ($slide['size'] < 1 || $slide['paragraphs'] > self::DIVIDER_PARAGRAPHS) {
                continue;
            }

            $counts[$slide['size']] = ($counts[$slide['size']] ?? 0) + 1;
        }

        if ($counts === []) {
            return $title;
        }

        arsort($counts);

        return (int) array_key_first($counts);
    }

    /**
     * The two faces of the deck.
     *
     * @param list<Paragraph> $paragraphs
     * @return array{heading: ?string, body: ?string}
     */
    private static function facesOf(Deck $deck, array $paragraphs, int $title): array
    {
        if ($deck->usesTheme()) {
            $declared = $deck->fonts();

            if ($declared['heading'] !== null && $declared['body'] !== null) {
                return $declared;
            }
        }

        $characters = [];
        $headingCharacters = [];

        foreach ($paragraphs as $paragraph) {
            $face = $paragraph->face();

            if ($face === null) {
                continue;
            }

            $characters[$face] = ($characters[$face] ?? 0) + $paragraph->characters();

            if ($paragraph->size() === $title) {
                $headingCharacters[$face] = ($headingCharacters[$face] ?? 0) + $paragraph->characters();
            }
        }

        $body = self::heaviest($characters);
        $heading = self::heaviest($headingCharacters) ?? $body;

        return ['heading' => $heading, 'body' => $body];
    }

    /**
     * The face that carries the most type.
     *
     * @param array<string, int> $characters
     */
    private static function heaviest(array $characters): ?string
    {
        if ($characters === []) {
            return null;
        }

        arsort($characters);

        return (string) array_key_first($characters);
    }

    /**
     * The size the deck's prose is written at.
     *
     * @param list<Paragraph> $paragraphs
     */
    private static function bodySize(array $paragraphs, ?string $face): int
    {
        $counts = [];

        foreach ($paragraphs as $paragraph) {
            if ($paragraph->size() < 1) {
                continue;
            }

            if ($face !== null && $paragraph->face() !== null && $paragraph->face() !== $face) {
                continue;
            }

            $counts[$paragraph->size()] = ($counts[$paragraph->size()] ?? 0) + 1;
        }

        if ($counts === []) {
            return 16;
        }

        arsort($counts);

        return (int) array_key_first($counts);
    }

    /**
     * The sizes below the title, largest first.
     *
     * A heading is never smaller than the body text it introduces, so the scale
     * stops at the body size rather than going under it.
     *
     * @param list<Paragraph> $paragraphs
     * @return list<int>
     */
    private static function headings(array $paragraphs, int $title, int $body): array
    {
        $sizes = [];

        foreach ($paragraphs as $paragraph) {
            $size = $paragraph->size();

            if ($size < 1 || $size >= $title || $size < $body) {
                continue;
            }

            $sizes[$size] = true;
        }

        $scale = array_keys($sizes);

        rsort($scale);

        return array_slice($scale, 0, self::HEADINGS);
    }

    /**
     * A multiple of a line's own height, with its point.
     */
    private static function multiple(float $value): string
    {
        return (float) (int) $value === $value ? sprintf('%.1f', $value) : (string) $value;
    }

    /**
     * The line spacing the deck sets.
     *
     * @param list<Paragraph> $paragraphs
     */
    private static function leadingOf(array $paragraphs): float
    {
        $counts = [];

        foreach ($paragraphs as $paragraph) {
            $leading = $paragraph->leading();

            if ($leading === null) {
                continue;
            }

            $counts[$leading] = ($counts[$leading] ?? 0) + 1;
        }

        if ($counts === []) {
            return 1.2;
        }

        arsort($counts);

        $spacing = (int) array_key_first($counts);

        // The value is in thousandths of a percent, and the library's leading is a
        // multiple of a line's own height.
        return round($spacing / 100000, 2);
    }

    /**
     * The weight the headings are set in.
     *
     * @param list<Paragraph> $paragraphs
     */
    private static function weightOf(array $paragraphs, ?string $face, int $title): string
    {
        $bold = 0;
        $plain = 0;

        foreach ($paragraphs as $paragraph) {
            if ($paragraph->size() !== $title) {
                continue;
            }

            if ($face !== null && $paragraph->face() !== null && $paragraph->face() !== $face) {
                continue;
            }

            $paragraph->isBold() ? $bold++ : $plain++;
        }

        if ($bold === 0 && $plain === 0) {
            return StyleMapping::BOLD;
        }

        return $bold >= $plain ? StyleMapping::BOLD : StyleMapping::REGULAR;
    }

    /**
     * The colour the headings are drawn in.
     *
     * @param list<Paragraph> $paragraphs
     */
    private static function inkOf(array $paragraphs, int $title, Palette $palette): string
    {
        $counts = [];

        foreach ($paragraphs as $paragraph) {
            if ($paragraph->size() !== $title) {
                continue;
            }

            $colour = $paragraph->colour();

            if ($colour === null) {
                continue;
            }

            $counts[$colour] = ($counts[$colour] ?? 0) + $paragraph->characters();
        }

        if ($counts === []) {
            return StyleMapping::INK;
        }

        arsort($counts);
        $colour = (string) array_key_first($counts);
        $accent = $palette->colours()['accent1'] ?? null;

        return $accent !== null && strtoupper($accent) === $colour ? StyleMapping::ACCENT : StyleMapping::INK;
    }
}
