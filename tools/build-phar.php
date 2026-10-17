#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Builds `build/mdoffice.phar` — a single file that converts Markdown to Word and
 * to PowerPoint, on a machine with nothing but PHP.
 *
 * ```sh
 * composer build:phar
 * ```
 *
 * The dependencies are installed into a directory of their own rather than into
 * the project, because `composer install --no-dev` in the project would delete
 * the test tools from `vendor/` and the next `composer test` would fail. Their
 * own test suites, documentation and analysis configuration are pruned out of
 * the archive.
 *
 * Two builds of one source tree on one runner, PHP and zlib produce the same
 * bytes, and `phar.yml` compares the checksums to keep it that way. Two
 * different PHP builds are not promised: the compressor has a say in that too.
 * What the script controls is in `resolveEpoch()` and `normaliseTimestamps()` —
 * the short version is that the Phar extension stamps every entry with the time
 * the archive was written and offers no way to say otherwise, so the timestamps
 * are rewritten after.
 *
 * `phar.readonly` is `On` in most PHP installations, which is the right default
 * and cannot be changed at runtime. Rather than refuse, this script re-runs
 * itself with the setting turned off for the length of the build, so that one
 * command works everywhere.
 */

use MarkdownOffice\Console\Application;

/**
 * Define this before `require`ing this file to load its definitions without running
 * the build.
 *
 * The only reason it exists is that a test can then reach {@see stageManifest()} and
 * {@see prune()} — which have behaviour worth asserting — without running the whole
 * build, which stages a tree, installs a second copy of the dependencies and writes
 * an archive to check.
 */
const BUILD_PHAR_INCLUDED = 'MDOFFICE_BUILD_PHAR_INCLUDED';

if (ini_get('phar.readonly') && !defined(BUILD_PHAR_INCLUDED)) {
    $command = sprintf(
        '%s -d phar.readonly=0 %s %s',
        escapeshellarg(PHP_BINARY),
        escapeshellarg(__FILE__),
        implode(' ', array_map('escapeshellarg', array_slice($argv ?? [], 1))),
    );

    passthru($command, $status);

    exit($status);
}

// The name and the version of the tool come from the class it drives, so the
// project's own autoloader has to be loadable; saying so plainly beats the
// fatal error about a missing file this used to produce on a fresh CI runner.
if (!is_file($autoloader = __DIR__ . '/../vendor/autoload.php')) {
    fwrite(STDERR, "build:phar needs the project's dependencies for its autoloader.\n"
        . "Run `composer install` first, or `composer install --no-dev` when you\n"
        . "only want the runtime ones.\n\n"
        . "Nothing has been written.\n");

    exit(1);
}

require $autoloader;

function confirm(string $question): bool
{
    if (!stream_isatty(STDIN)) {
        return true;
    }

    fwrite(STDOUT, $question . ' [Y/n] ');

    $answer = strtolower(trim((string) fgets(STDIN)));

    return $answer === '' || $answer === 'y' || $answer === 'yes';
}

function run(string $command, string $what): void
{
    fwrite(STDOUT, sprintf('  %-44s', $what));

    $output = [];
    $status = 0;

    // Standard input is closed, as it is in `capture()`: a child that inherits
    // this one waits on a terminal that is not there.
    exec($command . ' 2>&1 < /dev/null', $output, $status);

    if ($status !== 0) {
        fwrite(STDOUT, "failed\n");
        fwrite(STDERR, implode(PHP_EOL, $output) . PHP_EOL);

        exit($status);
    }

    fwrite(STDOUT, "ok\n");
}

/**
 * Run a command for its output.
 *
 * An inherited standard input is how a build that should have taken moments ends
 * up waiting on a terminal that is not there: the reading end has no writer and the
 * child never sees end of file. Every way of shelling out in this script closes it
 * for the same reason.
 */
function capture(string $command): string
{
    return (string) shell_exec($command . ' < /dev/null');
}

function humanSize(int $bytes): string
{
    return $bytes < 1024
        ? $bytes . ' B'
        : sprintf('%.1f KiB', $bytes / 1024);
}

function copyInto(string $from, string $to): void
{
    run(
        sprintf('cp -R %s %s', escapeshellarg($from), escapeshellarg($to)),
        'copying ' . basename($from) . '/',
    );
}

/**
 * Every package installs a test suite, documentation and CI configuration of its
 * own, and none of it is code the archive loads — `phpoffice/math` is in this
 * tree and the archive cannot write a document without it. Matched on the
 * directory's own name, at any depth below `vendor/`.
 */
const PRUNED_DIRECTORIES = [
    '.git',
    '.github',
    'doc',
    'docs',
    'test',
    'tests',
];

