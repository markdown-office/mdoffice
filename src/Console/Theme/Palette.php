<?php

declare(strict_types=1);

namespace MarkdownOffice\Console\Theme;

use MarkdownPresentation\Theme\Theme;

/**
 * The colours of a theme, read out of a deck.
 *
 * A deck has two answers to the question "what colours are you in?", and they do
 * not always agree. The theme part holds a palette of twelve slots; the slides
 * hold the colours that are actually on the page. A deck made in PowerPoint on a
 * theme of its own uses its theme, and the two answers are the same answer. A
 * deck made in a tool that does not do themes — LibreOffice, Keynote, a generator
 * — carries Office's stock theme part it never touched, and the palette in it
 * describes nothing that is on the page.
 *
 * So the deck is asked which of the two it uses: a deck whose shapes name the
 * theme's slots is drawn in that theme, and its palette is the theme's. A deck
 * that names literal colours everywhere is read for what it shows, and every
 * answer is reported with where it was read from.
 *
 * The slots are the library's own, from {@see Theme::COLOURS}, and the two that
 * play a part rather than a colour — the page and the ink — are told apart by the
 * page's own lightness rather than by their names, which is what the library's
 * `dark:` flag says in turn.
 */
final class Palette
{
    /**
     * How far apart a colour's channels have to be for the colour to be a colour
     * rather than a shade.
     *
     * A quarter of the range: a navy is a colour, a grey is not, and the test is
     * on the channels rather than on the hue because a near-white is nearly
     * hueless and a saturated navy is not.
     */
    private const CHROMA = 64;

    /**
     * How many accents a theme carries.
     */
    private const ACCENTS = 6;

    /**
     * @param array<string, string>                 $colours  The twelve slots, in DrawingML's order.
     * @param bool                                  $dark     Whether the page is dark.
     * @param list<array{slot: string, value: string, where: string}> $notes Where each answer came from.
     * @param list<string>                          $defaults The slots left at the library's default.
     */
    private function __construct(
        private readonly array $colours,
        private readonly bool $dark,
        private readonly array $notes,
        private readonly array $defaults,
    ) {
    }

    /**
     * The colours of a deck.
     */
    public static function of(Deck $deck): self
    {
        if ($deck->usesTheme() && $deck->scheme() !== []) {
            return self::declared($deck);
        }

        return self::shown($deck);
    }

    /**
     * Every colour, keyed by its slot.
     *
     * @return array<string, string>
     */
    public function colours(): array
    {
        return $this->colours;
    }

    /**
     * Whether the page the theme paints is dark.
     */
    public function isDark(): bool
    {
        return $this->dark;
    }

    /**
     * Where each answer came from, one entry per slot that was read.
     *
     * @return list<array{slot: string, value: string, where: string}>
     */
    public function notes(): array
    {
        return $this->notes;
    }

    /**
     * The slots the deck did not answer, which keep the library's own default.
     *
     * @return list<string>
     */
    public function defaults(): array
    {
        return $this->defaults;
    }

    /**
     * The palette the deck's own theme part declares.
     */
    private static function declared(Deck $deck): self
    {
        $scheme = $deck->scheme();
        $defaults = Theme::defaults()->colours();
        $colours = [];
        $notes = [];
        $left = [];

        foreach (Theme::COLOURS as $slot) {
            $colour = $scheme[$slot] ?? null;

            if ($colour === null) {
                $colours[$slot] = $defaults[$slot];
                $left[] = $slot;

                continue;
            }

            $colours[$slot] = self::hex($colour);
        }

        $slot = self::pageSlot($deck);
        $page = $colours[$slot];
        $dark = self::dark($page);

        // The page is only the deck's own if the deck's theme part declared the
        // slot the colour map paints it with. Otherwise the colour here is the
        // library's own, and saying where it came from would be a lie.
        if (isset($scheme[$slot])) {
            $notes[] = [
                'slot' => $slot,
                'value' => $page,
                'where' => "the deck's own theme part",
            ];
        }

        return new self($colours, $dark, $notes, $left);
    }

