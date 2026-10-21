#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Builds `build/mdoffice.phar` — a single file that converts Markdown to Word and
 * to PowerPoint, on a machine with nothing but PHP.
 *
 * The dependencies are installed into a directory of their own, because
 * `composer install --no-dev` in the project would delete the test tools from
 * `vendor/` and the next `composer test` would fail. Their own test suites,
 * documentation and analysis configuration are pruned out of the archive.
 *
 * Two builds of one source tree on one runner, PHP and zlib produce the same
 * bytes. Two different PHP builds are not promised: the compressor has a say in
 * that too. What this script does about it is in {@see resolveEpoch()} and
 * {@see normaliseTimestamps()}.
 *
 * `phar.readonly` is `On` in most PHP installations, which is the right default
 * and cannot be changed at runtime. Rather than refuse, this script re-runs
 * itself with the setting turned off for the length of the build, so that one
 * command works everywhere.
 */

use MarkdownOffice\Build\ArchiveRefused;
use MarkdownOffice\Build\BuildRefused;
use MarkdownOffice\Console\Application;

/**
 * Define this before `require`ing this file to load its definitions without running
 * the build.
 *
 * The only reason it exists is that a test can then reach {@see stageManifest()},
 * {@see packageNamed()} and {@see resolveEpoch()} without a build that stages a tree
 * and installs a second copy of the dependencies.
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

require_once $autoloader;

/** Padded, so every result lines up whatever the length of the label beside it. */
const STEP_LABEL_FORMAT = '  %-44s';

const STEP_OK = "ok\n";
const STEP_FAILED = "failed\n";

const MANIFEST_FILE = '/composer.json';

function confirm(string $question): bool
{
    if (!stream_isatty(STDIN)) {
        return true;
    }

    fwrite(STDOUT, $question . ' [Y/n] ');

    $answer = strtolower(trim((string) fgets(STDIN)));

    return $answer === '' || $answer === 'y' || $answer === 'yes';
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
    fwrite(STDOUT, sprintf(STEP_LABEL_FORMAT, $what));

    $output = [];
    $status = 0;

    exec($command . ' 2>&1 < /dev/null', $output, $status);

    if ($status !== 0) {
        fwrite(STDOUT, STEP_FAILED);
        fwrite(STDERR, implode(PHP_EOL, $output) . PHP_EOL);

        exit($status);
    }

    fwrite(STDOUT, STEP_OK);
}

/**
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
 * {@see filesBelow()} is the same walk with the directories left out.
 *
 * @return Generator<int, SplFileInfo>
 */
function entriesBelow(string $directory): Generator
{
    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );

    foreach ($entries as $entry) {
        if ($entry instanceof SplFileInfo) {
            yield $entry;
        }
    }
}

/**
 * A directory is never yielded, whatever is inside it, so a caller matching on names
 * cannot take one for a file.
 *
 * @return Generator<int, SplFileInfo>
 */
