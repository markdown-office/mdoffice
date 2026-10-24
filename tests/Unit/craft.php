<?php

declare(strict_types=1);

/**
 * The build configuration, and what it has to carry.
 *
 * Windows is the only platform this repository compiles: upstream publishes a
 * `micro` build for Linux and for macOS and none for Windows. So there is one
 * configuration file rather than three, and the differences between the platforms
 * are differences between what upstream publishes and what is built here.
 *
 * What the file has to carry does not depend on that: every platform's binary has
 * to have the extensions the libraries ask for, and the one platform that is
 * compiled here has to ask for the graphics libraries `gd` needs, or a build
 * passes `composer check-platform-reqs` and still refuses a photograph.
 */

/**
 * The configuration file, and the platform it is for.
 */
const CRAFT_FILE = 'craft-windows.yml';

/**
 * The extensions the compiled build has to carry.
 *
 * The same list the published interpreters carry, so it is written once here rather
 * than read from four places and compared.
 */
const CRAFT_COMMON_EXTENSIONS = [
    'dom',
    'simplexml',
    'xml',
    'xmlwriter',
    'gd',
    'zip',
    'mbstring',
    'phar',
    'iconv',
    'tokenizer',
    'filter',
    'ctype',
];

/**
 * The extensions the libraries ask for.
 *
 * A library that needs `ext-xmlwriter` and a build without it is a program that
 * fails on a document rather than on a version check — and `ext-xmlwriter` is not
 * hypothetical: `phpoffice/phpword` declares `ext-xml` and uses `XMLWriter`, which
 * is a different extension, and the two are bundled together on a normal install and
 * separate on a build from source.
 */
const CRAFT_REQUIRED_EXTENSIONS = [
    'ext-dom',
    'ext-gd',
    'ext-mbstring',
    'ext-xmlwriter',
    'ext-zip',
];

it('carries every extension the libraries ask for', function () {
    $carried = craftConfiguration(CRAFT_FILE)['extensions'];
    $missing = [];

    foreach (CRAFT_REQUIRED_EXTENSIONS as $extension) {
        if (!in_array(substr($extension, 4), $carried, true)) {
            $missing[] = $extension;
        }
    }

    expect($missing)->toBe([]);
});

it('builds the extensions the libraries need and nothing they do not', function () {
    $configuration = craftConfiguration(CRAFT_FILE);

    expect($configuration['extensions'])->toBe(CRAFT_COMMON_EXTENSIONS);
});

it('asks for the graphics libraries gd needs', function () {
    // `ext-gd` being present says nothing about the libraries behind it: the
    // functions are not even compiled in without them, so a build that passed a
    // platform check would still refuse a JPEG.
    expect(craftConfiguration(CRAFT_FILE)['build-options']['with-libs'] ?? null)
        ->toBe('freetype,libjpeg,libavif,libwebp');
});

it('leaves imagick out, because it cannot be built here', function () {
    // ImageMagick's own Windows build uses a 64-channel bit mask that needs a C++
    // compiler, and the tool that compiles the interpreter does not patch that. The
    // absence is a decision, and the smoke test asserts it, so a build that quietly
    // gained it would be noticed.
    expect(in_array('imagick', craftConfiguration(CRAFT_FILE)['extensions'], true))->toBe(false);
});

it('builds the interpreter the executable is made of', function () {
    // The `micro` SAPI is what becomes the executable. The `cli` one is not built:
    // the smoke test asks the micro what it ended up with, by appending a file to
    // it, so the second interpreter would be built for nothing.
    expect(craftConfiguration(CRAFT_FILE)['sapi'])->toBe(['micro']);
});

it('packs the executable, which is what the size of it is about', function () {
    // UPX takes 30–50% off the file, and the README says so. The tool is downloaded
    // rather than installed, so the size is what a user waits for.
    expect(craftConfiguration(CRAFT_FILE)['build-options']['with-upx-pack'] ?? false)->toBe(true);
});

