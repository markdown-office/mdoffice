<?php

declare(strict_types=1);

namespace MarkdownOffice\Console\Theme;

use MarkdownPresentation\Render\Free\Colour as Written;
use MarkdownPresentation\Theme\Fonts;
use MarkdownPresentation\Theme\Style;
use MarkdownPresentation\Theme\Theme;
use PhpOffice\PhpPresentation\Style\Color;

/**
 * A colour, and what can be said about one.
 *
 * The library reads the colours a deck writes — a role, a slot or a hex value —
 * and hands back the colour itself, so this asks it rather than reads hex a
 * second time. What the library does not answer is what can be said about a
 * colour: whether it is a colour or a shade, and whether the page it paints is
 * dark. Those two questions are here, and they are why this class exists.
 *
 * The colours of a package are already resolved — a package holds six hex
 * digits and nothing else — so the theme the library resolves them against has
 * no colours of its own: a name is not a colour a package holds, and resolving
 * one against a theme would answer with a colour the deck never had.
 *
 * A deck writes a colour in two spellings: with a hash in the theme file and
 * without one in the package, and the two are the same colour.
 */
final class Colour
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
     * @param string $value The colour as the deck wrote it.
     */
    private function __construct(private readonly string $value)
    {
    }

    /**
     * A colour, as the deck wrote it.
     */
    public static function of(string $colour): self
    {
        return new self($colour);
    }

    /**
     * The colour as the theme file writes it.
     *
     * A hash and six hex digits: that is what DrawingML calls a colour, and what
     * the library reads back. The library writes them in upper case, and a theme
     * file that holds a colour in lower case beside one in upper case is two
     * spellings of one colour.
     */
    public function hex(): string
    {
        $colour = $this->resolved();

        if ($colour === null) {
            return '#' . ltrim($this->value, '#');
        }

        return '#' . $colour->getRGB();
    }

    /**
     * Whether the colour is a colour rather than a shade.
     */
    public function isChromatic(): bool
    {
        $channels = $this->channels();

        if ($channels === null) {
            return false;
        }

        return max($channels) - min($channels) >= self::CHROMA;
    }

    /**
     * Whether the colour is dark.
     */
    public function isDark(): bool
    {
        $channels = $this->channels();

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
     * The three channels of the colour, or null when it is not one.
     *
     * @return array{int, int, int}|null
     */
    private function channels(): ?array
    {
        $colour = $this->resolved();

        if ($colour === null) {
            return null;
        }

        $rgb = $colour->getRGB();

        return [
            (int) hexdec(substr($rgb, 0, 2)),
            (int) hexdec(substr($rgb, 2, 2)),
            (int) hexdec(substr($rgb, 4, 2)),
        ];
    }

    /**
     * The colour the library reads out of what the deck wrote, or null where what
     * the deck wrote is not a colour.
     */
    private function resolved(): ?Color
    {
        return Written::resolve($this->value, self::theme());
    }

    /**
     * A theme with no colours of its own.
     *
     * The theme a colour is resolved against is the one it is drawn with, and the
     * colours of a package are not drawn with anything: they are the colours
     * themselves. A theme with no colours says so, and a name resolves to nothing.
     */
    private static function theme(): Theme
    {
        return new Theme('colours', null, null, false, [], new Fonts([]), new Style());
    }
}