function filesBelow(string $directory): Generator
{
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($files as $file) {
        if ($file instanceof SplFileInfo) {
            yield $file;
        }
    }
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
 * A requirement that names no series of releases.
 *
 * `*` asks for anything, and what it gets depends on when the resolve ran: a
 * stable-preferring resolve takes the newest *tag*, so a build this month and a
 * build next month hold different code, and neither can be said to hold a
 * version of the library at all.
 *
 * So the manifest's own requirement is used — `^0.2` for the word library and
 * `^0.1` for the presentation one — and a requirement that names no series is
 * refused rather than resolved.
 */
const VCS_UNPINNED_REQUIREMENT = '*';

function isPrunedFile(string $relative, string $name): bool
{
    if (in_array($relative, PRUNED_FILE_EXCEPTIONS, true)) {
        return false;
    }

    foreach (PRUNED_FILES as $pattern) {
        if (fnmatch($pattern, $name)) {
            return true;
        }
    }

    return false;
}

/**
 * @param array{count: int, bytes: int, paths: list<string>, directories: list<string>} $pruned
 */
function recordRemoval(array &$pruned, int $bytes, string $relative): void
{
    $pruned['count']++;
    $pruned['bytes'] += $bytes;
    $pruned['paths'][] = $relative;
}

/**
 * @param array{count: int, bytes: int, paths: list<string>, directories: list<string>} $pruned
 */
function removePrunedDirectory(array &$pruned, string $vendor, string $relative): void
{
    $path = $vendor . '/' . $relative;

    if (!is_dir($path)) {
        return;
    }

    foreach (filesBelow($path) as $file) {
        if ($file->isFile()) {
            recordRemoval($pruned, (int) $file->getSize(), substr($file->getPathname(), strlen($vendor) + 1));
        }
    }

    $pruned['directories'][] = $relative;

    exec(sprintf('rm -rf %s', escapeshellarg($path)));
}

/**
 * Reports what it removed rather than what it was asked to, because a pattern that
 * quietly matched something a package needed at runtime would otherwise show up as a
 * broken archive — or, worse, not at all.
 *
 * @return array{count: int, bytes: int, paths: list<string>, directories: list<string>}
 */
function prune(string $vendor): array
{
    $pruned = ['count' => 0, 'bytes' => 0, 'paths' => [], 'directories' => []];
    $directories = [];

    foreach (entriesBelow($vendor) as $entry) {
        $relative = substr($entry->getPathname(), strlen($vendor) + 1);

        if ($entry->isDir()) {
            if (in_array(strtolower($entry->getFilename()), PRUNED_DIRECTORIES, true)) {
                $directories[] = $relative;
            }

            continue;
        }

        if (!isPrunedFile($relative, $entry->getFilename())) {
            continue;
        }

        recordRemoval($pruned, (int) $entry->getSize(), $relative);

        unlink($entry->getPathname());
    }

    // Ascending order puts a directory ahead of the ones nested inside it, so a nested
    // one is already gone when it is reached: the guard in `removePrunedDirectory()`
    // skips it, and its files are counted once, with the parent that took them.
    sort($directories, SORT_STRING);

    foreach ($directories as $relative) {
        removePrunedDirectory($pruned, $vendor, $relative);
    }

    sort($pruned['paths'], SORT_STRING);
    sort($pruned['directories'], SORT_STRING);

    return $pruned;
}

/**
 * A value that is not a number is refused rather than replaced with the moment the
 * build ran, which would give an archive that looks like a successful build and is not
 * a reproducible one. Thrown rather than exited from, because `exit()` in a helper
 * cannot be caught.
 */
function epochFromEnvironment(): ?int
{
    $epoch = getenv('SOURCE_DATE_EPOCH');

    if ($epoch === false || trim($epoch) === '') {
        return null;
    }

    $epoch = trim($epoch);

    if (!ctype_digit($epoch)) {
        throw new BuildRefused(sprintf(
            'SOURCE_DATE_EPOCH has to be a whole number of seconds since 1970, and %s is not.',
            var_export($epoch, true),
        ));
    }

    return (int) $epoch;
}

/**
 * @return list<string>
 */
function authoredInputs(string $root): array
{
    $inputs = ['composer.json', 'composer.lock', 'README.md', 'LICENSE', 'tools/build-phar.php'];

    foreach (['src', 'bin', 'tools', 'examples'] as $directory) {
        if (!is_dir($root . '/' . $directory)) {
            continue;
        }

        foreach (filesBelow($root . '/' . $directory) as $file) {
            // `STRAY_DIRECTORIES` has nothing to match here, and that is the only reason
            // the `isFile()` guard is here: a directory is never yielded.
            if (!$file->isFile() || in_array($file->getFilename(), STRAY_FILES, true)) {
                continue;
            }

            $inputs[] = substr($file->getPathname(), strlen($root) + 1);
        }
    }

    return $inputs;
}

/**
 * `SOURCE_DATE_EPOCH` wins when it is set, which is the reproducible builds
 * convention and what a release should do: the tag's own commit date is the most
 * meaningful timestamp an artifact can carry. Without it the newest modification
 * time of the *authored* sources is used, so a checkout nothing has touched since
 * builds the same bytes twice.
 *
 * `vendor/` is deliberately not in that. Composer extracted those files a moment
 * ago, so their times say when this machine ran rather than when the code was
 * written, and a fresh install would give every entry a new stamp.
 */
function resolveEpoch(string $root): int
{
    $fromEnvironment = epochFromEnvironment();

    if ($fromEnvironment !== null) {
        return $fromEnvironment;
    }

    $newest = 0;

    // No committed `composer.lock` exists to be listed, so each input is asked about
    // before it is trusted.
    foreach (authoredInputs($root) as $input) {
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
        throw new ArchiveRefused('the archive is truncated');
    }

    return $value[1];
}

function readUint16(string $raw, int &$offset): int
{
    $value = unpack('v', substr($raw, $offset, 2));
    $offset += 2;

    if ($value === false) {
        throw new ArchiveRefused('the archive is truncated');
    }

    return $value[1];
}

/**
 * `__HALT_COMPILER();` ends the stub and PHP writes a closing tag and a line
 * ending after it, but `setStub()` adds both for us and a stub written without
 * them is equally valid, so every combination is accepted.
 */
function dataStart(string $raw): int
{
    $halt = strpos($raw, '__HALT_COMPILER();');

    if ($halt === false) {
        throw new ArchiveRefused('the archive has no stub');
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
        throw new ArchiveRefused(sprintf(
            'the manifest says it is %d bytes and reading it consumed %d, so this build does not'
            . ' recognise the archive layout this PHP wrote and will not rewrite it',
            $length,
            $walked,
        ));
    }

    return ['timestamps' => $timestamps, 'end' => $end, 'metadata' => $metadata];
}

/**
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
        throw new ArchiveRefused(sprintf(
            'the archive is signed with %s, which this build does not know how to rewrite',
            $type === '' ? 'nothing' : $type,
        ));
    }

    $algorithm = $algorithms[$type];

    if (substr($raw, -4) !== 'GBMB') {
        throw new ArchiveRefused('the archive does not end in the marker it should');
    }

    $digestLength = strlen((string) hex2bin((string) $signature['hash']));

    // The digest, then four bytes naming the algorithm. There is no length in
    // front of them, which is why the offset is derived from the end instead.
    $length = $digestLength + 4;
    $offset = strlen($raw) - 4 - $length;

    if (hash($algorithm, substr($raw, 0, $offset)) !== strtolower((string) $signature['hash'])) {
        throw new ArchiveRefused('the signature does not cover the bytes before it, so this build'
            . ' does not recognise the archive layout this PHP wrote and will not rewrite it');
    }

    return ['algorithm' => $algorithm, 'offset' => $offset, 'length' => $length];
}

/**
 * `Phar::addFile()` records `time()` as the archive is flushed — not the file's
 * own modification time, and not anything that can be passed in.
 * `PharFileInfo::setMTime()` no longer exists, a custom `SplFileInfo` handed to
 * `buildFromIterator()` is ignored, and no released PHP consults
 * `SOURCE_DATE_EPOCH` (php-src#20570 will). So the timestamps are written into
 * the manifest directly and the signature is recalculated over the result.
 *
 * Every one of those is checked against the bytes that were written, because an
 * archive this script rewrote into something it merely believes is still a corrupt
 * archive.
 */
function normaliseTimestamps(string $target, int $epoch): void
{
    $raw = file_get_contents($target);

    if ($raw === false) {
        throw new ArchiveRefused('the archive could not be read back');
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
        throw new ArchiveRefused('the rewritten archive could not be written in full');
    }

    // Read back off disk rather than through a second `Phar` object, which would
    // hand out the copy this process already has open and cache.
    $written = file_get_contents($target);

    if ($written === false || strlen($written) !== strlen($raw)) {
        throw new ArchiveRefused('the rewritten archive is not the length it should be');
    }

    $check = readManifest($written);

    if (count($check['timestamps']) !== count($manifest['timestamps'])) {
        throw new ArchiveRefused('the archive has a different number of entries than it did');
    }

    if ($check['metadata'] !== $manifest['metadata']) {
        throw new ArchiveRefused('rewriting the timestamps lost the archive metadata');
    }

    foreach ($check['timestamps'] as $entry) {
        if (substr($written, $entry['offset'], 4) !== $stamped) {
            throw new ArchiveRefused(sprintf('rewriting the timestamp of %s did not take', $entry['name']));
        }
    }

    if (hash($signature['algorithm'], substr($written, 0, $signature['offset']), true) !== $digest) {
        throw new ArchiveRefused('the rewritten archive does not match its own signature');
    }
}

/**
 * Matched on the last segment of the requirement's name, because that is the part a
 * checkout directory is named after: `../markdown-word` is
 * `markdown-office/markdown-word`. Null rather than an error when nothing matches,
 * because a repository may name something that is not a requirement — a
 * `packagist.org` entry for instance — and only the path kind reaches here.
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
        throw new BuildRefused(sprintf(
            'the directory name "%s" matches %d requirements (%s), and the build cannot tell which one to rewrite',
            $directory,
            count($matches),
            implode(', ', $matches),
        ));
    }

    return $matches[0] ?? null;
}

/**
 * Refuse a requirement that names no series of releases.
 *
 * `*` asks for anything, and what it resolves to depends on when the resolve ran:
 * a stable-preferring resolve takes the newest *tag* of any series. A build that
 * holds that holds code nobody chose, and says so in a version nobody can name.
 *
 * A series — `^0.2` — says which series is wanted, and takes the newest release
 * in it.
 *
 * @param array<string, string> $require
 */
function assertRequirementNamesASeries(array $require, string $name): void
{
    $constraint = $require[$name] ?? '';

    if (trim($constraint) === VCS_UNPINNED_REQUIREMENT) {
        throw new BuildRefused(sprintf(
            'the requirement for %s is "%s", which names no series of releases.'
            . ' A build that resolves it holds whatever was published when it ran,'
            . ' and says so in a version nobody can name.',
            $name,
            $constraint,
        ));
    }
}

/**
 * Development resolves the two libraries through `path` repositories, which is
 * what makes a change in either of them visible to the CLI without a reinstall.
 * Neither survives being copied: a `path` URL is resolved against the directory
 * holding the manifest, so `../markdown-word` means `build/markdown-word` once
 * staged, and a build run anywhere but beside the checkout finds no siblings at
 * all. So each one is rewritten to `vcs`, which resolves from any directory.
 *
 * @return list<string> the packages the rewritten repositories are asked for
 */
function stageManifest(string $root, string $deps): array
{
    /** @var array{repositories?: mixed, require?: array<string, string>, ...} $manifest */
    $manifest = json_decode((string) file_get_contents($root . MANIFEST_FILE), true, 512, JSON_THROW_ON_ERROR);
    $rewritten = 0;
    $asked = [];

    // Composer itself reads this file rather than this code, so the shape here is the
    // shape Composer accepts rather than the shape this build would like: an entry may
    // be a list of its own, a shorthand string, or an object, and only the `path` kind
    // reaches the rewrite below.
    $repositories = $manifest['repositories'] ?? [];

    if (!is_array($repositories)) {
        throw new BuildRefused('composer.json carries a "repositories" that is not a list.');
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
            throw new BuildRefused(sprintf('The path repository at index %d names no url.', $index));
        }

        $package = basename(rtrim($path, '/'));
        $url = VCS_URLS[$package] ?? null;

        if ($url === null) {
            throw new BuildRefused(sprintf(
                'the repository at ../%s is a path, and there is no URL to resolve it from instead.'
                . ' Add it to VCS_URLS, or drop it from composer.json',
                $package,
            ));
        }

        $repositories[$index] = ['type' => 'vcs', 'url' => $url];
        $rewritten++;

        // The requirement beside it, asked for by the series the manifest names —
        // `^0.2`, `^0.1` — rather than by a branch.
        //
        // A branch would be whatever was on it when the build ran, so two builds a
        // day apart hold different code and the archive says `dev-main` for a
        // library rather than a version a reader can name. A series takes the
        // newest *release* in it, which is a published tag.
        $name = packageNamed($manifest['require'] ?? [], $package);

        if ($name !== null) {
            assertRequirementNamesASeries($manifest['require'] ?? [], $name);

            $asked[] = $name;
        }
    }

    if ($rewritten > 0) {
        $manifest['repositories'] = $repositories;
    }

    file_put_contents(
        $deps . MANIFEST_FILE,
        json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL,
    );

    if ($rewritten > 0) {
        fwrite(STDOUT, sprintf(
            STEP_LABEL_FORMAT . "%d path repositories rewritten as vcs\n",
            'resolving the libraries by URL',
            $rewritten,
        ));
    }

    chmod($deps . MANIFEST_FILE, 0o644);

    return $asked;
}

