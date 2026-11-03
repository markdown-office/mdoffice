<?php

declare(strict_types=1);

namespace MarkdownOffice\Console\Theme;

/**
 * A colour, and what can be said about one.
 *
 * A deck writes a colour in two spellings: with a hash in the theme file and without one
 * in the package, and the two are the same colour. The questions asked of a colour are
 * asked of its channels — whether it is a colour or a shade, whether the page it paints
 * is dark — and a colour that is not one has no channels to answer with.
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
     * the library reads back.
     */
    public function hex(): string
    {
        return '#' . $this->value;
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
     * A colour is written with a hash in the theme file and without one in the
     * package, so the hash is taken off before the channels are read: a colour
     * that keeps its hash is six characters long with five of them in it, and
     * five characters are not a colour.
     *
     * @return array{int, int, int}|null
     */
    private function channels(): ?array
    {
        $parts = str_split(substr(ltrim($this->value, '#'), 0, 6), 2);

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