it('builds the same PHP series as the interpreters that are published', function () {
    // Four of the five released binaries are the published interpreter, pinned to a
    // patch release in the release workflow; the fifth is compiled here. A build
    // that drifted from them would ship a Windows binary that is not the tool the
    // other four are, and nothing else would notice.
    $published = publishedPhpSeries();

    expect($published)->not->toBe('')
        ->and(craftConfiguration(CRAFT_FILE)['php-version'])->toBe($published);
});

/**
 * The PHP series the published interpreters are, as the release workflow pins it.
 *
 * The matrix names a patch release for every platform that takes a published
 * interpreter; the step that installs the runner's own PHP names a series, and it
 * is not the one this is about. Only the pinned patch releases are read.
 *
 * @return string The series, as `major.minor`.
 */
function publishedPhpSeries(): string
{
    $workflow = (string) file_get_contents(dirname(__DIR__, 2) . '/.github/workflows/release.yml');

    if (preg_match_all("/php-version:\s*'(\d+\.\d+\.\d+)'/", $workflow, $matches) === 0) {
        return '';
    }

    $versions = array_unique($matches[1]);

    if (count($versions) !== 1) {
        throw new RuntimeException(sprintf(
            'The release workflow pins more than one interpreter: %s.',
            implode(', ', $versions),
        ));
    }

    $parts = explode('.', $versions[0]);

    return $parts[0] . '.' . $parts[1];
}

/**
 * A configuration file, read as the flat keys, the one nested block and the one
 * nested list it is written as.
 *
 * A reader rather than a parser, because the file is the one this repository
 * writes and the shape of it is checked by the tests above: a file that stopped
 * being this shape would fail there rather than be read wrongly here.
 *
 * @return array{php-version: string, extensions: list<string>, sapi: list<string>, build-options: array<string, mixed>, download-options: array<string, mixed>}
 */
function craftConfiguration(string $file): array
{
    $path = dirname(__DIR__, 2) . '/' . $file;

    if (!is_file($path)) {
        throw new RuntimeException(sprintf('The build configuration %s does not exist.', $file));
    }

    $configuration = [
        'php-version' => '',
        'extensions' => [],
        'sapi' => [],
        'build-options' => [],
        'download-options' => [],
    ];

    $block = null;

    foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $trimmed = trim($line);

        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }

        // A list item under the block that is open.
        if (str_starts_with($trimmed, '- ')) {
            if ($block === 'sapi') {
                $configuration['sapi'][] = trim(substr($trimmed, 2));
            }

            continue;
        }

        [$name, $value] = array_pad(explode(':', $trimmed, 2), 2, '');
        $name = trim($name);
        $value = craftUnquote(trim($value));

        // An indented line belongs to the block above it.
        if (str_starts_with($line, ' ')) {
            if ($block !== null) {
                $configuration[$block][$name] = $value;
            }

            continue;
        }

        $block = null;

        if ($name === 'extensions') {
            $configuration['extensions'] = array_values(array_filter(
                array_map('trim', explode(',', trim($value, " \t\"'"))),
            ));

            continue;
        }

        if ($name === 'sapi') {
            $block = 'sapi';

            continue;
        }

        if ($name === 'build-options' || $name === 'download-options') {
            $block = $name;

            continue;
        }

        $configuration[$name] = $value;
    }

    foreach (['build-options', 'download-options'] as $section) {
        foreach ($configuration[$section] as $key => $value) {
            $configuration[$section][$key] = match ($value) {
                'true' => true,
                'false' => false,
                default => $value,
            };
        }
    }

    return $configuration;
}

/**
 * A value without the quotes it was written with.
 *
 * The quotes are YAML's, not the value's, and a reader that kept them would compare
 * `'8.4'` with `8.4` and find a difference where there is none.
 */
function craftUnquote(string $value): string
{
    if (strlen($value) >= 2) {
        $first = $value[0];
        $last = $value[strlen($value) - 1];

        if (($first === '"' || $first === "'") && $last === $first) {
            return substr($value, 1, -1);
        }
    }

    return $value;
}