/**
 * Matched on a file's own name, at any depth below `vendor/`, as patterns because
 * the packages are not ours.
 */
const PRUNED_FILES = [
    'phpstan*',
    'psalm*',
    'phpunit*',
    '*.dist',
    '.editorconfig',
    '.gitattributes',
    '.gitignore',
    '.gitmodules',
    '.travis.yml',
    'mkdocs.yml',
    'roave-bc-check.yaml',
];

/**
 * Files that match `PRUNED_FILES` and are loaded at runtime anyway.
 *
 * `PhpWord\Settings::loadConfig()` with no argument falls back to
 * `phpword.ini.dist` in the package root, and a file that is gone is a file whose
 * settings silently stop applying: pruning it would be a behavioural change
 * dressed up as a size saving.
 */
const PRUNED_FILE_EXCEPTIONS = [
    // Matched against the path relative to the vendor directory, not the name.
    'phpoffice/phpword/phpword.ini.dist',
];

/**
 * Matched on a file's own name, at any depth below the staged `app/` tree.
 *
 * These are the ones a developer's machine leaves in a working copy and `cp -R`
 * copies verbatim, so the archive depends on whose machine built it: `.DS_Store`
 * records the directory's contents and Finder rewrites it as they change, and a
 * zip is only reproducible while its members are. None of them is ours, so
 * nothing can depend on one being there.
 */
const STRAY_FILES = [
    '.DS_Store',
    'Thumbs.db',
    'desktop.ini',
];

/**
 * Matched on a directory's own name, at any depth below the staged `app/` tree.
 *
 * Separate from {@see STRAY_FILES} because a name list cannot reach them: what a
 * zip tool writes into `__MACOSX` is a copy of each file under a name of its own
 * choosing, so nothing about the file says where it belongs.
 */
const STRAY_DIRECTORIES = [
    '__MACOSX',
];

/**
 * Where each library is fetched from when the manifest's `path` repositories are
 * rewritten for the staged install, keyed by the directory name it was written as.
 *
 * A sibling checkout's name is a local convenience; this is the one that resolves
 * from any directory, which is what a build has to work from.
 */
const VCS_URLS = [
    'markdown-word' => 'https://github.com/markdown-office/markdown-word',
    'markdown-presentation' => 'https://github.com/markdown-office/markdown-presentation',
];

/**
 * The constraint a rewritten library is asked for by.
 *
 * The branch rather than `*`, because the tags both libraries carry were cut under
 * their previous names and before most of what they do now, and a stable-preferring
 * resolve takes the tag in preference to the branch. See the rewrite itself.
 */
const VCS_BRANCH_CONSTRAINT = 'dev-main';


/**
 * The requirement a path repository's directory name refers to, or null when none
 * does.
 *
 * Matched on the last segment of the requirement's name, because that is the part a
 * checkout directory is named after: `../markdown-word` is
 * `markdown-office/markdown-word`. Null rather than an error when nothing matches,
 * because a repository may legitimately name something that is not a requirement —
 * a `packagist.org` entry for instance — and only the path kind reaches here.
 *
 * Ambiguous by accident is refused rather than guessed at: two requirements whose last
 * segments are both `markdown-word` would mean the rewrite is about to land on one of
 * them at random.
 *
 * @param array<string, string> $require
 */
function packageNamed(array $require, string $directory): ?string
{
    $matches = array_values(array_filter(
        array_keys($require),
        static fn (string $name): bool => str_ends_with($name, '/' . $directory) || $name === $directory,
    ));

    if (count($matches) > 1) {
        throw new RuntimeException(sprintf(
            'the directory name "%s" matches %d requirements (%s), and the build cannot tell which one to rewrite',
            $directory,
            count($matches),
            implode(', ', $matches),
        ));
    }

    return $matches[0] ?? null;
}

/**
 * Reports what it removed rather than what it was asked to remove, because a
 * pattern that quietly matched something a package needed at runtime would
 * otherwise show up as a broken archive — or, worse, not at all.
 *
 * @return array{count: int, bytes: int, paths: list<string>, directories: list<string>}
 */
