<?php

declare(strict_types=1);

namespace MarkdownOffice\Console\Slides\Diff;

/**
 * Where a shape is and how big it is.
 *
 * The numbers are the EMU the package holds, and they are compared in hundredths
 * of a millimetre rather than as they are. An EMU is a hundredth of a millimetre
 * divided by 360, so the raw numbers are far finer than anybody can see on a
 * slide, and two decks written by two tools differ in them for no reason a
 * reader could act on. A hundredth of a millimetre is the finest difference worth
 * reporting, and it is far finer than the pixel the library draws in.
 */
final class Geometry
{
    /**
     * The EMU in a millimetre.
     */
    private const EMU_PER_MILLIMETRE = 36000;

    public function __construct(
        public readonly ?int $x = null,
        public readonly ?int $y = null,
        public readonly ?int $cx = null,
        public readonly ?int $cy = null,
    ) {
    }

    /**
     * Whether two lengths are the same length.
     *
     * The hundredths are compared rather than the EMU, so that a difference
     * nobody could see is not a difference.
     */
    public static function same(int $left, int $right): bool
    {
        return self::hundredths($left) === self::hundredths($right);
    }

    /**
     * A length in hundredths of a millimetre.
     */
    private static function hundredths(int $emu): int
    {
        return (int) round($emu / (self::EMU_PER_MILLIMETRE / 100));
    }

    /**
     * A length as it is reported: in millimetres, from the top left of the slide.
     */
    public static function length(int $emu): string
    {
        $hundredths = self::hundredths($emu);
        $sign = $hundredths < 0 ? '-' : '';
        $value = abs($hundredths);

        return sprintf('%s%d.%02dmm', $sign, intdiv($value, 100), $value % 100);
    }
}
