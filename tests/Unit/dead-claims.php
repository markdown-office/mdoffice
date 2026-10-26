<?php

declare(strict_types=1);

/**
 * Comments here are claims, and a false one costs more than no comment: a reader
 * who trusts "reverse order puts a directory ahead of its children" will not look
 * again, and will be wrong.
 *
 * Three were found and corrected in one pass over the build script. Each was a
 * sentence about code that did something else, or named a file this repository
 * does not have. The phrases are listed below so that a corrected claim cannot
 * come back unnoticed.
 *
 * The walk strips comment markers and collapses whitespace first, because a `//`
 * comment reflows in an editor and a raw substring match would find nothing in a
 * rewrapped line — the guard would pass against the very words it forbids. The
 * match is case-insensitive for the same reason: a phrase beginning `a` is
 * `A` whenever it starts its sentence, and one case is enough to miss it.
 *
 * @return list<string>
 */
function sourcesWithClaim(string $claim): array
{
    $found = [];
    $root = dirname(__DIR__, 2);
    $areas = ['src', 'tests', 'tools'];
    $documents = ['README.md'];

    foreach ($areas as $area) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root . '/' . $area, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php' || !$file->isFile()) {
                continue;
            }

            // This file names every phrase it looks for, so it matches itself.
            if ($file->getFilename() === basename(__FILE__)) {
                continue;
            }

            $source = spelledOut((string) file_get_contents($file->getPathname()));

            if (stripos($source, $claim) !== false) {
                $found[] = $area . '/' . $file->getFilename();
            }
        }
    }

    // A false claim about what the archive holds is one a user reads and acts on,
    // and the README is where that claim would be acted on.
    foreach ($documents as $document) {
        $path = $root . '/' . $document;

        if (is_file($path) && stripos(spelledOut((string) file_get_contents($path)), $claim) !== false) {
            $found[] = $document;
        }
    }

    return $found;
}

function spelledOut(string $source): string
{
    $lines = [];

    foreach (preg_split('/\R/', $source) ?: [] as $line) {
        $lines[] = trim((string) preg_replace('~^\s*(?://+|\*+|\#+)\s?~', '', $line));
    }

    return (string) preg_replace('/\s+/', ' ', implode(' ', $lines));
}

it('does not claim again what the code does not do', function (string $claim) {
    expect(sourcesWithClaim($claim))->toBe([]);
})->with([
    'a workflow this repository does not have compares the checksums' => 'phar.yml',
    'the library versions are stamped into the archive metadata' => 'written into its metadata',
    'reversing the order puts a directory ahead of its children' => 'Reverse order puts a directory',
    'the help is read back by the test that owns the option table' => 'tests/Unit/application.php now reads every option',
    'a test holds a version constant to the changelog' => 'a test holds the constant to its',
    'the two sibling libraries are taken at two different versions' => '`0.2.x-dev` and `0.1.x-dev`',
    'the Linux executable is packed by the same tool as the Windows one' => 'UPX compresses the Linux and Windows ones',
    'the Windows executable is packed by UPX' => 'packs the Windows executable',
    'every executable is compiled by the same tool' => 'built by `static-php-cli` and joined to the archive',
    'the presentation library is taken at an older series than the word one' => '`^0.2` and `^0.1`',
    'an archive holds one library at one version and the other at another' => 'holds `v0.2.0` and `v0.1.0`',
    'a released archive holds whatever was on a branch' => 'is whatever `dev-main` of each',
    'a consumer has to relax the stability the install does not need' => 'installing it needs `minimum-stability: dev`',
    'there are three platforms and no more' => 'A macOS arm64, a Linux x86-64 and a Windows x64',
    'the consumer is told to require a series this release does not carry' => 'markdown-office/mdoffice": "^0.2',
]);

it('does not tell a reader the package is not on Packagist, because it is', function () {
    // The install section is where a reader looks first, and the Composer install
    // it describes resolves now that the package is published. A sentence that
    // says otherwise sends a reader looking for a problem that is not there.
    //
    // The package was not on Packagist when the first release was prepared, and
    // this test is the question "is it published now?". It is. When it is not
    // any more — a name that is taken, a package that is withdrawn — this test
    // and the sentence in the README change back together.
    $readme = (string) file_get_contents(dirname(__DIR__, 2) . '/README.md');

    expect($readme)->not->toContain('is not on Packagist');
});

it('tells a reader that the library reads a deck back, because it does', function () {
    // The reverse direction is a thing the command line does and the library does,
    // and a README that describes only half of it sends a reader looking for a
    // command that is not there.
    $readme = (string) file_get_contents(dirname(__DIR__, 2) . '/README.md');

    expect($readme)->toContain('and a deck back to Markdown');
});

it('tells a consumer to allow the patch plugin only while something needs it', function () {
    // The README asks the consumer to allow `cweagans/composer-patches` because
    // `markdown-presentation` applies a patch to the PHPPresentation it installs,
    // and Composer only runs a plugin the project allows. When that changes, the
    // instruction changes with it: a stale one asks every consumer to allow a
    // plugin for nothing.
    //
    // The manifest read is the installed one, which is what a consumer gets.
    $readme = (string) file_get_contents(dirname(__DIR__, 2) . '/README.md');
    $installed = dirname(__DIR__, 2) . '/vendor/markdown-office/markdown-presentation/composer.json';

    $needsIt = false;

    if (is_file($installed)) {
        $presentation = json_decode(
            (string) file_get_contents($installed),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $needsIt = isset($presentation['extra']['patches']);
    }

    expect(str_contains($readme, '"cweagans/composer-patches": true'))->toBe($needsIt);
});

it('asks a consumer for no stability the install does not need', function () {
    // Every dependency is a stable release, so a consumer who reads the README and
    // relaxes `minimum-stability` has been asked for something that costs them a
    // guarantee and buys them nothing.
    $readme = (string) file_get_contents(dirname(__DIR__, 2) . '/README.md');
    $installed = dirname(__DIR__, 2) . '/vendor/markdown-office/markdown-presentation/composer.json';

    $needsIt = false;

    if (is_file($installed)) {
        $presentation = json_decode(
            (string) file_get_contents($installed),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $needsIt = str_contains(
            (string) ($presentation['require']['phpoffice/phppresentation'] ?? ''),
            'dev-',
        );
    }

    expect(str_contains($readme, 'minimum-stability'))->toBe($needsIt);
});