function prune(string $vendor): array
{
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($vendor, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );

    $pruned = ['count' => 0, 'bytes' => 0, 'paths' => [], 'directories' => []];
    $directories = [];

    foreach ($files as $file) {
        if (!$file instanceof SplFileInfo) {
            continue;
        }

        $path = (string) $file->getPathname();
        $relative = substr($path, strlen($vendor) + 1);

        if ($file->isDir()) {
            if (in_array(strtolower($file->getFilename()), PRUNED_DIRECTORIES, true)) {
                $directories[] = $relative;
            }

            continue;
        }

        $name = $file->getFilename();

        if (in_array($relative, PRUNED_FILE_EXCEPTIONS, true)) {
            continue;
        }

        $matched = false;

        foreach (PRUNED_FILES as $pattern) {
            if (fnmatch($pattern, $name)) {
                $matched = true;

                break;
            }
        }

        if (!$matched) {
            continue;
        }

        $pruned['count']++;
        $pruned['bytes'] += (int) $file->getSize();
        $pruned['paths'][] = $relative;

        unlink($path);
    }

    // Reverse order puts a directory ahead of the ones nested inside it, so a
    // nested one is already gone when it is reached: the guard below skips it, and
    // its files are counted with the parent that took them.
    rsort($directories, SORT_STRING);

    foreach ($directories as $relative) {
        $path = $vendor . '/' . $relative;

        if (is_dir($path)) {
            $inside = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            );

            foreach ($inside as $file) {
                if ($file instanceof SplFileInfo && $file->isFile()) {
                    $pruned['count']++;
                    $pruned['bytes'] += (int) $file->getSize();
                    $pruned['paths'][] = substr($file->getPathname(), strlen($vendor) + 1);
                }
            }

            $pruned['directories'][] = $relative;

            exec(sprintf('rm -rf %s', escapeshellarg($path)));
        }
    }

    sort($pruned['paths'], SORT_STRING);
    sort($pruned['directories'], SORT_STRING);

    return $pruned;
}

/**
 * The instant every part of the archive is stamped with.
 *
 * `SOURCE_DATE_EPOCH` wins when it is set, which is the reproducible builds
 * convention and what a release should do: the tag's own commit date is the most
 * meaningful timestamp an artifact can carry. Without it the newest modification
 * time of the *authored* sources is used, so a checkout nothing has touched since
 * builds the same bytes twice.
 *
 * `vendor/` is deliberately not consulted. Composer extracted those files a
 * moment ago, so their times say when this machine ran rather than when the code
 * was written, and a fresh install would give every entry a new stamp.
 */
function resolveEpoch(string $root): int
{
    $fromEnvironment = getenv('SOURCE_DATE_EPOCH');

    if ($fromEnvironment !== false && trim($fromEnvironment) !== '') {
        $fromEnvironment = trim($fromEnvironment);

        if (!ctype_digit($fromEnvironment)) {
            // Thrown rather than exited from. `exit()` in a helper cannot be caught,
            // so the one behaviour here worth asserting — that a bad value is refused
            // rather than quietly replaced with the moment the build ran — could only
            // be checked by running the whole build in a subprocess and reading what
            // it printed.
            throw new RuntimeException(sprintf(
                'SOURCE_DATE_EPOCH has to be a whole number of seconds since 1970, and %s is not.',
                var_export($fromEnvironment, true),
            ));
        }

        return (int) $fromEnvironment;
    }

    $inputs = ['composer.json', 'composer.lock', 'README.md', 'LICENSE', 'tools/build-phar.php'];

    foreach (['src', 'bin', 'tools', 'examples'] as $directory) {
        if (!is_dir($root . '/' . $directory)) {
            continue;
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if (!$file instanceof SplFileInfo) {
                continue;
            }

            $stray = in_array($file->getFilename(), STRAY_FILES, true)
                || ($file->isDir() && in_array($file->getFilename(), STRAY_DIRECTORIES, true));

            if ($file->isFile() && !$stray) {
                $inputs[] = substr((string) $file->getPathname(), strlen($root) + 1);
            }
        }
    }

    $newest = 0;

    foreach ($inputs as $input) {
        if (is_file($root . '/' . $input)) {
            $newest = max($newest, (int) filemtime($root . '/' . $input));
        }
    }

    return $newest === 0 ? time() : $newest;
}

function readUint32(string $raw, int &$offset): int
{
    $value = unpack('V', substr($raw, $offset, 4));
    $offset += 4;

    if ($value === false) {
        throw new RuntimeException('the archive is truncated');
    }

    return $value[1];
}

function readUint16(string $raw, int &$offset): int
{
    $value = unpack('v', substr($raw, $offset, 2));
    $offset += 2;

    if ($value === false) {
        throw new RuntimeException('the archive is truncated');
    }

    return $value[1];
}

/**
 * Where the archive's own data begins, just after the stub.
 *
 * `__HALT_COMPILER();` ends the stub and PHP writes a closing tag and a line
 * ending after it, but `setStub()` adds both for us and a stub written without
 * them is equally valid, so every combination is accepted.
 */