/**
 * A `composer.phar` in the project root is preferred: that is the version whoever
 * installed the dependencies used, and the `composer` on the PATH is whatever the
 * machine carries.
 */
function resolveComposer(string $root): ?string
{
    $phar = $root . '/composer.phar';

    if (is_file($phar)) {
        return escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($phar);
    }

    $onPath = trim(capture('command -v composer 2>/dev/null'));

    return $onPath === '' ? null : $onPath;
}

/**
 * The lock is deliberately *not* copied, and that is the one thing this build gives up
 * that it would rather keep. A lock built against `path` repositories records each
 * library's dist as `{"type": "path", "url": "../markdown-word"}`; carried into
 * `build/deps` that points at a directory which is not there, and rewriting the
 * manifest does not change it, because the lock is what says where the bytes come from.
 *
 * So the staged install resolves from `vcs` and takes the published release the
 * manifest's constraint names, and the archive is not pinned by this repository's
 * commit alone. Which libraries the archive does hold, its own `--version` says, read
 * from the resolve this made, and its metadata says the same to anyone who is not
 * running it.
 *
 * @return array<string, string> package name => the version it resolved to
 */
function installDependencies(string $composer, string $root, string $deps): array
{
    fwrite(STDOUT, "\n  Runtime dependencies\n");

    $asked = stageManifest($root, $deps);

    run(
        sprintf(
            '%s update --no-dev --no-interaction --no-progress --optimize-autoloader --working-dir=%s',
            $composer,
            escapeshellarg($deps),
        ),
        'installing without development dependencies',
    );

    $resolved = resolvedLibraries($deps, $asked);

    foreach ($resolved as $name => $version) {
        report(sprintf('%s %s', substr($name, strlen('markdown-office/')), $version), true);
    }

    return $resolved;
}

