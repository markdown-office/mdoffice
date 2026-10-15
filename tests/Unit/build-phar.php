<?php

declare(strict_types=1);

/*
 * The build script: what it stages, and what it prunes out of the result.
 *
 * These reach the script by `require`ing it with {@see BUILD_PHAR_INCLUDED} defined,
 * which loads the definitions and stops before the build. That is the whole reason
 * that constant exists: these functions have behaviour worth asserting, and a test
 * that had to run the build to reach them would be asserting the build instead — a
 * minute of installing a second copy of the dependencies per assertion, and a failure
 * that said nothing about which function was wrong.
 *
 * `markdown-word` tests its copy of this script by reading its source text and
 * matching the constants out of it. That checks the name lists and nothing else, and
 * it would have missed the `path`→`vcs` rewrite entirely, which is the part of the
 * build that was written new for this repository.
 */

use MarkdownOffice\Tests\Support\Scratch;

/**
 * Load the build script's definitions, once.
 *
 * `require_once` alone would not do: the script runs on include unless the constant
 * is defined first, and a test that loaded it the other way round would find a build
 * had already run.
 */
function buildScript(): void
{
    if (!defined('MDOFFICE_BUILD_PHAR_INCLUDED')) {
        define('MDOFFICE_BUILD_PHAR_INCLUDED', true);
    }

    require_once dirname(__DIR__, 2) . '/tools/build-phar.php';
}

/**
 * Whether a staged path, relative to `app/`, is one of the strays.
 *
 * The last segment is matched against the file names and every earlier one against the
 * directory names, which is what the build does: `cp -R` brings a stray in wherever
 * it sits, and a name can be a stray directory's at one depth and its own file's at
 * another.
 */
function isStray(string $relative): bool
{
    buildScript();

    $segments = explode('/', $relative);

    foreach ($segments as $index => $segment) {
        $last = $index === count($segments) - 1;
        $names = $last ? STRAY_FILES : STRAY_DIRECTORIES;

        if (in_array($segment, $names, true)) {
            return true;
        }
    }

    return false;
}

/**
 * A fresh directory inside the scratch area.
 *
 * `Scratch::path()` returns a *file* path, and both arguments `stageManifest()` takes
 * are directories — it appends `/composer.json` to the first and writes that name into
 * the second. So the staging helper cannot be built on it directly.
 */
function stageDirectory(string $prefix): string
{
    $path = Scratch::directory() . '/' . $prefix . '-' . bin2hex(random_bytes(6));

    if (!mkdir($path, 0o777, true) && !is_dir($path)) {
        throw new RuntimeException('Unable to create ' . $path);
    }

    return $path;
}

/**
 * A staged manifest built from the given `require` and `repositories`.
 *
 * @param array<string, string>       $require
 * @param list<array<string, string>> $repositories
 */
function stageFrom(array $require, array $repositories): array
{
    buildScript();

    $root = stageDirectory('build-root');
    $deps = stageDirectory('build-deps');

    file_put_contents($root . '/composer.json', (string) json_encode([
        'name' => 'markdown-office/mdoffice',
        'require' => $require,
        'repositories' => $repositories,
    ]));

    stageManifest($root, $deps);

    return (array) json_decode((string) file_get_contents($deps . '/composer.json'), true);
}

it('asks a path repository for by URL, so the staged manifest resolves', function () {
    // A `path` URL is resolved against the directory holding the manifest, so
    // `../markdown-word` means `build/markdown-word` once staged — and a build run
    // anywhere but beside a checkout has no siblings at all.
    $staged = stageFrom(
        ['markdown-office/markdown-word' => '*'],
        [['type' => 'path', 'url' => '../markdown-word']],
    );

    expect($staged['repositories'][0])
        ->toBe(['type' => 'vcs', 'url' => VCS_URLS['markdown-word']]);
});

it('asks the library for by branch, not by whatever version resolves', function () {
    // The trap this avoids: `prefer-stable` is on, so `*` takes the newest *tag*, and
    // `markdown-word`'s `v0.1.1` predates `Format`. The build then installs a library
    // this tool cannot run against and the archive fails on a class-not-found for
    // something the library has had for a long time.
    $staged = stageFrom(
        ['markdown-office/markdown-word' => '*'],
        [['type' => 'path', 'url' => '../markdown-word']],
    );

    expect($staged['require']['markdown-office/markdown-word'])->toBe(VCS_BRANCH_CONSTRAINT);
});

