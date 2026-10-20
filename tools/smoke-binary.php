<?php

declare(strict_types=1);

/**
 * The smoke test for a built binary.
 *
 * A static build can fail in ways a test suite never sees: an extension the code
 * needs is missing from the compile, the archive is not attached to the interpreter
 * the way it was meant to be, or the binary is signed in a way the kernel refuses.
 * None of that shows up until the artifact runs on a machine that has nothing else
 * on it, which is the machine the artifact is for.
 *
 * So the artifact is what is tested. The combined binary converts a document each
 * way and writes a deck; the static `php` the same build produced reports which
 * extensions it ended up with. The two come out of one `spc craft` run, so the
 * extensions the interpreter has are the extensions the binary has.
 *
 * Usage:
 *
 *     php tools/smoke-binary.php --binary <path> --php <path> --root <dir> [--imagick yes|no]
 *
 * `--imagick` says whether the platform's build carries `ext-imagick`, which is
 * asserted rather than assumed: it is a known gap on Windows, and a build that
 * quietly gained it — or quietly lost it — is a surprise in one direction and an
 * absence in the other, so CI is where it is noticed.
 */

/** Padded, so every result lines up whatever the length of the label beside it. */
const STEP_LABEL_FORMAT = '  %-44s';

const STEP_OK = "ok\n";
const STEP_FAILED = "failed\n";

/**
 * The extensions the libraries and this tool ask for, and the ones the build needs
 * whatever the libraries ask for.
 *
 * `ext-xmlwriter` is here because `phpoffice/phpword` declares `ext-xml` and uses
 * `XMLWriter`, which is a different extension; the split only shows on a build from
 * source, and a normal installation has both. `ext-phar` is here because the stub
 * is a phar. The rest are what PHP itself needs to parse and run what is compiled
 * into it.
 */
const REQUIRED_EXTENSIONS = [
    'ctype',
    'dom',
    'filter',
    'gd',
    'iconv',
    'mbstring',
    'phar',
    'simplexml',
    'tokenizer',
    'xml',
    'xmlwriter',
    'zip',
];

$options = parseOptions($argv);

$root = $options['root'] ?? dirname(__DIR__);
$binary = $options['binary'] ?? null;
$php = $options['php'] ?? null;
$imagick = ($options['imagick'] ?? 'no') === 'yes';

if ($binary === null || $php === null) {
    fwrite(STDERR, "Usage: php tools/smoke-binary.php --binary <path> --php <path> --root <dir> [--imagick yes|no]\n");
    fwrite(STDERR, "  --binary  the combined executable\n");
    fwrite(STDERR, "  --php     the static php from the same build, for the extension assertions\n");
    fwrite(STDERR, "  --root    the repository, for the examples and the fixture\n");

    exit(2);
}

foreach (['binary' => $binary, 'php' => $php] as $what => $path) {
    if (!is_file($path)) {
        fwrite(STDERR, sprintf("The %s at %s does not exist.\n", $what, $path));

        exit(2);
    }
}

$work = sys_get_temp_dir() . '/mdoffice-smoke-' . getmypid();

if (!mkdir($work, 0o777, true) && !is_dir($work)) {
    fwrite(STDERR, sprintf("Could not make %s.\n", $work));

    exit(2);
}

register_shutdown_function(static function () use ($work): void {
    foreach ((array) glob($work . '/*') as $file) {
        if (is_string($file)) {
            @unlink($file);
        }
    }

    @rmdir($work);
});

$binary = escapeshellarg($binary);
$php = escapeshellarg($php);

// The version, and the two libraries it was built against. What is asserted here is
// that the binary names them: the build asks for the libraries by branch, so the
// version it reports is a branch rather than a number, and a number would be a
// claim about a pin this build does not make.
$version = capture($binary . ' --version');
$first = strtok(trim($version), PHP_EOL);

report(
    'the binary runs',
    $first !== false && str_starts_with((string) $first, 'mdoffice '),
    sprintf("The binary said:\n%s\n", trim($version)),
);

report(
    'it names both libraries',
    str_contains($version, 'markdown-office/markdown-word')
    && str_contains($version, 'markdown-office/markdown-presentation'),
    sprintf("The binary said:\n%s\n", trim($version)),
);

// The round trip, through a file rather than a pipe: a redirection written after a
// pipe binds to the wrong end of it, and the reading end would get the document
// instead of the output.
$example = escapeshellarg($root . '/examples/01-smoke.md');
$document = $work . '/smoke.docx';

run(
    $binary . ' word ' . $example . ' --to docx -o ' . escapeshellarg($document),
    'it converts Markdown to a document',
);

report(
    'the document is a document',
    str_starts_with((string) @file_get_contents($document), 'PK'),
    sprintf("%s does not begin with the zip magic.\n", $document),
);

$back = $work . '/smoke.md';

run(
    $binary . ' word ' . escapeshellarg($document) . ' -o ' . escapeshellarg($back),
    'it converts a document back to Markdown',
);

$markdown = (string) @file_get_contents($back);

report(
    'the document kept its heading',
    str_contains($markdown, '# Smoke test'),
    sprintf("%s does not carry the heading back.\n", $back),
);