function dataStart(string $raw): int
{
    $halt = strpos($raw, '__HALT_COMPILER();');

    if ($halt === false) {
        throw new RuntimeException('the archive has no stub');
    }

    $offset = $halt + strlen('__HALT_COMPILER();');

    if (str_starts_with(substr($raw, $offset), ' ?>') || str_starts_with(substr($raw, $offset), '?>')) {
        $offset += str_starts_with(substr($raw, $offset), ' ?>') ? 3 : 2;
    }

    if (substr($raw, $offset, 2) === "\r\n") {
        return $offset + 2;
    }

    return substr($raw, $offset, 1) === "\n" ? $offset + 1 : $offset;
}

/**
 * The offset of every entry's timestamp, and the end of the manifest.
 *
 * The manifest comes before the file contents, which follow it in manifest
 * order, and its first four bytes are the length of the rest of it. So the length
 * is both how the end is found and the check that the walk below read it
 * correctly: a length the entries do not account for means this PHP wrote a
 * layout this does not understand, and the build stops rather than guess.
 *
 * @return array{timestamps: list<array{offset: int, name: string}>, end: int, metadata: string}
 */
function readManifest(string $raw): array
{
    $offset = dataStart($raw);
    $length = readUint32($raw, $offset);
    $start = $offset;
    $end = $offset + $length;

    $count = readUint32($raw, $offset);
    readUint16($raw, $offset); // API version
    readUint32($raw, $offset); // global flags
    $offset += readUint32($raw, $offset); // alias
    $metadata = substr($raw, $offset, readUint32($raw, $offset));
    $offset += strlen($metadata);

    $timestamps = [];

    for ($entry = 0; $entry < $count; $entry++) {
        $nameLength = readUint32($raw, $offset);
        $name = substr($raw, $offset, $nameLength);
        $offset += $nameLength;
        readUint32($raw, $offset); // uncompressed size
        $timestamps[] = ['offset' => $offset, 'name' => $name];
        readUint32($raw, $offset); // timestamp
        readUint32($raw, $offset); // compressed size
        readUint32($raw, $offset); // CRC32
        readUint32($raw, $offset); // flags
        $offset += readUint32($raw, $offset); // per-file metadata
    }

    $walked = $offset - $start;

    // PHP pads the manifest with one byte, and counts that byte in the length it
    // wrote, whenever that length ends in `\r` or `\n` — a reader that took either
    // for the end of a manifest string would stop early (php-src bug #65028,
    // `phar_flush_ex()`). The byte it pads with is the first byte of the buffer
    // the metadata length was written from, which nothing writes over again
    // before the padding goes out, so it is the low byte of that length. The byte
    // is checked as well as the arithmetic because a spare byte at the end is also
    // what a walk that read the layout wrongly would leave behind, and this is
    // the one place that tells the two apart.
    $padding = in_array($walked & 0xFF, [0x0A, 0x0D], true) ? chr(strlen($metadata) & 0xFF) : '';

    if ($walked + strlen($padding) !== $length || substr($raw, $offset, strlen($padding)) !== $padding) {
        throw new RuntimeException(sprintf(
            'the manifest says it is %d bytes and reading it consumed %d, so this build does not'
            . ' recognise the archive layout this PHP wrote and will not rewrite it',
            $length,
            $walked,
        ));
    }

    return ['timestamps' => $timestamps, 'end' => $end, 'metadata' => $metadata];
}

/**
 * The hash algorithm, and the offset of the signature, for a written archive.
 *
 * The signature covers everything before it — stub, manifest and file contents —
 * and is followed by the literal `GBMB`, the last four bytes of the file. The
 * arithmetic is checked against the signature PHP itself read back, so a layout
 * that does not add up is refused instead of rewritten.
 *
 * @return array{algorithm: string, offset: int, length: int}
 */
function locateSignature(string $raw, Phar $phar): array
{
    $signature = $phar->getSignature();
    $algorithms = [
        'MD5' => 'md5',
        'SHA-1' => 'sha1',
        'SHA-256' => 'sha256',
        'SHA-512' => 'sha512',
    ];
    // The key is present for every signature Phar reports; the branch below is for a
    // signature *type* this build has no rewriting rule for, which is a different
    // thing from there being no key at all.
    $type = $signature['hash_type'];

    if (!isset($algorithms[$type])) {
        throw new RuntimeException(sprintf(
            'the archive is signed with %s, which this build does not know how to rewrite',
            $type === '' ? 'nothing' : $type,
        ));
    }

    $algorithm = $algorithms[$type];

    if (substr($raw, -4) !== 'GBMB') {
        throw new RuntimeException('the archive does not end in the marker it should');
    }

    $digestLength = strlen((string) hex2bin((string) $signature['hash']));

    // The digest, then four bytes naming the algorithm. There is no length in
    // front of them, which is why the offset is derived from the end instead.
    $length = $digestLength + 4;
    $offset = strlen($raw) - 4 - $length;

    if (hash($algorithm, substr($raw, 0, $offset)) !== strtolower((string) $signature['hash'])) {
        throw new RuntimeException(sprintf(
            'the signature does not cover the bytes before it, so this build does not recognise the'
            . ' archive layout this PHP wrote and will not rewrite it',
        ));
    }

    return ['algorithm' => $algorithm, 'offset' => $offset, 'length' => $length];
}