it('rewrites every path repository it finds, and leaves the others alone', function () {
    $staged = stageFrom(
        [
            'markdown-office/markdown-word' => '*',
            'markdown-office/markdown-presentation' => '*',
        ],
        [
            ['type' => 'path', 'url' => '../markdown-word'],
            ['type' => 'path', 'url' => '../markdown-presentation'],
            ['type' => 'packagist.org', 'url' => 'https://repo.packagist.org'],
        ],
    );

    expect($staged['repositories'][0]['type'])->toBe('vcs')
        ->and($staged['repositories'][1]['type'])->toBe('vcs')
        ->and($staged['repositories'][1]['url'])->toBe(VCS_URLS['markdown-presentation'])
        // The one that was not a path stays exactly as it was.
        ->and($staged['repositories'][2])->toBe(['type' => 'packagist.org', 'url' => 'https://repo.packagist.org'])
        ->and($staged['require']['markdown-office/markdown-presentation'])->toBe(VCS_BRANCH_CONSTRAINT);
});

it('refuses a path repository it has no URL for, rather than staging one that cannot resolve', function () {
    buildScript();

    $root = stageDirectory('build-unknown');
    $deps = stageDirectory('build-unknown-deps');

    file_put_contents($root . '/composer.json', (string) json_encode([
        'require' => ['acme/thing' => '*'],
        'repositories' => [['type' => 'path', 'url' => '../acme-thing']],
    ]));

    // Loud, and before anything is written: a manifest with a path URL still in it
    // would fail much later, in Composer, with nothing about this decision in it.
    expect(fn () => stageManifest($root, $deps))
        ->toThrow(RuntimeException::class, 'there is no URL to resolve it from instead');

    expect(is_file($deps . '/composer.json'))->toBeFalse();
});

it('refuses when a directory name matches two requirements', function () {
    buildScript();

    // Ambiguous by accident — a vendor with the same package name under two of its
    // own — would mean the rewrite lands on one of them at random.
    expect(fn () => packageNamed(
        ['acme/markdown-word' => '*', 'other/markdown-word' => '*'],
        'markdown-word',
    ))->toThrow(RuntimeException::class, 'the build cannot tell which one to rewrite');
});

it('leaves a requirement alone when nothing names the directory', function () {
    buildScript();

    // Null rather than a guess, and a real case rather than a contrived one: a
    // `packagist.org` repository is a directory to nothing, and only the path kind is
    // ever rewritten — so this is what the caller sees for every repository it skips.
    expect(packageNamed(
        ['markdown-office/markdown-word' => '*', 'markdown-office/commonmark-generic-directives' => '^0.2'],
        'packagist',
    ))->toBeNull()
        // And a requirement the directory is named after does match, which is the whole
        // of how the two are put back together.
        ->and(packageNamed(['markdown-office/markdown-word' => '*'], 'markdown-word'))
        ->toBe('markdown-office/markdown-word');
});

it('writes the staged manifest as JSON a person can read', function () {
    buildScript();

    $root = stageDirectory('build-pretty');
    $deps = stageDirectory('build-pretty-deps');

    file_put_contents($root . '/composer.json', (string) json_encode([
        'require' => ['markdown-office/markdown-word' => '*'],
        'repositories' => [['type' => 'path', 'url' => '../markdown-word']],
    ]));

    stageManifest($root, $deps);
    $written = (string) file_get_contents($deps . '/composer.json');

    // Unescaped slashes: a manifest full of `https:\/\/github.com\/…` is unreadable,
    // and this is the file a person opens when a resolve goes wrong.
    expect($written)->toContain('https://github.com/markdown-office/markdown-word')
        ->and($written)->not->toContain('\\/');

    clearstatcache();
    expect(fileperms($deps . '/composer.json') & 0o777)->toBe(0o644);
});

// The staging filter

it('drops the files a machine leaves behind, at any depth', function (string $relative) {
    expect(isStray($relative))->toBeTrue();
})->with([
    '.DS_Store',
    'src/.DS_Store',
    'src/Console/Word/.DS_Store',
    'vendor/phpoffice/phpword/.DS_Store',
    'Thumbs.db',
    'desktop.ini',
    // The one a zip tool writes under a name of its own choosing, which is why
    // `STRAY_DIRECTORIES` is a separate list: nothing about the file says where it
    // belongs. Only nested — a bare `__MACOSX` is a *directory*, and this helper sees
    // one segment and so treats it as a file name, which the build reaches through a
    // separate directory pass.
    '__MACOSX/markdown-word/._Format.php',
]);