/**
 * The versions the staged install resolved, read from the lock it wrote.
 *
 * The lock is what says which bytes are in the archive, so it is the one place the
 * answer is already written down.
 *
 * @param  list<string>          $asked The packages the manifest asked for
 * @return array<string, string> package name => the version it resolved to
 */
function resolvedLibraries(string $deps, array $asked): array
{
    $lock = $deps . '/composer.lock';

    if (!is_file($lock)) {
        throw new BuildRefused('the staged install wrote no lock, so there is nothing to say which versions it resolved');
    }

    /** @var array{packages?: mixed, ...} $read */
    $read = json_decode((string) file_get_contents($lock), true, 512, JSON_THROW_ON_ERROR);
    $versions = [];

    if (!is_array($read['packages'] ?? null)) {
        throw new BuildRefused('the staged install wrote a lock with no packages in it, so it resolved nothing');
    }

    foreach ($read['packages'] as $package) {
        if (!is_array($package)) {
            continue;
        }

        $name = $package['name'] ?? null;
        $version = $package['version'] ?? null;

        if (!is_string($name) || !is_string($version) || !in_array($name, $asked, true)) {
            continue;
        }

        $versions[$name] = $version;
    }

    $missing = array_values(array_diff($asked, array_keys($versions)));

    if ($missing !== []) {
        throw new BuildRefused(sprintf(
            'the staged install did not resolve %s, so the archive would hold neither.',
            implode(', ', $missing),
        ));
    }

    foreach ($versions as $name => $version) {
        if (!isReleaseVersion($version)) {
            throw new BuildRefused(sprintf(
                'the library %s resolved to "%s", which is a branch and not a release.'
                . ' An archive that holds a branch holds whatever was on it when the build ran,'
                . ' and says so in a version nobody can name.',
                $name,
                $version,
            ));
        }
    }

    return $versions;
}