/**
 * Give every entry the same, chosen modification time.
 *
 * `Phar::addFile()` records `time()` as the archive is flushed — not the file's
 * own modification time, and not anything that can be passed in.
 * `PharFileInfo::setMTime()` no longer exists, a custom `SplFileInfo` handed to
 * `buildFromIterator()` is ignored, and no released PHP consults
 * `SOURCE_DATE_EPOCH` (php-src#20570 will). So the timestamps are written into
 * the manifest directly and the signature is recalculated over the result.
 *
 * Every one of those is checked against the bytes that were written, and the
 * artifact is deleted if any of it does not hold: an archive this script
 * rewrote into something it merely believes is still a corrupt archive. The
 * version and round-trip checks below are what prove PHP itself can still read
 * it, and they run the archive in a process that has no cache of it.
 */
function normaliseTimestamps(string $target, int $epoch): void
{
    $raw = file_get_contents($target);

    if ($raw === false) {
        throw new RuntimeException('the archive could not be read back');
    }

    $manifest = readManifest($raw);
    $signature = locateSignature($raw, new Phar($target));
    $stamped = pack('V', $epoch);

    $rebuilt = $raw;

    foreach ($manifest['timestamps'] as $entry) {
        $rebuilt = substr_replace($rebuilt, $stamped, $entry['offset'], 4);
    }

    $digest = hash($signature['algorithm'], substr($rebuilt, 0, $signature['offset']), true);
    $rebuilt = substr_replace($rebuilt, $digest, $signature['offset'], $signature['length'] - 4);

    if (file_put_contents($target, $rebuilt) !== strlen($rebuilt)) {
        throw new RuntimeException('the rewritten archive could not be written in full');
    }

    // Read back off disk rather than through a second `Phar` object, which would
    // hand out the copy this process already has open and cache.
    $written = file_get_contents($target);

    if ($written === false || strlen($written) !== strlen($raw)) {
        throw new RuntimeException('the rewritten archive is not the length it should be');
    }

    $check = readManifest($written);

    if (count($check['timestamps']) !== count($manifest['timestamps'])) {
        throw new RuntimeException('the archive has a different number of entries than it did');
    }

    if ($check['metadata'] !== $manifest['metadata']) {
        throw new RuntimeException('rewriting the timestamps lost the archive metadata');
    }

    foreach ($check['timestamps'] as $entry) {
        if (substr($written, $entry['offset'], 4) !== $stamped) {
            throw new RuntimeException(sprintf(
                'rewriting the timestamp of %s did not take',
                $entry['name'],
            ));
        }
    }

    if (hash($signature['algorithm'], substr($written, 0, $signature['offset']), true) !== $digest) {
        throw new RuntimeException('the rewritten archive does not match its own signature');
    }
}

/**
 * Copy the manifest into the staging directory, with its repositories in a form
 * that resolves from there.
 *
 * Development resolves the two libraries through `path` repositories, which is
 * what makes a change in either of them visible to the CLI without a reinstall.
 * Neither survives being copied: a `path` URL is resolved against the directory
 * holding the manifest, so `../markdown-word` means `build/markdown-word` once the
 * manifest is staged, and a build that ran anywhere but beside the checkout would
 * find no siblings at all.
 *
 * So each one is rewritten to `vcs`, which resolves the same package from any
 * directory.
 */