it('catches the names Finder and Explorer actually write', function () {
    // Not the ones this list has by accident, but the ones a developer's machine puts
    // in a working copy — which `cp -R` copies verbatim, and which would then make the
    // archive a function of whose machine ran the build.
    expect(STRAY_FILES)
        ->toContain('.DS_Store')
        ->toContain('Thumbs.db')
        ->toContain('desktop.ini')
        ->and(STRAY_DIRECTORIES)->toContain('__MACOSX');
});

it('keeps every source file the archive is built from', function (string $relative) {
    expect(isStray($relative))->toBeFalse();
})->with([
    'src/Console/Application.php',
    'src/Console/Word/WordApplication.php',
    'bin/mdoffice',
    'tools/build-phar.php',
    'vendor/markdown-office/markdown-word/src/Format.php',
    // A name that merely starts like a stray's, which a prefix match would take.
    'src/Console/DocumentStore.php',
    'src/Console/DesktopIni.php',
]);

it('does not read a stray into the timestamp the build stamps entries with', function () {
    // The archive's modification time is the newest of the *authored* sources, so that
    // a checkout nothing has touched since builds the same bytes twice. A `.DS_Store`
    // Finder rewrote this morning is newer than every source, and taking it would make
    // every build a function of when the last directory listing happened.
    $root = Scratch::path('epoch-root');
    mkdir($root . '/src', 0o777, true);
    mkdir($root . '/bin', 0o777, true);

    file_put_contents($root . '/src/Real.php', "<?php\n");
    file_put_contents($root . '/src/.DS_Store', 'finder');
    file_put_contents($root . '/composer.json', '{}');

    $old = strtotime('2020-01-01');
    touch($root . '/src/Real.php', $old);
    touch($root . '/composer.json', $old);
    // The stray is the newest thing in the tree, by twenty years.
    touch($root . '/src/.DS_Store', $old + 20 * 365 * 24 * 3600);

    clearstatcache();

    expect(resolveEpoch($root))->toBe($old);
});

it('does not count a stray as a source the archive is built from', function () {
    // The other half of the same thing: a stray in the list of inputs would be one
    // more path `filemtime()` is asked about, and on a machine where Finder has just
    // rewritten one, the answer changes between two builds of one commit.
    $root = Scratch::path('epoch-sources');
    mkdir($root . '/src', 0o777, true);

    file_put_contents($root . '/src/Real.php', "<?php\n");
    file_put_contents($root . '/src/.DS_Store', 'finder');

    $old = strtotime('2020-01-01');
    touch($root . '/src/Real.php', $old);
    touch($root . '/src/.DS_Store', $old + 20 * 365 * 24 * 3600);

    clearstatcache();

    expect(resolveEpoch($root))->toBe($old);
});

it('does not fall over a directory the project does not have', function () {
    // `src`, `bin`, `tools` and `examples` are listed, and one of them is absent from
    // a checkout more often than you would think — `examples/` was, when this
    // repository had none yet. `RecursiveDirectoryIterator` on a path that does not
    // exist is a warning and an empty iterator, and the build carries on with an epoch
    // of `time()` that makes every archive unique.
    $root = Scratch::path('epoch-missing');
    mkdir($root . '/src', 0o777, true);

    $when = strtotime('2021-06-01');
    file_put_contents($root . '/src/Real.php', "<?php\n");
    touch($root . '/src/Real.php', $when);
    touch($root . '/composer.json', $when);

    clearstatcache();

    expect(resolveEpoch($root))->toBe($when);
});

it('stamps the archive with the commit date when the environment gives one', function () {
    // The reproducible-builds convention, and what a release should use: the tag's own
    // date is the most meaningful instant an artifact can carry. Checked through the
    // environment rather than by calling anything with an argument, because the
    // environment is where the value actually arrives from.
    buildScript();

    $epoch = 1234567890;

    putenv('SOURCE_DATE_EPOCH=' . $epoch);

    try {
        expect(resolveEpoch(Scratch::path('epoch-env')))->toBe($epoch);
    } finally {
        putenv('SOURCE_DATE_EPOCH');
    }
});

it('refuses a SOURCE_DATE_EPOCH that is not a whole number, before writing anything', function () {
    // Silently falling back to "now" would give an archive whose timestamp is the
    // moment the build was run — which looks like a successful build and is not a
    // reproducible one.
    buildScript();

    putenv('SOURCE_DATE_EPOCH=tuesday');

    try {
        expect(fn () => resolveEpoch(Scratch::path('epoch-bad')))
            ->toThrow(RuntimeException::class, 'has to be a whole number of seconds');
    } finally {
        putenv('SOURCE_DATE_EPOCH');
    }
});