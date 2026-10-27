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

        $page = $colours[self::pageSlot($deck)];
        $dark = self::dark($page);

        $notes[] = [
            'slot' => $dark ? 'dk1' : 'lt1',
            'value' => self::hex($page),
            'where' => "the deck's own theme part",
        ];

        return new self($colours, $dark, $notes, $left);
    }

    /**
     * The palette the deck shows, read from its slides.
     */
    private static function shown(Deck $deck): self
    {
        $used = $deck->colours();
        $defaults = Theme::defaults()->colours();
        $notes = [];
        $left = [];

        $page = self::pageColour($deck);
        $dark = $page === null ? false : self::dark($page);

        $text = self::ranked($used, 'text');
        $fills = self::ranked($used, 'fills');

        $ink = self::first($text, static fn (string $colour): bool => ! self::isChromatic($colour) && $colour !== $page);
        $quiet = self::first($text, static fn (string $colour): bool => ! self::isChromatic($colour) && $colour !== $page && $colour !== $ink);
        $panel = self::first($fills, static fn (string $colour): bool => ! self::isChromatic($colour) && $colour !== $page);

        $accents = [];

        foreach (self::ranked($used, 'uses') as $colour) {
            if (! self::isChromatic($colour) || $colour === $page || $colour === $ink || $colour === $quiet) {
                continue;
            }

            $accents[] = $colour;

            if (count($accents) === 6) {
                break;
            }
        }

        $colours = [];

        foreach (Theme::COLOURS as $slot) {
            $colours[$slot] = $defaults[$slot];
        }

        if ($page !== null) {
            $colours[$dark ? 'dk1' : 'lt1'] = self::hex($page);
            $notes[] = ['slot' => $dark ? 'dk1' : 'lt1', 'value' => self::hex($page), 'where' => 'the background of the slides'];
        }

        if ($ink !== null) {
            $colours[$dark ? 'lt1' : 'dk1'] = self::hex($ink);
            $notes[] = ['slot' => $dark ? 'lt1' : 'dk1', 'value' => self::hex($ink), 'where' => 'the type'];
        }

        if ($quiet !== null) {
            $colours[$dark ? 'lt2' : 'dk2'] = self::hex($quiet);
            $notes[] = ['slot' => $dark ? 'lt2' : 'dk2', 'value' => self::hex($quiet), 'where' => 'the quieter type'];
        }

        if ($panel !== null) {
            $colours[$dark ? 'dk2' : 'lt2'] = self::hex($panel);
            $notes[] = ['slot' => $dark ? 'dk2' : 'lt2', 'value' => self::hex($panel), 'where' => 'a filled block'];
        }

        foreach ($accents as $position => $colour) {
            $slot = 'accent' . ($position + 1);
            $colours[$slot] = self::hex($colour);
            $notes[] = ['slot' => $slot, 'value' => self::hex($colour), 'where' => 'the colour the deck draws with'];
        }

        // A link is the accent in every deck that has one, and a link that has been
        // followed is the quiet type: the two are the deck's own colours, and the
        // theme part's are the ones the deck never used.
        if ($accents !== []) {
            $colours[Theme::HLINK] = self::hex($accents[0]);
            $notes[] = ['slot' => Theme::HLINK, 'value' => self::hex($accents[0]), 'where' => 'the accent, as a link is drawn'];
        }

        if ($quiet !== null) {
            $colours[Theme::FOL_HLINK] = self::hex($quiet);
            $notes[] = ['slot' => Theme::FOL_HLINK, 'value' => self::hex($quiet), 'where' => 'the quieter ink'];
        }

        foreach (Theme::COLOURS as $slot) {
            if (! in_array($slot, array_column($notes, 'slot'), true)) {
                $left[] = $slot;
            }
        }

        return new self($colours, $dark, $notes, $left);
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
     * @param list<array{colour: string, fills: int, outlines: int, text: int}> $used
     * @return list<string>
     */
    private static function ranked(array $used, string $by): array
    {
        $counts = [];

        foreach ($used as $use) {
            $counts[$use['colour']] = match ($by) {
                'uses' => $use['fills'] + $use['outlines'] + $use['text'],
                'draws' => $use['fills'] + $use['outlines'],
                default => $use[$by],
            };
        }

        arsort($counts);

        return array_map(strval(...), array_keys($counts));
    }

    /**
     * The first colour that satisfies this test.
     *
     * @param list<string> $colours
     */
    private static function first(array $colours, callable $test): ?string
    {
        foreach ($colours as $colour) {
            if ($test($colour)) {
                return $colour;
            }
        }

        return null;
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
     * @return array{int, int, int}|null
     */
    private static function channels(string $colour): ?array
    {
        $parts = str_split(ltrim(substr($colour, 0, 6), '#'), 2);

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
