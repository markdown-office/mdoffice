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
 * else, and pointing two roles at one name. The first is avoided by mapping on
 * structure alone, and the second by making sure that no two roles are given one
 * name: a name is one layout, and two roles on one name are one layout drawn
 * twice.
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
     * The whole mapping, as a theme's file carries it.
     *
     * A theme's `layouts:` section is the whole mapping: a section that names one
     * role and not the others is a theme that cannot draw a slide on the others, and
     * the library refuses it by name. So a scaffold maps every role — the roles the
     * deck's own layouts cover by the deck's own names, and the rest by the
     * library's, which are the names the library draws the layouts it generates
     * under.
     *
     * A scaffold is meant to be used, and a theme that can only draw the role it was
     * scaffolded from fails on the first deck that differs. A role the deck had no
     * layout for is a role the theme can still draw.
     *
     * Two roles may not share a name: the library draws one layout for a name, so a
     * role pointed at a name another role holds is drawn in that role's geometry. The
     * deck's own name is the deck's, and a role the deck has no layout for takes a
     * name of its own rather than the one that is taken.
     *
     * @return array<string, string>
     */
    public function complete(): array
    {
        return self::mapping($this->mapped)[0];
    }

    /**
     * The roles that take a name of their own, and the library's name they could
     * not take.
     *
     * @return array<string, string>
     */
    public function renamed(): array
    {
        return self::mapping($this->mapped)[1];
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
     * The whole mapping of the roles, and the roles that had to be renamed to make it.
     *
     * @param array<string, string> $mapped The roles the deck's own layouts cover.
     * @return array{0: array<string, string>, 1: array<string, string>} The mapping,
     *         and the roles that take a name of their own with the library's name they
     *         could not take.
     */
    private static function mapping(array $mapped): array
    {
        $complete = [];
        $renamed = [];
        $names = array_values($mapped);

        foreach (LayoutRole::names() as $role) {
            if (isset($mapped[$role])) {
                $complete[$role] = $mapped[$role];

                continue;
            }

            $library = LayoutRole::defaults()[$role];
            $name = $library;

            if (in_array($library, $names, true)) {
                $name = self::ownName($role, $names);
                $renamed[$role] = $library;
            }

            $complete[$role] = $name;
            $names[] = $name;
        }

        return [$complete, $renamed];
    }

    /**
     * A name of the role's own, for a role whose library name another role holds.
     *
     * The role's own name is what a deck writes to ask for the role, and the roles
     * are a closed set of eight, so no other role has it. The number is for the deck
     * that names a layout after a role — a layout called "blank" that is a content
     * slide — and it is the last resort.
     *
     * @param list<string> $names The names the other roles hold.
     */
    private static function ownName(string $role, array $names): string
    {
        $name = $role;
        $number = 2;

        while (in_array($name, $names, true)) {
            $name = $role . ' ' . $number;
            $number++;
        }

        return $name;
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

        $section = self::divider($candidates);
        $content = $section === null ? $candidates[0] : self::firstOther($candidates, $section);

        return [$section, $content];
    }

    /**
     * The layout that is the section divider, or null when there is none.
     *
     * A layout that says what it is — a name that holds the word the library
     * uses for a section — is the divider. Where none of them says so, what the
     * deck does with them does: a section divider carries a title and little
     * else, so the layout whose slides carry the least text is the one that is
     * the divider.
     *
     * @param list<Layout> $candidates The layouts that hold a title and one column.
     */
    private static function divider(array $candidates): ?Layout
    {
        foreach ($candidates as $candidate) {
            if (str_contains(strtolower($candidate->name()), self::SECTION)) {
                return $candidate;
            }
        }

        return self::quietest($candidates);
    }

    /**
     * The layout whose slides carry the least text, or null when there is none.
     *
     * @param list<Layout> $candidates The layouts that hold a title and one column.
     */
    private static function quietest(array $candidates): ?Layout
    {
        // One layout that holds a title and one column cannot be a divider and a
        // content slide at once, so it is the content slide.
        if (count($candidates) === 1) {
            return null;
        }

        $used = array_values(array_filter(
            $candidates,
            static fn (Layout $candidate): bool => $candidate->slides() > 0,
        ));

        if ($used === []) {
            return null;
        }

        usort(
            $used,
            static fn (Layout $left, Layout $right): int => $left->wordsPerSlide() <=> $right->wordsPerSlide(),
        );

        return $used[0];
    }

    /**
     * The first of the candidates that is not this one.
     *
     * @param list<Layout> $candidates
     */
    private static function firstOther(array $candidates, Layout $taken): ?Layout
    {
        foreach ($candidates as $candidate) {
            if ($candidate !== $taken) {
                return $candidate;
            }
        }

        return null;
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
