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

            // Nothing, and a mapping with nothing in it, are left out rather than
            // written as a key with no value under it. The theme reader takes such a
            // key as "not said" and keeps the library's own answer; the layouts reader
            // takes `layouts:` as a role name rather than as the mappings, so neither
            // writer writes an empty mapping in the shape its own reader happens to
            // take.
            if ($value === null || $value === []) {
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

        // A mapping with nothing in it is left out for the reason {@see self::theme()} gives.
        if ($mapping !== []) {
            $lines[] = 'layouts:';

            foreach ($mapping as $role => $name) {
                $lines[] = sprintf('  %s: %s', $role, self::scalar($name));
            }
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
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_float($value) => self::number($value),
            is_int($value) => (string) $value,
            default => self::text($value),
        };
    }

    /**
     * A number, as YAML writes it.
     *
     * A whole number keeps its point: the leading of a theme is a multiple of a
     * line's own height, and `1` is a number of lines rather than a multiple of
     * one.
     */
    private static function number(float $value): string
    {
        return (float) (int) $value === $value ? sprintf('%.1f', $value) : (string) $value;
    }

    /**
     * A string, as YAML writes it.
     */
    private static function text(string $text): string
    {
        if ($text === '' || preg_match('/^[A-Za-z][A-Za-z0-9 ._\/-]*$/', $text) !== 1) {
            return self::quoted($text);
        }

        if (in_array(strtolower($text), self::KEYWORDS, true) || is_numeric($text)) {
            return self::quoted($text);
        }

        return $text;
    }

    /**
     * A string in quotes, with what it holds escaped.
     */
    private static function quoted(string $text): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $text) . '"';
    }
}
