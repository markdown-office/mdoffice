<?php

declare(strict_types=1);

namespace MarkdownOffice\Console\Theme;

use MarkdownPresentation\Theme\Fonts;
use MarkdownPresentation\Theme\Style;
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
 *
 * What the deck shows and a theme cannot hold is said as well: a gradient paints
 * the page with more than one colour, and a theme has one colour for the page.
 */
final class Palette
{
    /**
     * How many accents a theme carries.
     */
    private const ACCENTS = 6;

    /**
     * @param array<string, string>                 $colours  The twelve slots, in DrawingML's order.
     * @param bool                                  $dark     Whether the page is dark.
     * @param list<array{slot: string, value: string, where: string}> $notes Where each answer came from.
     * @param list<string>                          $defaults The slots left at the library's default.
     * @param list<string>                          $omissions What the deck shows and a theme cannot hold.
     * @param string                                $pageFrom Where the page of the theme came from.
     */
    private function __construct(
        private readonly array $colours,
        private readonly bool $dark,
        private readonly array $notes,
        private readonly array $defaults,
        private readonly array $omissions,
        private readonly string $pageFrom,
    ) {
    }

    /**
     * The colours of a deck.
     */
    public static function of(Deck $deck): self
    {
        $background = $deck->background();

        if ($deck->usesTheme() && $deck->scheme() !== []) {
            return self::declared($deck, $background);
        }

        return self::shown($deck, $background);
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
     * Where the page of the theme came from.
     *
     * The master of a deck that is not drawn in its own theme, the slides of a deck
     * whose master names no colour, the theme part of a deck that is drawn in one,
     * or the library's own where the deck answers nothing: a page the deck does not
     * answer is not a page the theme can have.
     */
    public function pageFrom(): string
    {
        return $this->pageFrom;
    }

    /**
     * What the deck shows and a theme cannot hold.
     *
     * @return list<string>
     */
    public function omissions(): array
    {
        return $this->omissions;
    }

    /**
     * The slots left at the library's default, with the colour each of them keeps.
     *
     * The colour with the name, because "accent6 was not in the deck" is half an answer:
     * what a person edits is the colour, and the file it was written into already carries
     * it.
     *
     * @return array<string, string>
     */
    public function defaultColours(): array
    {
        $colours = [];

        foreach ($this->defaults as $slot) {
            $colours[$slot] = $this->colours[$slot] ?? '';
        }

        return $colours;
    }

    /**
     * The palette the deck's own theme part declares.
     *
     * @param array{colour: ?string, gradients: int, from: ?string} $background The page of the deck.
     */
    private static function declared(Deck $deck, array $background): self
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

            $colours[$slot] = Colour::of($colour)->hex();
        }

        // The slot the colour map paints the page with, and the colour of that page.
        // A map that names a slot the theme part does not have is a deck that does
        // not say what its page is, and the page is the library's own.
        $slot = $deck->colourMap()['bg1'] ?? Theme::LT1;
        $page = $colours[$slot] ?? $defaults[Theme::LT1];
        $dark = Colour::of($page)->isDark();

        // The slot the theme paints the page with, which is the one the roles name
        // and not necessarily the one the deck's map named.
        $themeSlot = $dark ? Theme::DK1 : Theme::LT1;

        // The page is only the deck's own if the deck's theme part declared the slot
        // the theme paints it with. Otherwise the colour here is the library's own,
        // and saying where it came from would be a lie.
        $pageFrom = "the library's own";

        if (isset($scheme[$themeSlot])) {
            $notes[] = [
                'slot' => $themeSlot,
                'value' => $colours[$themeSlot],
                'where' => "the deck's own theme part",
            ];
            $pageFrom = "the deck's own theme part";
        }

        return new self(
            $colours,
            $dark,
            $notes,
            $left,
            self::omissionsOf($background['gradients']),
            $pageFrom,
        );
    }

    /**
     * The palette the deck shows: the page from its master where the master names
     * one, the rest from its slides.
     *
     * @param array{colour: ?string, gradients: int, from: ?string} $background The page of the deck.
     */
    private static function shown(Deck $deck, array $background): self
    {
        $used = $deck->colours();
        $page = $background['colour'];
        $from = $background['from'];
        $dark = $page === null ? false : Colour::of($page)->isDark();

        $ink = self::firstShade($used, 'text', [$page]);
        $quiet = self::firstShade($used, 'text', [$page, $ink]);
        $panel = self::firstShade($used, 'fills', [$page, $ink, $quiet]);
        $accents = self::accents($used, [$page, $ink, $quiet]);

        $slots = self::roles($deck, $dark);
        $defaults = Theme::defaults()->colours();
        $colours = [];
        $notes = [];

        foreach (Theme::COLOURS as $slot) {
            $colours[$slot] = $defaults[$slot];
        }

        if ($page !== null && $from !== null) {
            self::place($colours, $notes, $slots['page'], $page, $from);
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

        $pageFrom = $from ?? "the library's own";

        return new self(
            $colours,
            $dark,
            $notes,
            self::left($notes),
            self::omissionsOf($background['gradients']),
            $pageFrom,
        );
    }

    /**
     * The slots the four roles play, as the library plays them.
     *
     * The library keeps the roles on a theme: a page that is dark is painted with
     * `dk1` and written on in `lt1`, and a page that is light the other way round.
     * A theme answers that from its own page and nothing else, so this asks one
     * rather than writes the table out again: a second copy of it would be a
     * second answer to the same question, and a theme whose page sits in the wrong
     * slot is a theme the library draws wrongly.
     *
     * @return array{page: string, ink: string, quiet: string, panel: string}
     */
    private static function roles(Deck $deck, bool $dark): array
    {
        $theme = new Theme($deck->name(), null, null, $dark, [], new Fonts([]), new Style());

        return [
            'page' => $theme->page(),
            'ink' => $theme->ink(),
            'quiet' => $theme->subtleInk(),
            'panel' => $theme->panel(),
        ];
    }

    /**
     * What the deck shows and a theme cannot hold.
     *
     * A gradient paints the page with more than one colour, and a theme has one
     * colour for the page: a gradient is therefore not one of the twelve slots. It
     * is said rather than read as the colour of one of the stops the gradient is
     * made of, because a stop is one end of the gradient and the page of a deck
     * painted with one is neither of them.
     *
     * @return list<string>
     */
    private static function omissionsOf(int $gradients): array
    {
        if ($gradients === 0) {
            return [];
        }

        return [sprintf(
            'a gradient is painted on %d of the slides, and a theme has one page colour',
            $gradients,
        )];
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
            if (Colour::of($colour)->isChromatic() || in_array($colour, $not, true)) {
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
            if (! Colour::of($colour)->isChromatic() || in_array($colour, $not, true)) {
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
        $value = Colour::of($colour)->hex();
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
     * The colours of the deck, ranked by how often it uses them.
     *
     * Only the colours the deck uses in the way being asked about: a colour the
     * deck fills a block with is a candidate for the panel, and one it only draws
     * type in is not — a panel the colour of the body text is a code block drawn in
     * the colour of the words in it.
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
}
