<?php

declare(strict_types=1);

namespace MarkdownOffice\Console\Slides\Diff;

/**
 * What changed between two decks.
 *
 * The slides are paired before anything is compared, because a difference is a
 * difference between two slides and not between two positions in two files. A
 * slide is paired with the one carrying the same id, because that id survives an
 * edit and a reorder of the deck and a position does not: a slide inserted at
 * the front moves every slide after it, and pairing by position would report the
 * whole deck as changed.
 *
 * What is compared is the text, the position and the size of the shapes, and the
 * colour of the fills and the runs. What is not is said as well, in
 * {@see self::notCompared()}: a diff that silently skips what it could not
 * compare leaves a reader worse off than one that says what it looked at.
 */
final class Comparison
{
    /**
     * @param list<string> $lines  The differences, one sentence each.
     * @param int          $paired How many slides of the two decks were paired.
     */
    private function __construct(
        private readonly array $lines,
        private readonly int $paired,
    ) {
    }

    /**
     * Compare two decks.
     */
    public static function of(Deck $before, Deck $after): self
    {
        $lines = [];

        if ($before->width() !== $after->width() || $before->height() !== $after->height()) {
            $lines[] = sprintf(
                'the slide size changed from %s to %s',
                self::page($before->width(), $before->height()),
                self::page($after->width(), $after->height()),
            );
        }

        $paired = 0;

        foreach (self::pair($before->slides(), $after->slides()) as [$first, $second]) {
            if ($first === null && $second === null) {
                continue;
            }

            if ($first === null) {
                $lines[] = sprintf('slide %d of the second deck was added', $second->number);

                continue;
            }

            if ($second === null) {
                $lines[] = sprintf('slide %d was removed', $first->number);

                continue;
            }

            $paired++;

            if ($first->number !== $second->number) {
                $lines[] = sprintf('slide %d moved to slide %d', $first->number, $second->number);
            }

            foreach (self::shapes($first, $second) as $line) {
                $lines[] = $line;
            }
        }

        return new self($lines, $paired);
    }

    /**
     * The differences, one sentence each.
     *
     * @return list<string>
     */
    public function lines(): array
    {
        return $this->lines;
    }

    /**
     * Whether the two decks differ.
     */
    public function hasDifferences(): bool
    {
        return $this->lines !== [];
    }

    /**
     * How many slides of the two decks were paired.
     */
    public function paired(): int
    {
        return $this->paired;
    }

    /**
     * The slides of the two decks, one against the other.
     *
     * A slide is paired with the one carrying the same id. The slides left over
     * are paired by their place among the slides left over, and whatever is still
     * left over was added or removed.
     *
     * @param list<Slide> $before
     * @param list<Slide> $after
     * @return list<array{0: ?Slide, 1: ?Slide}>
     */
    private static function pair(array $before, array $after): array
    {
        $byId = [];

        foreach ($after as $slide) {
            if ($slide->id !== '' && !isset($byId[$slide->id])) {
                $byId[$slide->id] = $slide;
            }
        }

        $pairs = [];
        $taken = [];
        $left = [];

        foreach ($before as $slide) {
            $other = $slide->id === '' ? null : ($byId[$slide->id] ?? null);

            if ($other !== null && !isset($taken[$other->number])) {
                $taken[$other->number] = true;
                $pairs[] = [$slide, $other];

                continue;
            }

            $left[] = $slide;
        }

        $rest = [];

        foreach ($after as $slide) {
            if (!isset($taken[$slide->number])) {
                $rest[] = $slide;
            }
        }

        $shared = min(count($left), count($rest));

        for ($index = 0; $index < $shared; $index++) {
            $pairs[] = [$left[$index], $rest[$index]];
        }

        for ($index = $shared; $index < count($left); $index++) {
            $pairs[] = [$left[$index], null];
        }

        for ($index = $shared; $index < count($rest); $index++) {
            $pairs[] = [null, $rest[$index]];
        }

        usort(
            $pairs,
            static fn (array $left, array $right): int => self::order($left) <=> self::order($right),
        );

        return $pairs;
    }

