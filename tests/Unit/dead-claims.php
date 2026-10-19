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
]);

