<?php

declare(strict_types=1);

namespace MarkdownOffice\Console\Theme;

use MarkdownPresentation\Theme\LayoutRole;

/**
 * The layouts of a deck, mapped onto the eight layout roles.
 *
 * A deck's layouts are named in whatever vocabulary its author had, and two decks
 * in the same office call the same layout "Titel und Inhalt" and "Title and
 * Content". The names are therefore a hint and never the rule: what a layout
 * *is* is the placeholders it holds. A layout with a title and one column of
 * content is a content slide, whatever it is called, and a layout with nothing in
 * it at all is a blank one.
 *
 * The eight roles are a closed set and a deck has whatever layouts it has, so
 * there are two ways to be wrong: pointing a role at a layout that is something
 * else, and leaving a role unmapped without saying so. The first is avoided by
 * mapping on structure alone; the second is reported, because a role nobody
 * mapped is a decision somebody has to make.
 *
 * One role cannot be told from the structure alone: a section header and a
 * content slide hold the same placeholders, and only what the deck does with
 * them tells them apart. So the section is the content layout the deck's own name
 * gives it, or the one whose slides carry the least text, and where neither
 * answers, the role is reported as unmapped rather than pointed at a layout that
 * may be something else.
 */
final class Roles
{
    /**
     * The words in a layout's name that mean it is a section.
     *
     * The library's own vocabulary, which is Office's: a layout called
     * "Section Header" is one. A deck in another language calls it something
     * else, and the role is reported as unmapped rather than guessed at.
     */
    private const SECTION = 'section';

    /**
     * @param array<string, string> $mapped  The role to the name of the layout it is.
     * @param list<string>          $unmapped The roles the deck has no layout for.
     * @param list<string>          $unused   The layouts no role took.
     * @param list<array{role: string, name: string, why: string}> $notes Why each role took the layout it took.
     */
    private function __construct(
        private readonly array $mapped,
        private readonly array $unmapped,
        private readonly array $unused,
        private readonly array $notes,
    ) {
    }

    /**
     * The roles of a deck's layouts.
     *
     * @param list<Layout> $layouts
     */
    public static function of(array $layouts): self
    {
        $candidates = [];

        foreach ($layouts as $layout) {
            foreach (self::claims($layout) as $role) {
                $candidates[$role][] = $layout;
            }
        }

        [$section, $content] = self::section($candidates['content'] ?? []);

        $mapped = [];
        $taken = [];
        $notes = [];

        foreach (LayoutRole::names() as $role) {
            $layout = match ($role) {
                'section' => $section,
                'content' => $content,
                default => self::best($candidates[$role] ?? [], $taken),
            };

            if ($layout === null) {
                continue;
            }

            $taken[] = $layout;
            $mapped[$role] = $layout->name();
            $notes[] = [
                'role' => $role,
                'name' => $layout->name(),
                'why' => self::why($layout, $role),
            ];
        }

        $unmapped = [];

        foreach (LayoutRole::names() as $role) {
            if (! isset($mapped[$role])) {
                $unmapped[] = $role;
            }
        }

        $unused = [];

        foreach ($layouts as $layout) {
            if (! in_array($layout, $taken, true)) {
                $unused[] = $layout->name();
            }
        }

        return new self($mapped, $unmapped, $unused, $notes);
    }

    /**
     * The roles, and the name of the layout each is.
     *
     * @return array<string, string>
     */
    public function mapped(): array
    {
        return $this->mapped;
    }

    /**
     * The roles the deck has no layout for.
     *
     * @return list<string>
     */
    public function unmapped(): array
    {
        return $this->unmapped;
    }

    /**
     * The layouts no role took.
     *
     * @return list<string>
     */
    public function unused(): array
    {
        return $this->unused;
    }

    /**
     * Why each role took the layout it took.
     *
     * @return list<array{role: string, name: string, why: string}>
     */
    public function notes(): array
    {
        return $this->notes;
    }

    /**
     * The roles a layout holds the placeholders of.
     *
     * @return list<string>
     */
    private static function claims(Layout $layout): array
    {
        $columns = count($layout->columns());

        return match (true) {
            $layout->hasTitle() && $layout->hasSubtitle() => ['title'],
            $layout->hasTitle() && $columns === 0 => ['image-title'],
            $layout->hasTitle() && $columns === 1 => ['content'],
            $layout->hasTitle() && $columns === 2 => [$layout->hasPicture() ? 'image-text' : 'two-column'],
            $layout->hasTitle() => [],
            $columns === 0 => ['blank'],
            $columns === 1 => [$layout->hasPicture() ? 'image-slide' : 'blank'],
            default => [],
        };
    }

    /**
     * The layout that is the section, and the one that is the content slide.
     *
     * @param list<Layout> $candidates The layouts that hold a title and one column.
     * @return array{0: ?Layout, 1: ?Layout}
     */
    private static function section(array $candidates): array
    {
        if ($candidates === []) {
            return [null, null];
        }

        $named = null;

        foreach ($candidates as $candidate) {
            if (str_contains(strtolower($candidate->name()), self::SECTION)) {
                $named = $candidate;

                break;
            }
        }

        if ($named !== null) {
            $rest = array_values(array_filter(
                $candidates,
                static fn (Layout $candidate): bool => $candidate !== $named,
            ));

            return [$named, $rest[0] ?? null];
        }

        if (count($candidates) === 1) {
            return [null, $candidates[0]];
        }

        // Several layouts hold a title and one column, and none of them says what
        // it is: what the deck does with them does. A section divider carries a
        // title and little else, so the layout whose slides carry the least text
        // is the one that is the divider.
        $used = array_values(array_filter(
            $candidates,
            static fn (Layout $candidate): bool => $candidate->slides() > 0,
        ));

        if ($used === []) {
            return [null, $candidates[0]];
        }

        usort(
            $used,
            static fn (Layout $left, Layout $right): int => $left->wordsPerSlide() <=> $right->wordsPerSlide(),
        );

        $section = $used[0];
        $rest = array_values(array_filter(
            $candidates,
            static fn (Layout $candidate): bool => $candidate !== $section,
        ));

        return [$section, $rest[0] ?? null];
    }

    /**
     * The layout that fits a role best, among the ones that are not taken.
     *
     * @param list<Layout> $candidates
     * @param list<Layout> $taken
     */
    private static function best(array $candidates, array $taken): ?Layout
    {
        $free = array_values(array_filter(
            $candidates,
            static fn (Layout $candidate): bool => ! in_array($candidate, $taken, true),
        ));

        if ($free === []) {
            return null;
        }

        // A blank slide is the one with nothing in it; a layout that holds a column
        // is a blank slide too, but it is the emptier of the two that is the better
        // answer for a role that means "nothing here".
        if (count($free) > 1) {
            usort(
                $free,
                static fn (Layout $left, Layout $right): int => $left->contents() <=> $right->contents(),
            );
        }

        return $free[0];
    }

    /**
     * Why a layout is the role it was mapped onto.
     */
    private static function why(Layout $layout, string $role): string
    {
        return match ($role) {
            'title' => 'a title and a subtitle',
            'content' => 'a title and one column',
            'two-column' => 'a title and two columns',
            'section' => 'a title and one column, and the deck uses it for a divider',
            'blank' => $layout->contents() === 0 ? 'nothing in it at all' : 'no title and one column',
            'image-text' => 'a title, a picture and a column',
            'image-title' => 'a title and nothing else',
            'image-slide' => 'a picture and nothing else',
            default => 'the placeholders it holds',
        };
    }
}