    /**
     * Where a pair of slides belongs in the report.
     *
     * @param array{0: ?Slide, 1: ?Slide} $pair
     */
    private static function order(array $pair): int
    {
        if ($pair[0] !== null) {
            return $pair[0]->number;
        }

        if ($pair[1] !== null) {
            return $pair[1]->number;
        }

        return 0;
    }

    /**
     * The differences between two slides.
     *
     * @return list<string>
     */
    private static function shapes(Slide $before, Slide $after): array
    {
        $lines = [];
        $number = $before->number;

        foreach (self::pairShapes($before->shapes, $after->shapes) as [$first, $second]) {
            if ($first === null && $second === null) {
                continue;
            }

            if ($first === null) {
                $lines[] = sprintf('slide %d: %s was added', $number, self::named($second));

                continue;
            }

            if ($second === null) {
                $lines[] = sprintf('slide %d: %s was removed', $number, self::named($first));

                continue;
            }

            foreach (self::text($first, $second, $number) as $line) {
                $lines[] = $line;
            }

            foreach (self::geometry($first, $second, $number) as $line) {
                $lines[] = $line;
            }

            foreach (self::colours($first, $second, $number) as $line) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * The shapes of two slides, one against the other.
     *
     * A shape is paired with the one carrying the same id, and the ones left
     * over with the one of the same name, and the ones left over after that by
     * their place in the slide. The id is what a deck keeps when a shape is
     * edited, the name is what a person gives it, and the place is the last
     * resort, for a deck whose ids were all made anew.
     *
     * @param list<Shape> $before
     * @param list<Shape> $after
     * @return list<array{0: ?Shape, 1: ?Shape}>
     */
    private static function pairShapes(array $before, array $after): array
    {
        $byId = self::match(
            $before,
            $after,
            static fn (Shape $shape): string => $shape->id === 0 ? '' : 'id ' . $shape->id,
        );
        $byName = self::match($byId['before'], $byId['after'], static fn (Shape $shape): string => $shape->name);
        $pairs = [...$byId['pairs'], ...$byName['pairs']];

        $shared = min(count($byName['before']), count($byName['after']));

        for ($index = 0; $index < $shared; $index++) {
            $pairs[] = [$byName['before'][$index], $byName['after'][$index]];
        }

        for ($index = $shared; $index < count($byName['before']); $index++) {
            $pairs[] = [$byName['before'][$index], null];
        }

        for ($index = $shared; $index < count($byName['after']); $index++) {
            $pairs[] = [null, $byName['after'][$index]];
        }

        usort(
            $pairs,
            static fn (array $left, array $right): int => self::shapeOrder($left) <=> self::shapeOrder($right),
        );

        return $pairs;
    }

    /**
     * Pair the shapes of two slides that carry the same key.
     *
     * @param list<Shape>        $before
     * @param list<Shape>        $after
     * @param callable(Shape): string $key
     * @return array{pairs: list<array{0: Shape, 1: Shape}>, before: list<Shape>, after: list<Shape>}
     */
    private static function match(array $before, array $after, callable $key): array
    {
        $byKey = [];

        foreach ($after as $index => $shape) {
            $name = $key($shape);

            if ($name !== '' && !isset($byKey[$name])) {
                $byKey[$name] = $index;
            }
        }

        $pairs = [];
        $taken = [];
        $left = [];

        foreach ($before as $shape) {
            $name = $key($shape);
            $index = $name === '' ? null : ($byKey[$name] ?? null);

            if ($index !== null && !isset($taken[$index])) {
                $taken[$index] = true;
                $pairs[] = [$shape, $after[$index]];

                continue;
            }

            $left[] = $shape;
        }

        $rest = [];

        foreach ($after as $index => $shape) {
            if (!isset($taken[$index])) {
                $rest[] = $shape;
            }
        }

        return ['pairs' => $pairs, 'before' => $left, 'after' => $rest];
    }

    /**
     * Where a pair of shapes belongs in the report.
     *
     * @param array{0: ?Shape, 1: ?Shape} $pair
     */
    private static function shapeOrder(array $pair): int
    {
        if ($pair[0] !== null) {
            return $pair[0]->id;
        }

        if ($pair[1] !== null) {
            return $pair[1]->id;
        }

        return 0;
    }

    /**
     * The text that is in one shape and not in the other.
     *
     * @return list<string>
     */
    private static function text(Shape $before, Shape $after, int $number): array
    {
        $lines = [];

        foreach (self::missing($before->paragraphs, $after->paragraphs) as $text) {
            $lines[] = sprintf('slide %d: the text "%s" was removed', $number, $text);
        }

        foreach (self::missing($after->paragraphs, $before->paragraphs) as $text) {
            $lines[] = sprintf('slide %d: the text "%s" was added', $number, $text);
        }

        return $lines;
    }

    /**
     * The entries of one list that are not in the other.
     *
     * @param list<string> $left
     * @param list<string> $right
     * @return list<string>
     */
    private static function missing(array $left, array $right): array
    {
        $counts = array_count_values($right);
        $missing = [];

        foreach ($left as $entry) {
            if (($counts[$entry] ?? 0) > 0) {
                $counts[$entry]--;

                continue;
            }

            $missing[] = $entry;
        }

        return $missing;
    }

    /**
     * Where a shape is and how big it is, when both say so.
     *
     * @return list<string>
     */
    private static function geometry(Shape $before, Shape $after, int $number): array
    {
        $lines = [];
        $from = $before->geometry;
        $to = $after->geometry;

        if (
            $from->x !== null && $from->y !== null && $to->x !== null && $to->y !== null
            && (!Geometry::same($from->x, $to->x) || !Geometry::same($from->y, $to->y))
        ) {
            $lines[] = sprintf(
                'slide %d: %s moved from %s, %s to %s, %s',
                $number,
                self::named($after),
                Geometry::length($from->x),
                Geometry::length($from->y),
                Geometry::length($to->x),
                Geometry::length($to->y),
            );
        }

        if (
            $from->cx !== null && $from->cy !== null && $to->cx !== null && $to->cy !== null
            && (!Geometry::same($from->cx, $to->cx) || !Geometry::same($from->cy, $to->cy))
        ) {
            $lines[] = sprintf(
                'slide %d: %s changed size from %s, %s to %s, %s',
                $number,
                self::named($after),
                Geometry::length($from->cx),
                Geometry::length($from->cy),
                Geometry::length($to->cx),
                Geometry::length($to->cy),
            );
        }

        return $lines;
    }

    /**
     * The colours that are in one shape and not in the other.
     *
     * @return list<string>
     */
    private static function colours(Shape $before, Shape $after, int $number): array
    {
        $lines = [];

        if ($before->fill !== null && $after->fill !== null && $before->fill !== $after->fill) {
            $lines[] = sprintf(
                'slide %d: %s changed its fill colour from %s to %s',
                $number,
                self::named($after),
                $before->fill,
                $after->fill,
            );
        }

        $count = min(count($before->runs), count($after->runs));

        for ($index = 0; $index < $count; $index++) {
            $one = $before->runs[$index];
            $two = $after->runs[$index];

            // A run whose text is not the same is not the same run, and the text
            // is reported as the difference it is.
            if ($one['text'] !== $two['text']) {
                continue;
            }

            if ($one['colour'] === null || $two['colour'] === null || $one['colour'] === $two['colour']) {
                continue;
            }

            $lines[] = sprintf(
                'slide %d: %s changed colour from %s to %s',
                $number,
                $two['text'] === '' ? self::named($after) : sprintf('the text "%s"', $two['text']),
                $one['colour'],
                $two['colour'],
            );
        }

        return $lines;
    }

    /**
     * A shape, as the report names it.
     */
    private static function named(Shape $shape): string
    {
        return $shape->name === '' ? 'the shape' : sprintf('the shape "%s"', $shape->name);
    }

    /**
     * The size of a slide, as the report names it.
     */
    private static function page(int $width, int $height): string
    {
        return sprintf('%s × %s', Geometry::length($width), Geometry::length($height));
    }
}