    /**
     * The palette the deck shows, read from its slides.
     */
    private static function shown(Deck $deck): self
    {
        $used = $deck->colours();
        $page = self::pageColour($deck);
        $dark = $page === null ? false : self::dark($page);

        $ink = self::firstShade($used, 'text', [$page]);
        $quiet = self::firstShade($used, 'text', [$page, $ink]);
        $panel = self::firstShade($used, 'fills', [$page, $ink, $quiet]);
        $accents = self::accents($used, [$page, $ink, $quiet]);

        $slots = self::slots($dark);
        $defaults = Theme::defaults()->colours();
        $colours = [];
        $notes = [];

        foreach (Theme::COLOURS as $slot) {
            $colours[$slot] = $defaults[$slot];
        }

        if ($page !== null) {
            self::place($colours, $notes, $slots['page'], $page, 'the background of the slides');
        }

        if ($ink !== null) {
            self::place($colours, $notes, $slots['ink'], $ink, 'the type');
        }

        if ($quiet !== null) {
            self::place($colours, $notes, $slots['quiet'], $quiet, 'the quieter type');
        }

        if ($panel !== null) {
            self::place($colours, $notes, $slots['panel'], $panel, 'a filled block');
        }

        foreach ($accents as $position => $colour) {
            self::place($colours, $notes, 'accent' . ($position + 1), $colour, 'the colour the deck draws with');
        }

        // A link is the accent in every deck that has one, and a link that has been
        // followed is the quiet type: the two are the deck's own colours, and the
        // theme part's are the ones the deck never used.
        if ($accents !== []) {
            self::place($colours, $notes, Theme::HLINK, $accents[0], 'the accent, as a link is drawn');
        }

        if ($quiet !== null) {
            self::place($colours, $notes, Theme::FOL_HLINK, $quiet, 'the quieter ink');
        }

        return new self($colours, $dark, $notes, self::left($notes));
    }

    /**
     * The first shade of a rank that the deck draws with, and that is not one of
     * the colours already spoken for.
     *
     * A shade is a colour that is not a colour: the greys and the blacks the type
     * and the panels are drawn in, as opposed to the accents, which are colours.
     *
     * @param list<array{colour: string, fills: int, outlines: int, text: int}> $used
     * @param list<string|null> $not The colours that are spoken for already; null is none of them.
     */
    private static function firstShade(array $used, string $by, array $not): ?string
    {
        foreach (self::ranked($used, $by) as $colour) {
            if (self::isChromatic($colour) || in_array($colour, $not, true)) {
                continue;
            }

            return $colour;
        }

        return null;
    }

    /**
     * The colours the deck draws with, at most as many as a theme carries.
     *
     * @param list<array{colour: string, fills: int, outlines: int, text: int}> $used
     * @param list<string|null> $not The colours that are spoken for already; null is none of them.
     * @return list<string>
     */
    private static function accents(array $used, array $not): array
    {
        $accents = [];

        foreach (self::ranked($used, 'uses') as $colour) {
            if (! self::isChromatic($colour) || in_array($colour, $not, true)) {
                continue;
            }

            $accents[] = $colour;

            if (count($accents) === self::ACCENTS) {
                break;
            }
        }

        return $accents;
    }

    /**
     * The slots the colours of a deck take, on a dark page and on a light one.
     *
     * The page takes the slot that is its own kind, and the ink and the quieter
     * ink take the other: a page that is dark is written on in the light one.
     *
     * @return array{page: string, ink: string, quiet: string, panel: string}
     */
    private static function slots(bool $dark): array
    {
        return $dark
            ? ['page' => 'dk1', 'ink' => 'lt1', 'quiet' => 'lt2', 'panel' => 'dk2']
            : ['page' => 'lt1', 'ink' => 'dk1', 'quiet' => 'dk2', 'panel' => 'lt2'];
    }