// The table is what a theme, the style registry and PHPWord's own writer all have a
// hand in, so a round trip that returns it is evidence the archive is whole rather
// than merely loadable.
report(
    'the document kept its table',
    str_contains($markdown, '| one'),
    sprintf("%s does not carry the table back.\n", $back),
);

$deck = $work . '/deck.pptx';

run(
    $binary . ' slides ' . escapeshellarg($root . '/examples/02-deck.md') . ' -o ' . escapeshellarg($deck),
    'it writes a deck',
);

report(
    'the deck is a document',
    str_starts_with((string) @file_get_contents($deck), 'PK'),
    sprintf("%s does not begin with the zip magic.\n", $deck),
);

report('the deck has slides', deckHasSlides($deck));

// The extensions, from the interpreter the same build produced. The binary cannot
// be asked directly: it is a program with a fixed job, and the only PHP it will run
// is the archive's own entry point.
$loaded = array_filter(
    explode(',', trim((string) capture($php . ' -r ' . escapeshellarg(
        'echo implode(",", array_filter(' . var_export(REQUIRED_EXTENSIONS, true) . ', "extension_loaded")), "\n";'
    )))),
    'strlen',
);

$missing = array_values(array_diff(REQUIRED_EXTENSIONS, $loaded));

report(
    'the extensions are loaded',
    $missing === [],
    sprintf("Missing: %s\n", implode(', ', $missing)),
);

report(
    $imagick ? 'imagick is loaded' : 'imagick is absent',
    $imagick === extensionIsLoaded($php, 'imagick'),
    sprintf(
        "The platform's build says %s about imagick, and this test says %s.\n",
        $imagick ? 'should have it' : 'should not have it',
        $imagick ? 'it does not' : 'it does',
    ),
);

// The JPEG. `ext-gd` being present says nothing about the libraries behind it, and a
// static build of `gd` carries no JPEG support unless it was asked for by name — so
// the function is there either way, and only reading a JPEG tells the difference.
$jpeg = $root . '/tests/fixtures/jpeg.jpg';

report(
    'it reads a JPEG',
    capture($php . ' -r ' . escapeshellarg(sprintf(
        'echo imagecreatefromjpeg(%s) === false ? "no" : "yes";',
        var_export($jpeg, true),
    ))) === 'yes',
    sprintf("%s could not be read by the build's gd.\n", $jpeg),
);

exit(0);

/**
 * @param list<string> $arguments
 * @return array<string, string>
 */
function parseOptions(array $arguments): array
{
    $options = [];

    for ($i = 1; $i < count($arguments); $i++) {
        $argument = $arguments[$i];

        if (!str_starts_with($argument, '--')) {
            continue;
        }

        $name = substr($argument, 2);
        $value = $arguments[$i + 1] ?? '';

        if (str_starts_with($value, '--')) {
            $value = '';
        } else {
            $i++;
        }

        $options[$name] = $value;
    }

    return $options;
}

/** `$why` goes to standard error, with the rest of a failure rather than the report. */
function report(string $what, bool $passed, string $why = ''): void
{
    fwrite(STDOUT, sprintf(STEP_LABEL_FORMAT, $what));

    if ($passed) {
        fwrite(STDOUT, STEP_OK);

        return;
    }

    fwrite(STDOUT, STEP_FAILED);

    if ($why !== '') {
        fwrite(STDERR, $why);
    }

    exit(1);
}

function run(string $command, string $what): void
{
    $output = [];
    $status = 0;

    exec($command . ' 2>&1 < /dev/null', $output, $status);

    if ($status !== 0) {
        fwrite(STDOUT, sprintf(STEP_LABEL_FORMAT, $what));
        fwrite(STDOUT, STEP_FAILED);
        fwrite(STDERR, implode(PHP_EOL, $output) . PHP_EOL);

        exit($status);
    }

    fwrite(STDOUT, sprintf(STEP_LABEL_FORMAT, $what));
    fwrite(STDOUT, STEP_OK);
}

/**
 * An inherited standard input is how a check that should have taken moments ends up
 * waiting on a terminal that is not there: the reading end has no writer and the
 * child never sees end of file. Every way of shelling out here closes it for the
 * same reason.
 */
function capture(string $command): string
{
    return (string) shell_exec($command . ' < /dev/null');
}

function extensionIsLoaded(string $php, string $extension): bool
{
    return capture($php . ' -r ' . escapeshellarg(
        'echo extension_loaded(' . var_export($extension, true) . ') ? "yes" : "no";'
    )) === 'yes';
}

/**
 * A deck is a zip with slide parts in it, so the part is looked for in the archive
 * rather than inferred from the file's name.
 */
function deckHasSlides(string $deck): bool
{
    if (!class_exists(ZipArchive::class)) {
        fwrite(STDERR, "This check needs ext-zip in the PHP running the test.\n");

        exit(2);
    }

    $archive = new ZipArchive();

    if ($archive->open($deck) !== true) {
        return false;
    }

    $slides = 0;

    for ($i = 0; $i < $archive->numFiles; $i++) {
        $name = (string) $archive->getNameIndex($i);

        if (str_starts_with($name, 'ppt/slides/slide') && str_ends_with($name, '.xml')) {
            $slides++;
        }
    }

    $archive->close();

    return $slides > 0;
}
