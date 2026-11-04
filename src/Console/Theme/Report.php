<?php

declare(strict_types=1);

namespace MarkdownOffice\Console\Theme;

use MarkdownPresentation\Theme\StyleMapping;
use MarkdownPresentation\Theme\ThemeLoader;

/**
 * What the scaffold command did, and what it could not decide.
 *
 * The report is what the reader of the command line sees, and it has one job: to say
 * where every number in the theme came from. A slot the theme sets, a slot the theme
 * leaves alone, a role the deck's own layouts cover and a role they do not — each is
 * named, and each says which of the two it is.
 *
 * It is a class of its own rather than a part of the command, because it is a different
 * thing from what the command does: the command writes the theme, and this says what it
 * wrote.
 */
final class Report
{
    /**
     * The names of a list of them, indented under the heading they belong to.
     */
    private const LISTED_NAMES = '    %s';

    /**
     * The report the command prints.
     *
     * @param string $input The deck the theme was scaffolded from.
     * @param string $directory The directory the theme was written into.
     * @param array<string, array{title?: \MarkdownPresentation\Render\Box, body?: list<\MarkdownPresentation\Render\Box>}> $placed
     *        The boxes the template gives, by layout name.
     */
    public function __construct(
        private readonly string $input,
        private readonly string $directory,
        private readonly Deck $deck,
        private readonly Palette $palette,
        private readonly TypeScale $scale,
        private readonly Roles $roles,
        private readonly array $placed,
    ) {
    }

    /**
     * The report, as the lines it is.
     *
     * @return list<string>
     */
    public function lines(): array
    {
        $lines = [
            sprintf(
                'mdoffice theme: wrote the theme for "%s" into "%s".',
                basename($this->input),
                $this->directory,
            ),
            '',
            sprintf(
                '  %-13s %s',
                ThemeLoader::THEME_FILE,
                'the colours, the fonts, the type scale and the layout roles',
            ),
        ];

        if ($this->deck->hasMaster()) {
            $lines[] = sprintf(
                '  %-13s the deck\'s %d layouts, without its %d slides',
                ThemeLoader::TEMPLATE_FILE,
                count($this->deck->layouts()),
                $this->deck->slides(),
            );
        }

        return [
            ...$lines,
            ...$this->colours(),
            ...$this->type(),
            ...$this->roles(),
            ...$this->defaults(),
            ...$this->unmapped(),
            ...$this->template(),
        ];
    }

    /**
     * The colours the theme carries, and the ones it leaves alone.
     *
     * @return list<string>
     */
    private function colours(): array
    {
        $lines = ['', '  colours'];

        foreach ($this->palette->notes() as $note) {
            $lines[] = sprintf('    %-10s %-9s %s', $note['slot'], $note['value'], $note['where']);
        }

        if ($this->palette->omissions() !== []) {
            $lines[] = '';
            $lines[] = '  not in the theme';

            foreach ($this->palette->omissions() as $omission) {
                $lines[] = '    ' . $omission;
            }
        }

        return $lines;
    }

    /**
     * The type scale the theme carries.
     *
     * @return list<string>
     */
    private function type(): array
    {
        $lines = ['', '  type'];

        foreach ($this->scale->notes() as $note) {
            $lines[] = sprintf('    %-14s %-24s %s', $note['what'], $note['value'], $note['where']);
        }

        return $lines;
    }

    /**
     * The layout roles, and why each took the layout it took.
     *
     * @return list<string>
     */
    private function roles(): array
    {
        $lines = ['', '  roles'];
        $reasons = [];

        foreach ($this->roles->notes() as $note) {
            $reasons[$note['role']] = $note['why'];
        }

        foreach ($this->roles->renamed() as $role => $library) {
            $reasons[$role] = sprintf('a name of its own: the deck\'s layout is called %s', $library);
        }

        foreach ($this->roles->complete() as $role => $name) {
            $lines[] = sprintf(
                '    %-11s %-26s %s',
                $role,
                $name,
                $reasons[$role] ?? "the library's own name",
            );
        }

        $lines[] = '';
        $lines[] = $this->split();

        return $lines;
    }

    /**
     * What the theme leaves at the library's own default.
     *
     * @return list<string>
     */
    private function defaults(): array
    {
        $colours = $this->palette->defaultColours();
        $numbers = $this->leftAlone();
        $lines = [];

        if ($colours !== [] || $numbers !== []) {
            $lines[] = '';
            $lines[] = '  left at the library\'s default';
        }

        if ($colours !== []) {
            // The colour with the name, because what a person edits is the colour: a slot
            // that says only that it was left alone does not say what it was left as.
            $lines[] = sprintf('    %-8s %s', 'colours', implode(', ', array_map(
                static fn (string $slot, string $value): string => sprintf('%s %s', $slot, $value),
                array_keys($colours),
                array_values($colours),
            )));
        }

        if ($numbers !== []) {
            $lines[] = sprintf('    %-8s %s', 'type', implode(', ', $numbers));
        }

        return $lines;
    }

    /**
     * The roles the deck has no layout for, and the layouts no role took.
     *
     * @return list<string>
     */
    private function unmapped(): array
    {
        $lines = [];

        if ($this->roles->unmapped() !== []) {
            $lines[] = '';
            $lines[] = '  the deck has no layout for';
            $lines[] = sprintf(self::LISTED_NAMES, implode(', ', $this->roles->unmapped()));
        }

        if ($this->roles->unused() !== []) {
            $lines[] = '';
            $lines[] = '  no role was pointed at these layouts';
            $lines[] = sprintf(self::LISTED_NAMES, implode(', ', $this->roles->unused()));
        }

        return $lines;
    }

    /**
     * What the template carries, and what it does not.
     *
     * @return list<string>
     */
    private function template(): array
    {
        if (! $this->deck->hasMaster()) {
            return ['', '  the deck has no master, so the theme carries no template.'];
        }

        $covering = 0;

        foreach ($this->roles->mapped() as $name) {
            if (isset($this->placed[$name])) {
                $covering++;
            }
        }

        return [
            '',
            sprintf(
                '  the template places %d of the %d roles the deck\'s own layouts map.',
                $covering,
                count($this->roles->mapped()),
            ),
        ];
    }

    /**
     * How the roles were split between the deck's own layouts and the library's.
     *
     * A reader of the file has to be able to tell which names are the deck's and which
     * are the library's, so the two are counted apart — and a role that had to take a
     * name of its own is counted apart from them too, because its name is neither.
     */
    private function split(): string
    {
        $total = count($this->roles->complete());
        $own = count($this->roles->mapped());
        $renamed = count($this->roles->renamed());

        if ($renamed === 0) {
            return sprintf(
                '  the deck\'s own layouts map %d of the %d roles; the library\'s own names map the other %d.',
                $own,
                $total,
                $total - $own,
            );
        }

        return sprintf(
            '  the deck\'s own layouts map %d of the %d roles; the library\'s own names map %d, and the rest take a name of their own.',
            $own,
            $total,
            $total - $own - $renamed,
        );
    }

    /**
     * The numbers the deck did not answer.
     *
     * The type scale and the spacing are the same kind of thing — a number the deck did
     * not carry — and the colours are another kind of thing altogether, so the two are
     * reported apart rather than in one list that mixes a colour with four measurements.
     *
     * @return list<string>
     */
    private function leftAlone(): array
    {
        $defaults = $this->scale->defaults();

        foreach (StyleMapping::KEYS as $key) {
            if (! in_array($key, TypeScale::KEYS, true)) {
                $defaults[] = $key;
            }
        }

        $defaults[] = 'mono';

        return $defaults;
    }
}
