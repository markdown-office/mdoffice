<?php

declare(strict_types=1);

namespace MarkdownOffice\Console\Theme;

/**
 * One slide layout of a deck: the name it carries and the placeholders it holds.
 *
 * The placeholders are what the layout *is*, and the name is what it is called. The
 * two are kept apart here because they answer different questions and only one of
 * them can be relied on: a deck's layouts are named in whatever language and
 * whatever vocabulary its author had, and two decks in the same office call the
 * same layout "Titel und Inhalt" and "Title and Content". The placeholders are
 * the same in both.
 */
final class Layout
{
    /** The placeholder types that hold the layout's title. */
    public const TITLES = ['title', 'ctrTitle'];

    /** The placeholder type that holds a subtitle. */
    public const SUBTITLE = 'subTitle';

    /** The placeholder types that hold a column of content. */
    public const COLUMNS = ['body', 'obj', 'pic'];

    /**
     * The placeholder types that are furniture: a date, a footer and a slide
     * number, which are on every slide of a deck and say nothing about it.
     */
    public const FURNITURE = ['dt', 'ftr', 'sldNum'];

    /**
     * @param string       $name         The name the layout carries.
     * @param list<string> $placeholders The placeholder types, in the order the layout lists them.
     * @param int          $slides       The slides that use this layout.
     * @param int          $words        The words on the slides that use it.
     */
    public function __construct(
        private readonly string $name,
        private readonly array $placeholders,
        private readonly int $slides = 0,
        private readonly int $words = 0,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * @return list<string>
     */
    public function placeholders(): array
    {
        return $this->placeholders;
    }

    /**
     * Whether the layout holds a placeholder of this type.
     */
    public function has(string $type): bool
    {
        return in_array($type, $this->placeholders, true);
    }

    /**
     * Whether the layout holds a title.
     */
    public function hasTitle(): bool
    {
        foreach (self::TITLES as $type) {
            if ($this->has($type)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the layout holds a subtitle.
     */
    public function hasSubtitle(): bool
    {
        return $this->has(self::SUBTITLE);
    }

    /**
     * The content columns the layout holds, in the order it lists them.
     *
     * A subtitle is not one of them: it is the cover's second line rather than a
     * column of content, and a layout that holds one is a title slide rather than a
     * content slide.
     *
     * @return list<string>
     */
    public function columns(): array
    {
        return array_values(array_filter(
            $this->placeholders,
            static fn (string $type): bool => in_array($type, self::COLUMNS, true),
        ));
    }

    /**
     * Whether one of the layout's columns is a picture.
     */
    public function hasPicture(): bool
    {
        return $this->has('pic');
    }

    /**
     * How many placeholders the layout holds that are not furniture.
     *
     * A date, a footer and a slide number are on every layout of a deck and say
     * nothing about what the layout is for, so they are not counted: a layout
     * that holds nothing else holds nothing at all.
     */
    public function contents(): int
    {
        return count(array_filter(
            $this->placeholders,
            static fn (string $type): bool => ! in_array($type, self::FURNITURE, true),
        ));
    }

    /**
     * How many slides use this layout.
     */
    public function slides(): int
    {
        return $this->slides;
    }

    /**
     * How many words the slides using this layout carry.
     */
    public function words(): int
    {
        return $this->words;
    }

    /**
     * The words a slide of this layout carries, on average.
     *
     * Zero when no slide uses the layout: a layout the deck does not use says
     * nothing about what it is for, and dividing by nothing would say it does.
     */
    public function wordsPerSlide(): float
    {
        return $this->slides === 0 ? 0.0 : $this->words / $this->slides;
    }
}