/**
 * Whether a version names a release.
 *
 * A branch resolves to `dev-main` or to `9999999-dev`, and an alias to something
 * ending in `-dev`; a release names its numbers, with or without the `v` the tag
 * carried.
 */
function isReleaseVersion(string $version): bool
{
    return preg_match('/^v?\d/', $version) === 1 && !str_contains($version, 'dev');
}

/**
 * A stray directory carries a trailing slash, which is what tells it apart from a file
 * of the same name when it is removed.
 *
 * Removed once the walk is over rather than as each one is found: the iterator descends
 * into a directory it has already yielded, so removing one there fails the walk on the
 * directory that is already gone.
 *
 * @return list<string>
 */
function dropStrays(string $app): array
{
    $strays = [];

    foreach (entriesBelow($app) as $entry) {
        $relative = substr($entry->getPathname(), strlen($app) + 1);

        if ($entry->isDir() && in_array($entry->getFilename(), STRAY_DIRECTORIES, true)) {
            $strays[] = $relative . '/';

            continue;
        }

        if ($entry->isFile() && in_array($entry->getFilename(), STRAY_FILES, true)) {
            $strays[] = $relative;
        }
    }

    foreach ($strays as $stray) {
        $path = $app . '/' . $stray;

        if (str_ends_with($stray, '/')) {
            exec(sprintf('rm -rf %s', escapeshellarg($path)));
        } elseif (is_file($path)) {
            unlink($path);
        }
    }

    return $strays;
}

