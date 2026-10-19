<?php

declare(strict_types=1);

/*
 * Imports nothing uses.
 *
 * There is no style linter in this repository, and PHP does not warn about an unused
 * import: the name is simply bound and never read. That is cheap to leave behind and
 * it costs more than a smell, because a reader who sees `use MarkdownWord\WordPackage;`
 * in a file that never mentions the class again will look for the reason it is there
 * and find none — which is exactly what happened once already.
 *
 * A `use` is matched by the short name it binds, which is how a reference in the file
 * resolves. Anything else — a fully-qualified name spelled out at the point of use — is
 * deliberately not counted: it does not use the import, so the import really is
 * unused, and the file is better off with one spelling than two.
 */

/**
 * The `use` statements in a PHP source file, as `short name => line number`.
 *
 * @return array<string, int>
 */
function importsIn(string $source): array
{
    $imports = [];

    foreach (preg_split('/\R/', $source) ?: [] as $number => $line) {
        if (preg_match('/^use\s+(?:function\s+|const\s+)?([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)\s*(?:as\s+([A-Za-z_][A-Za-z0-9_]*))?\s*;/', $line, $match) !== 1) {
            continue;
        }

        // `as` renames the binding, so where there is one that is the name the rest
        // of the file refers to. `!==` binds tighter than `??`, which is why the
        // comparison is parenthesised: without them this is `$match[2] ?? false`
        // and every import in the file lands on one key.
        $aliased = ($match[2] ?? '') !== '';

        $imports[$aliased ? $match[2] : lastSegment($match[1])] = $number + 1;
    }

    return $imports;
}

/**
 * The last segment of a qualified name, which is the short name an unqualified
 * reference to it resolves through.
 */
function lastSegment(string $qualified): string
{
    $parts = explode('\\', $qualified);

    return (string) end($parts);
}

/**
 * Imports that nothing in the file refers to.
 *
 * @return list<string>
 */
function unusedImports(string $source): array
{
    // The use statements are removed before the body is searched, so an import can
    // never satisfy its own reference.
    $withoutImports = preg_replace('/^use\s+[^;]+;$/m', '', $source) ?? $source;

    $unused = [];

    foreach (importsIn($source) as $short => $line) {
        if (preg_match('/(?<![A-Za-z0-9_$\\\\])' . preg_quote($short, '/') . '(?![A-Za-z0-9_])/', $withoutImports) === 1) {
            continue;
        }

        $unused[] = sprintf('line %d: %s', $line, $short);
    }

    return $unused;
}

/**
 * Every PHP file under `src/`.
 *
 * @return list<string>
 */
function srcFiles(): array
{
    $found = [];

    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/src', FilesystemIterator::SKIP_DOTS),
    ) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $found[] = (string) $file->getPathname();
        }
    }

    sort($found);

    return $found;
}

it('imports nothing the file does not use', function (string $file) {
    $unused = unusedImports((string) file_get_contents($file));

    expect($unused)->toBe([], sprintf(
        "%s imports %s and never uses %s.\nRemove the import, or use the short name rather than"
        . ' spelling the class out where it is used.',
        substr($file, strlen(dirname(__DIR__, 2)) + 1),
        count($unused) === 1 ? 'a name' : 'names',
        implode(', ', $unused),
    ));
})->with(static fn (): array => array_map(
    static fn (string $file): string => $file,
    srcFiles(),
));

it('sees an import it is meant to see', function () {
    // The guard above is only worth anything if the scanner finds imports at all. A
    // pattern that stopped matching would report zero unused imports for the whole
    // repository and pass, which is the failure mode a test like this one exists to
    // prevent.
    $used = "<?php\n\nuse MarkdownWord\\Format;\nuse MarkdownWord\\WordToMarkdown;\n\n"
        . "final class Example\n{\n    public function run(Format \$a): WordToMarkdown\n    {\n"
        . "        return new WordToMarkdown();\n    }\n}\n";

    expect(array_keys(importsIn($used)))->toBe(['Format', 'WordToMarkdown'])
        ->and(unusedImports($used))->toBe([]);

    // And with the reference taken away while the import stays — written out rather
    // than derived from the first, because replacing the name would rewrite the use
    // line too and leave nothing to find.
    $dangling = "<?php\n\nuse MarkdownWord\\Format;\nuse MarkdownWord\\WordToMarkdown;\n\n"
        . "final class Example\n{\n    public function run(Format \$a): Format\n    {\n"
        . "        return new Format();\n    }\n}\n";

    expect(unusedImports($dangling))->toBe(['line 4: WordToMarkdown']);

    // An `as` binds a different name, and that is the name the body must use.
    $aliased = "<?php\n\nuse MarkdownWord\\Reverse\\Options as ReverseOptions;\n\n"
        . "final class Example\n{\n    public function run(ReverseOptions \$o): void\n    {\n    }\n}\n";

    expect(array_keys(importsIn($aliased)))->toBe(['ReverseOptions'])
        ->and(unusedImports($aliased))->toBe([]);
});
