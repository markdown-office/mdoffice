<?php

declare(strict_types=1);

namespace MarkdownOffice\Console\Theme;

use MarkdownPresentation\Theme\StyleMapping;
use MarkdownPresentation\Theme\Theme;
use MarkdownPresentation\Theme\ThemeMapping;

/**
 * The two files of a theme, written as YAML.
 *
 * The shape of a `theme.yml` is the library's, not this file's: the keys are
 * {@see ThemeMapping::KEYS}, the colours are the twelve of {@see Theme::COLOURS} in
 * DrawingML's order and the style is the twelve numbers of
 * {@see StyleMapping::KEYS}. A theme written here is read by the same reader that
 * reads the themes the library ships, and the command reads its own output back
 * through it before it says the theme is written.
 *
 * The files carry a comment at the top, because a theme is a place to explain a
 * design and a scaffolded one has to say where its numbers came from: the reader
 * of the file is a person deciding whether to keep them.
 *
 * @phpstan-type Value string|int|float|bool
 * @phpstan-type Style array<string, Value|list<Value>>
 * @phpstan-type Data array{
 *     name?: string,
 *     description?: ?string,
 *     author?: ?string,
 *     dark?: bool,
 *     colors?: array<string, string>,
 *     fonts?: array<string, string>,
 *     style?: Style
 * }
 */
final class Manifest
{
    /**
     * The words a plain scalar may not be, because YAML would read them as
     * something else.
     */
    private const KEYWORDS = ['true', 'false', 'null', 'yes', 'no', 'on', 'off', 'y', 'n'];

    /**
     * A `theme.yml`.
     *
     * @param Data $data The theme, keyed as the library's keys.
     */
    public static function theme(string $comment, array $data): string
    {
        $lines = self::comment($comment);

        foreach (ThemeMapping::KEYS as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }

            $value = $data[$key];

            if ($value === null) {
                continue;
            }

            if (! is_array($value)) {
                $lines[] = $key . ': ' . self::scalar($value);

                continue;
            }

            $lines[] = $key . ':';

            foreach ($value as $name => $item) {
                $lines[] = is_array($item)
                    ? sprintf('  %s: [%s]', $name, implode(', ', array_map(self::scalar(...), $item)))
                    : sprintf('  %s: %s', $name, self::scalar($item));
            }
        }

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    /**
     * A `layouts.yml`.
     *
     * @param array<string, string> $mapping The role to the name of the layout it is.
     */
    public static function layouts(string $comment, array $mapping): string
    {
        $lines = self::comment($comment);
        $lines[] = 'layouts:';

        foreach ($mapping as $role => $name) {
            $lines[] = sprintf('  %s: %s', $role, self::scalar($name));
        }

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    /**
     * A comment, one line per line of the text.
     *
     * @return list<string>
     */
    private static function comment(string $comment): array
    {
        $lines = [];

        foreach (explode(PHP_EOL, trim($comment)) as $line) {
            $lines[] = $line === '' ? '#' : '# ' . $line;
        }

        return $lines;
    }

    /**
     * A value, as YAML writes it.
     *
     * A colour is quoted because a `#` starts a comment, and a layout name is
     * quoted when it holds anything a plain scalar may not — a comma, a colon, a
     * digit at the front — because the file is read by people as well as by the
     * library.
     *
     * @param Value $value
     */
    private static function scalar(string|int|float|bool $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_float($value)) {
            // A whole number keeps its point: the leading of a theme is a multiple
            // of a line's own height, and `1` is a number of lines rather than a
            // multiple of one.
            return (float) (int) $value === $value ? sprintf('%.1f', $value) : (string) $value;
        }

        if (is_int($value)) {
            return (string) $value;
        }

        $text = $value;

        if ($text === '' || preg_match('/^[A-Za-z][A-Za-z0-9 ._\/-]*$/', $text) !== 1) {
            return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $text) . '"';
        }

        if (in_array(strtolower($text), self::KEYWORDS, true) || is_numeric($text)) {
            return '"' . $text . '"';
        }

        return $text;
    }
}