function copyApplication(string $root, string $deps, string $app): void
{
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
    $strays = dropStrays($app);

    if ($strays !== []) {
        fwrite(STDOUT, sprintf(
            STEP_LABEL_FORMAT . "%d (%s)\n",
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
}

/**
 * @return array{count: int, bytes: int, paths: list<string>, directories: list<string>}
 */
function pruneDependencies(string $app): array
{
    fwrite(STDOUT, "\n  Dependencies\n");

    $pruned = prune($app . '/vendor');

    fwrite(STDOUT, sprintf(
        STEP_LABEL_FORMAT . "%d files, %s\n",
        'pruning their tests and documentation',
        $pruned['count'],
        humanSize($pruned['bytes']),
    ));
    fwrite(STDOUT, sprintf(STEP_LABEL_FORMAT . "%s\n", '  directories', implode(', ', $pruned['directories'])));
    fwrite(STDOUT, sprintf(STEP_LABEL_FORMAT . "%s\n", '  files', implode(', ', $pruned['paths'])));

    // The permission bits go into the manifest, so the umask the machine happened to
    // have would otherwise be one more thing that differs between two builds.
    run(
        sprintf('find %s -type f -exec chmod 644 {} +', escapeshellarg($app)),
        'normalising the staged file permissions',
    );
    chmod($app . '/bin/mdoffice', 0o755);

    return $pruned;
}

/**
 * Paths are relative to the staging directory, so the archive has the `/app` root the
 * stub requires; the directories are created by the files inside them.
 *
 * Sorted, because a directory iterator hands back whatever order the filesystem
 * felt like and both the manifest and the contents follow the order the files
 * were added in. Sorted, the archive is the same archive.
 *
 * @param array<string, string> $libraries The library versions the resolve chose
 */
function writeArchive(string $app, string $target, int $epoch, array $libraries): int
{
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

    $paths = [];

    foreach (filesBelow($app) as $file) {
        if ($file->isFile()) {
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
        'libraries' => $libraries,
    ]);

    $phar->stopBuffering();

    chmod($target, 0o755);

    return count($paths);
}

/**
 * A conversion in each direction, because an archive that reports its version and then
 * cannot convert anything is still broken. A `.docx` is a zip archive and starts with
 * `PK`, and the round trip has to come back with the heading in it: together those two
 * catch a missing dependency as well as a broken one.
 */
function verifyArchive(string $target, string $root, string $build): void
{
    $reported = trim(capture(sprintf(
        '%s %s --version 2>&1',
        escapeshellarg(PHP_BINARY),
        escapeshellarg($target),
    )));

    $expected = Application::NAME . ' ' . Application::VERSION;

    // The first line only. The rest is the version of each library in the archive,
    // which this check is not about.
    $first = strtok($reported, PHP_EOL);

    report('the archive runs', $first === $expected, sprintf(
        "Expected \"%s\" but the archive said \"%s\".\n",
        $expected,
        $first,
    ));

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

    report('it converts Markdown to a document', substr($document, 0, 2) === 'PK');
    report('it converts a document back to Markdown', str_contains($back, '# Smoke test'));

    // The table is what a theme, the style registry and PHPWord's own writer all
    // have a hand in, so a round trip that returns it is evidence the archive is
    // whole rather than merely loadable.
    report('the document kept its table', str_contains($back, '| one'));

    // The deck. The two halves are different libraries and different conversions,
    // and a verification that exercised only one of them would pass an archive whose
    // other half was broken. The slides half was: the themes it renders with are
    // inside the archive, and a `PathScope` that cannot resolve a path under a
    // stream wrapper refused every one of them.
    $deckExample = escapeshellarg($root . '/examples/02-deck.md');
    $deckFile = $build . '/deck.pptx';

    $deck = capture(sprintf(
        $phar . ' slides to-pptx %s -o ' . escapeshellarg($deckFile) . ' 2>&1 < /dev/null',
        $deckExample,
    ));

    $written = is_file($deckFile) && filesize($deckFile) > 0;

    @unlink($deckFile);

    report('it writes a deck', $written, sprintf(
        "The archive said:\n%s\n",
        trim($deck),
    ));
}

/**
 * A function rather than the top level of the file, so that a test can reach the
 * helpers above it with `require` and stop before the work. A test that had to run
 * the whole build to reach the staging rewrite or the pruning would assert the build
 * instead.
 */
function mdofficeBuild(): void
{
    $root = dirname(__DIR__);
    $build = $root . '/build';
    $deps = $build . '/deps';
    $app = $build . '/app';
    $target = $build . '/mdoffice.phar';

    $composer = resolveComposer($root);

    if ($composer === null) {
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

    $libraries = installDependencies($composer, $root, $deps);

    fwrite(STDOUT, "\n  Application\n");

    copyApplication($root, $deps, $app);
    $pruned = pruneDependencies($app);

    fwrite(STDOUT, "\n  Archive\n");

    if (!confirm(sprintf('Write %s?', $target))) {
        fwrite(STDOUT, "Cancelled.\n");

        exit(0);
    }

    $added = writeArchive($app, $target, $epoch, $libraries);

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

    fwrite(STDOUT, sprintf(
        STEP_LABEL_FORMAT . "%s (%d files)\n",
        'every entry stamped',
        gmdate('c', $epoch),
        $added,
    ));

    fwrite(STDOUT, "\n  Verification\n");

    verifyArchive($target, $root, $build);

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
