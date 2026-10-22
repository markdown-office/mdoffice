<?php

declare(strict_types=1);

/**
 * The three build configurations, and the two differences between them.
 *
 * The platforms do not build the same thing: `imagick` cannot be built on Windows,
 * and UPX cannot pack a Mach-O binary. Everything else is the same build, and it
 * has to stay the same — a platform that quietly gained or lost an extension would
 * be a difference nobody decided on.
 *
 * So the differences are held to a list. A fourth file, or a third difference, fails
 * here rather than shipping.
 */

/**
 * The configurations, and what each one is allowed to differ in.
 *
 * `imagick` is present on the platforms that can build it and absent on the one that
 * cannot; `upx` is present on the platforms UPX supports and absent on the one it
 * does not.
 */
const CRAFT_PLATFORMS = [
    'macos' => ['craft-macos.yml', true, false],
    'linux' => ['craft-linux.yml', true, true],
    'windows' => ['craft-windows.yml', false, true],
];

/**
 * The extensions every platform's build has to carry.
 *
 * The same list in all three, so it is written once here rather than read three
 * times and compared.
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
 * The extensions the three manifests ask for.
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

it('builds the same thing on every platform but for the two differences', function (string $file, bool $imagick, bool $upx) {
    $configuration = craftConfiguration($file);

    expect(array_values(array_diff(CRAFT_COMMON_EXTENSIONS, $configuration['extensions'])))->toBe([])
        ->and(in_array('imagick', $configuration['extensions'], true))->toBe($imagick)
        ->and(($configuration['build-options']['with-upx-pack'] ?? false) === true)->toBe($upx)
        // The graphics libraries `gd` is built against, and the reason the build is
        // worth two minutes: without them the extension has no JPEG at all.
        ->and($configuration['build-options']['with-libs'] ?? null)
        ->toBe('freetype,libjpeg,libavif,libwebp')
        ->and($configuration['php-version'])->toBe('8.4')
        // The `cli` SAPI is what the smoke test asks which extensions the build ended
        // up with; the `micro` one is what becomes the executable.
        ->and($configuration['sapi'])->toBe(['cli', 'micro']);
})->with(CRAFT_PLATFORMS);

/**
 * The three files differ in the two ways and no others.
 *
 * Compared key by key rather than as whole files, because the two differences are
 * additions in one case and a removal in the other, and a whole-file comparison
 * would report every comment as a difference too.
 */
it('differs between the platforms in nothing but the two', function () {
    $macos = craftConfiguration('craft-macos.yml');
    $linux = craftConfiguration('craft-linux.yml');
    $windows = craftConfiguration('craft-windows.yml');

    // The extensions, which differ by `imagick` alone.
    expect(array_values(array_diff($macos['extensions'], $windows['extensions'])))->toBe(['imagick'])
        ->and(array_values(array_diff($windows['extensions'], $macos['extensions'])))->toBe([])
        ->and($linux['extensions'])->toBe($macos['extensions']);

    // Everything else, which does not differ at all.
    foreach (['php-version', 'sapi', 'download-options'] as $key) {
        expect($linux[$key])->toBe($macos[$key], $key . ' differs between macOS and Linux')
            ->and($windows[$key])->toBe($macos[$key], $key . ' differs between macOS and Windows');
    }

    // The build options, which differ by UPX alone: it is in the two that pack and
    // not in the one that cannot.
    expect(array_keys(array_diff_key($linux['build-options'], $macos['build-options'])))
        ->toBe(['with-upx-pack'])
        ->and(array_keys(array_diff_key($macos['build-options'], $linux['build-options'])))
        ->toBe([])
        ->and(array_keys(array_diff_key($windows['build-options'], $macos['build-options'])))
        ->toBe(['with-upx-pack']);
});

it('carries every extension the libraries ask for', function () {
    $carried = craftConfiguration('craft-macos.yml')['extensions'];
    $missing = [];

    foreach (CRAFT_REQUIRED_EXTENSIONS as $extension) {
        if (!in_array(substr($extension, 4), $carried, true)) {
            $missing[] = $extension;
        }
    }

    expect($missing)->toBe([]);
});

/**
 * A configuration file, read as the flat keys, the one nested block and the one
 * nested list it is written as.
 *
 * A reader rather than a parser, because the three files are the ones this
 * repository writes and the shape of them is checked by the test above: a file that
 * stopped being this shape would fail there rather than be read wrongly here.
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