function stageManifest(string $root, string $deps): void
{
    /** @var array{repositories?: mixed, require?: array<string, string>, ...} $manifest */
    $manifest = json_decode((string) file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
    $rewritten = 0;

    // Composer itself reads this file rather than this code, so the shape here is the
    // shape Composer accepts rather than the shape this build would like: an entry may
    // be a list of its own, a shorthand string, or an object, and only the `path` kind
    // reaches the rewrite below.
    $repositories = $manifest['repositories'] ?? [];

    if (!is_array($repositories)) {
        throw new RuntimeException('composer.json carries a "repositories" that is not a list.');
    }

    foreach ($repositories as $index => $repository) {
        if (!is_array($repository) || ($repository['type'] ?? null) !== 'path') {
            continue;
        }

        // Composer writes `repositories` back in the order it read them, so the index
        // is the position in the list rather than a name, and this is where it is
        // written back.
        $path = $repository['url'] ?? null;

        if (!is_string($path)) {
            throw new RuntimeException(sprintf('The path repository at index %d names no url.', $index));
        }

        $package = basename(rtrim($path, '/'));
        $url = VCS_URLS[$package] ?? null;

        if ($url === null) {
            throw new RuntimeException(sprintf(
                'the repository at ../%s is a path, and there is no URL to resolve it from instead.'
                . ' Add it to VCS_URLS, or drop it from composer.json',
                $package,
            ));
        }

        $repositories[$index] = ['type' => 'vcs', 'url' => $url];
        $rewritten++;

        // The requirement beside it, asked for by branch rather than left as `*`.
        //
        // This is not tidiness. `prefer-stable` is on, so a `*` resolves to the newest
        // *tag* — and `markdown-word` carries `v0.1.1`, cut before `Format` existed,
        // while `main` is months past it. The build then installs a library this tool
        // cannot run against, and the first sign of it is a class-not-found for
        // something the library has had for a long time.
        $name = packageNamed($manifest['require'] ?? [], $package);

        if ($name !== null) {
            $manifest['require'][$name] = VCS_BRANCH_CONSTRAINT;
        }
    }

    if ($rewritten > 0) {
        $manifest['repositories'] = $repositories;
    }

    file_put_contents(
        $deps . '/composer.json',
        json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL,
    );

    if ($rewritten > 0) {
        fwrite(STDOUT, sprintf(
            "  %-44s%d path repositories rewritten as vcs\n",
            'resolving the libraries by URL',
            $rewritten,
        ));
    }

    chmod($deps . '/composer.json', 0o644);
}

/**
 * Build the archive. Everything below this is the build itself.
 *
 * A function rather than the top level of the file, so that a test can reach the
 * helpers above it with `require` and stop before the work: the staging rewrite,
 * the pruning and the manifest all have behaviour worth asserting, and a test that
 * had to run the whole build to reach them would assert the build instead.
 */
function mdofficeBuild(): void
{
    $root = dirname(__DIR__);
    $build = $root . '/build';
    $deps = $build . '/deps';
    $app = $build . '/app';
    $target = $build . '/mdoffice.phar';

    // A `composer.phar` in the project root is preferred: that is the version
    // whoever installed the dependencies used, and the `composer` on the PATH is
    // whatever the machine happens to carry.
    $composerPhar = $root . '/composer.phar';

    $composer = is_file($composerPhar)
        ? escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($composerPhar)
        : trim(capture('command -v composer 2>/dev/null'));

    if ($composer === '') {
        fwrite(STDERR, "This build needs Composer: either a composer.phar in the project root,\n"
            . "or the `composer` command on your PATH.\n");

        exit(1);
    }

    // Resolved before anything is copied, so the stamp describes the sources rather
    // than the staging directory.
    try {
        $epoch = resolveEpoch($root);
    } catch (RuntimeException $problem) {
        fwrite(STDERR, $problem->getMessage() . "\n\nNothing has been written.\n");

        exit(1);
    }

    fwrite(STDOUT, sprintf("Building %s %s\n\n", Application::NAME, Application::VERSION));

    // Cleared rather than reused, so a dependency that has since been removed cannot
    // linger in the archive — and so one build at a time: two of them share this
    // directory, and the second one's `rm -rf` is the first one's staging tree.
    run(sprintf('rm -rf %s %s', escapeshellarg($deps), escapeshellarg($app)), 'clearing the build directory');
    mkdir($deps, 0o777, true);
    mkdir($app, 0o777, true);

    fwrite(STDOUT, "\n  Runtime dependencies\n");

    // The manifest is staged with its repositories rewritten, and the lock is *not*
    // copied. That is the one place this build gives up something it would rather keep,
    // and it is worth being exact about why.
    //
    // A lock built against `path` repositories records each library's dist as
    // `{"type": "path", "url": "../markdown-word"}`. Carried into `build/deps`, that
    // points at a directory that does not exist, and no amount of rewriting the manifest
    // changes it — the lock is what says where the bytes come from. So a staged build
    // resolves from `vcs` instead, and what it resolves is the tip of each library's
    // default branch at the moment the build ran.
    //
    // That means the archive is not pinned by this repository's commit alone. Until the
    // libraries carry tags of their own the two cannot be: pinning needs a commit to
    // pin, and there is nowhere to record one that a `path` repository will not
    // overwrite. The cost is bounded — nothing installs `mdoffice` from Packagist yet,
    // and the library versions the archive actually holds are written into its metadata
    // below, so any artifact says what it was built from. Pinning to exact commits is
    // the release step, alongside registering `markdown-office/*` on Packagist.
    stageManifest($root, $deps);

    run(
        sprintf(
            '%s update --no-dev --no-interaction --no-progress --optimize-autoloader --working-dir=%s',
            $composer,
            escapeshellarg($deps),
        ),
        'installing without development dependencies',
    );

    fwrite(STDOUT, "\n  Application\n");

    copyInto($root . '/src', $app);
    copyInto($root . '/bin', $app);

    // No `themes/` beside the app: each library finds its own through
    // `dirname(__DIR__, 2) . '/themes'`, which from
    // `app/vendor/markdown-office/markdown-word/src/Theme/` is that package's own
    // directory. Composer brought them in with the packages, which is also why
    // `themes/` must not be `export-ignore`d in either of them — a line there is
    // invisible until an archive ships without the themes its documents name.
    copyInto($deps . '/vendor', $app);

    // Across everything copied above rather than beside each `copyInto`: `cp -R` is
    // what brings them in, so the filter belongs where the tree is complete, and a
    // later copy cannot reintroduce one the first pass removed.
    $strays = [];

    foreach (
        new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($app, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        ) as $file
    ) {
        if (!$file instanceof SplFileInfo) {
            continue;
        }

        $relative = substr((string) $file->getPathname(), strlen($app) + 1);

        if ($file->isDir() && in_array($file->getFilename(), STRAY_DIRECTORIES, true)) {
            $strays[] = $relative . '/';

            continue;
        }

        if ($file->isFile() && in_array($file->getFilename(), STRAY_FILES, true)) {
            $strays[] = $relative;
        }
    }

    // Removed once the walk is over rather than as each one is found: the iterator
    // descends into a directory it has already yielded, so removing one there
    // fails the walk on the directory that is already gone.
    foreach ($strays as $stray) {
        $path = $app . '/' . $stray;

        if (str_ends_with($stray, '/')) {
            exec(sprintf('rm -rf %s', escapeshellarg($path)));
        } elseif (is_file($path)) {
            unlink($path);
        }
    }

    if ($strays !== []) {
        fwrite(STDOUT, sprintf(
            "  %-44s%d (%s)\n",
            'dropping files a machine left behind',
            count($strays),
            implode(', ', $strays),
        ));
    }

    foreach (['LICENSE.md', 'LICENSE', 'README.md'] as $document) {
        if (is_file($root . '/' . $document)) {
            run(
                sprintf('cp %s %s', escapeshellarg($root . '/' . $document), escapeshellarg($app)),
                'copying ' . $document,
            );
        }
    }

    fwrite(STDOUT, "\n  Dependencies\n");

    $pruned = prune($app . '/vendor');

    fwrite(STDOUT, sprintf(
        "  %-44s%d files, %s\n",
        'pruning their tests and documentation',
        $pruned['count'],
        humanSize($pruned['bytes']),
    ));

    fwrite(STDOUT, sprintf("  %-44s%s\n", '  directories', implode(', ', $pruned['directories'])));
    fwrite(STDOUT, sprintf("  %-44s%s\n", '  files', implode(', ', $pruned['paths'])));

    // The permission bits go into the manifest, so the umask the machine happened to
    // have would otherwise be one more thing that differs between two builds.
    run(
        sprintf('find %s -type f -exec chmod 644 {} +', escapeshellarg($app)),
        'normalising the staged file permissions',
    );
    chmod($app . '/bin/mdoffice', 0o755);

    fwrite(STDOUT, "\n  Archive\n");

    if (!confirm(sprintf('Write %s?', $target))) {
        fwrite(STDOUT, "Cancelled.\n");

        exit(0);
    }

    @unlink($target);

    $phar = new Phar($target, 0, Application::NAME . '.phar');
    $phar->startBuffering();

    $phar->setStub(<<<'STUB'
        #!/usr/bin/env php
        <?php

        /**
         * mdoffice — Markdown to Word and to PowerPoint.
         *
         * Generated by tools/build-phar.php. Edit bin/mdoffice or src/ instead.
         */

        Phar::mapPhar('mdoffice.phar');

        require 'phar://mdoffice.phar/app/bin/mdoffice';

        __HALT_COMPILER();
        STUB);

    // Everything under the staging directory goes in at a path relative to it, so the
    // archive has an /app root the stub can find things under; the directories are
    // created by the files inside them.
    //
    // Sorted, because a directory iterator hands back whatever order the filesystem
    // felt like and both the manifest and the contents follow the order the files
    // were added in. Sorted, the archive is the same archive.
    $paths = [];

    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($app, FilesystemIterator::SKIP_DOTS),
    ) as $file) {
        if ($file instanceof SplFileInfo && $file->isFile()) {
            $paths[] = (string) $file->getPathname();
        }
    }

    sort($paths, SORT_STRING);

    foreach ($paths as $path) {
        $phar->addFile($path, 'app/' . substr($path, strlen($app) + 1));
    }

    // Compression is a pass over the whole archive rather than something `addFile()`
    // takes, so it comes last. Nearly everything in here is text.
    $phar->compressFiles(Phar::GZ);

    $phar->setMetadata([
        'name' => Application::NAME,
        'version' => Application::VERSION,
        'built' => gmdate('c', $epoch),
    ]);

    $phar->stopBuffering();

    chmod($target, 0o755);

    try {
        normaliseTimestamps($target, $epoch);
    } catch (RuntimeException $problem) {
        // An archive whose manifest was rewritten but not verified is worse than no
        // archive, so the build that could not vouch for it deletes it.
        @unlink($target);

        fwrite(STDERR, "\n" . $problem->getMessage() . "\n"
            . "The archive it could not vouch for has been deleted.\n");

        exit(1);
    }

    fwrite(STDOUT, sprintf("  %-44s%s (%d files)\n", 'every entry stamped', gmdate('c', $epoch), count($paths)));

    fwrite(STDOUT, "\n  Verification\n");

    $reported = trim(capture(sprintf(
        '%s %s --version 2>&1',
        escapeshellarg(PHP_BINARY),
        escapeshellarg($target),
    )));

    $expected = Application::NAME . ' ' . Application::VERSION;

    // The first line only. The rest is the version of each library in the archive,
    // which belongs to the lock and not to this check.
    $first = strtok($reported, PHP_EOL);

    fwrite(STDOUT, sprintf('  %-44s', 'the archive runs'));

    if ($first !== $expected) {
        fwrite(STDOUT, "failed\n");
        fwrite(STDERR, sprintf("Expected \"%s\" but the archive said \"%s\".\n", $expected, $first));

        exit(1);
    }

    fwrite(STDOUT, "ok\n");

    // A conversion in each direction, because an archive that reports its version and
    // then cannot convert anything is still broken. A `.docx` is a zip archive and
    // starts with `PK`, and the round trip has to come back with the heading in it:
    // together those two catch a missing dependency as well as a broken one.
    //
    // Every command carries `word`, because that is the dispatcher and the archive is
    // `mdoffice` now, not `mdword`. An archive whose stub loaded but whose entry point
    // no longer took a part would answer `--version` and then fail every conversion, so
    // the part is exercised here rather than only in the tests.
    //
    // The round trip goes through a file rather than a pipe, so that every command
    // here is a single command and closing its standard input is unambiguous. A
    // redirection written after a pipe binds to the wrong end of it, and the reading
    // end would get `/dev/null` instead of the document.
    $phar = escapeshellarg($target);
    $example = escapeshellarg($root . '/examples/01-smoke.md');
    $roundTrip = $build . '/round-trip.docx';

    $produced = static fn (string $command): string => capture(sprintf($phar . ' ' . $command, $example));

    $document = $produced('word to-docx %s -o - 2>/dev/null');
    $produced('word to-docx %s -o ' . escapeshellarg($roundTrip) . ' 2>/dev/null');
    $back = $produced('word to-markdown ' . escapeshellarg($roundTrip) . ' -o - 2>/dev/null');

    @unlink($roundTrip);

    $checks = [
        'it converts Markdown to a document' => substr($document, 0, 2) === 'PK',
        'it converts a document back to Markdown' => str_contains($back, '# Smoke test'),
        // The table is what a theme, the style registry and PHPWord's own writer all
        // have a hand in, so a round trip that returns it is evidence the archive is
        // whole rather than merely loadable.
        'the document kept its table' => str_contains($back, '| one'),
    ];

    foreach ($checks as $what => $passed) {
        fwrite(STDOUT, sprintf('  %-44s', $what));

        if (!$passed) {
            fwrite(STDOUT, "failed\n");

            exit(1);
        }

        fwrite(STDOUT, "ok\n");
    }

    fwrite(STDOUT, sprintf("\n%s — %s\n", $target, humanSize((int) filesize($target))));
    fwrite(STDOUT, sprintf("%d files, %s of dependencies' own tests and documentation left out.\n",
        $pruned['count'],
        humanSize($pruned['bytes']),
    ));
    fwrite(STDOUT, "Put it anywhere on your PATH and run `mdoffice`.\n\n");
}

// Skipped when the file was `require`d for its definitions. Defining the constant is
// the whole of the contract; there is nothing else to configure.
if (!defined(BUILD_PHAR_INCLUDED)) {
    mdofficeBuild();
}