    /**
     * A colour the deck shows, in the slot it takes and with a note of where it
     * was read.
     *
     * @param array<string, string> $colours
     * @param list<array{slot: string, value: string, where: string}> $notes
     */
    private static function place(
        array &$colours,
        array &$notes,
        string $slot,
        string $colour,
        string $where,
    ): void {
        $value = self::hex($colour);
        $colours[$slot] = $value;
        $notes[] = ['slot' => $slot, 'value' => $value, 'where' => $where];
    }

    /**
     * The slots the deck did not answer, which keep the library's own default.
     *
     * @param list<array{slot: string, value: string, where: string}> $notes
     * @return list<string>
     */
    private static function left(array $notes): array
    {
        $left = [];

        foreach (Theme::COLOURS as $slot) {
            if (! in_array($slot, array_column($notes, 'slot'), true)) {
                $left[] = $slot;
            }
        }

        return $left;
    }

    /**
     * The background colour the deck's slides set, or null when none of them does.
     */
    private static function pageColour(Deck $deck): ?string
    {
        $pages = $deck->pages();

        if ($pages === []) {
            return null;
        }

        return $pages[0]['colour'];
    }

    /**
     * The slot the deck's colour map paints the page with.
     */
    private static function pageSlot(Deck $deck): string
    {
        return $deck->colourMap()['bg1'] ?? 'lt1';
    }

    /**
     * The colours of the deck, ranked by how often it uses them.
     *
     * Only the colours the deck uses in the way being asked about: a colour the
     * deck fills a block with is a candidate for the panel, and one it only
     * draws type in is not. A panel the colour of the body text is a code block
     * drawn in the colour of the words in it.
     *
     * @param list<array{colour: string, fills: int, outlines: int, text: int}> $used
     * @return list<string>
     */
    private static function ranked(array $used, string $by): array
    {
        $counts = [];

        foreach ($used as $use) {
            $count = match ($by) {
                'uses' => $use['fills'] + $use['outlines'] + $use['text'],
                'draws' => $use['fills'] + $use['outlines'],
                default => $use[$by],
            };

            if ($count < 1) {
                continue;
            }

            $counts[$use['colour']] = $count;
        }

        arsort($counts);

        return array_map(strval(...), array_keys($counts));
    }

    /**
     * A colour as the theme file writes it.
     *
     * A hash and six hex digits: that is what DrawingML calls a colour, and what
     * the library reads back.
     */
    private static function hex(string $colour): string
    {
        return '#' . $colour;
    }

    /**
     * Whether a colour is a colour rather than a shade.
     */
    private static function isChromatic(string $colour): bool
    {
        $channels = self::channels($colour);

        if ($channels === null) {
            return false;
        }

        return max($channels) - min($channels) >= self::CHROMA;
    }

    /**
     * Whether a colour is dark.
     */
    private static function dark(string $colour): bool
    {
        $channels = self::channels($colour);

        if ($channels === null) {
            return false;
        }

        $linear = array_map(
            static fn (float $channel): float => $channel <= 0.03928
                ? $channel / 12.92
                : (($channel + 0.055) / 1.055) ** 2.4,
            array_map(static fn (int $channel): float => $channel / 255, $channels),
        );

        $luminance = 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];

        return $luminance < 0.5;
    }

    /**
     * The three channels of a colour, or null when it is not one.
     *
     * A colour is written with a hash in the theme file and without one in the
     * package, so the hash is taken off before the channels are read: a colour
     * that keeps its hash is six characters long with five of them in it, and
     * five characters are not a colour.
     *
     * @return array{int, int, int}|null
     */
    private static function channels(string $colour): ?array
    {
        $parts = str_split(substr(ltrim($colour, '#'), 0, 6), 2);

        if (count($parts) !== 3) {
            return null;
        }

        $channels = [];

        foreach ($parts as $part) {
            if (preg_match('/\A[0-9A-Fa-f]{2}\z/', $part) !== 1) {
                return null;
            }

            $channels[] = (int) hexdec($part);
        }

        return [$channels[0], $channels[1], $channels[2]];
    }
}
