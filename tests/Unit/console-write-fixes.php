<?php

declare(strict_types=1);

use MarkdownOffice\Console\Word\WordApplication;
use MarkdownOffice\Console\ConsoleException;
use MarkdownOffice\Tests\Support\Scratch;
use MarkdownOffice\Tests\Support\Upstream;

/*
 * The command line's result writer.
 *
 * `writeResult()` used `file_put_contents()`, which opens an existing file with
 * O_TRUNC. Two names for one inode therefore both got truncated: writing the
 * result to a hard link to the input destroyed the input. The guard in
 * `BaseCommand` cannot see this, because `realpath()` returns a different path
 * for each name.
 *
 * Writing is now staged beside the target and renamed, which replaces the
 * directory entry and leaves the shared inode alone.
 */

beforeEach(fn () => Upstream::install());
afterEach(fn () => Upstream::restore());

/** A directory of its own, since the shared scratch directory is wiped wholesale. */
function workingDirectory(): string
{
    $path = Scratch::directory() . '/write-' . bin2hex(random_bytes(4));

    mkdir($path, 0o777, true);

    return $path;
}

/** @return array{0: string, 1: string} The two names for one inode. */
function linkedPair(): array
{
    $directory = workingDirectory();
    $input = $directory . '/input.md';
    $alias = $directory . '/alias.md';

    file_put_contents($input, "ORIGINAL CONTENT\n");
    link($input, $alias);

    return [$input, $alias];
}

it('leaves a hard link to the input alone', function () {
    [$input, $alias] = linkedPair();

    expect(fileinode($input))->toBe(fileinode($alias));

    (new WordApplication())->writeResult($alias, "REPLACEMENT\n");

    expect((string) file_get_contents($input))->toBe("ORIGINAL CONTENT\n")
        ->and((string) file_get_contents($alias))->toBe("REPLACEMENT\n");
});

it('gives the result its own inode rather than the one it replaced', function () {
    [$input, $alias] = linkedPair();

    (new WordApplication())->writeResult($alias, "REPLACEMENT\n");

    expect(fileinode($input))->not->toBe(fileinode($alias));
});

it('writes a result to a name nothing else points at', function () {
    $path = workingDirectory() . '/plain.md';

    (new WordApplication())->writeResult($path, "hello\n");

    expect((string) file_get_contents($path))->toBe("hello\n");
});

it('leaves no staging file behind when the write works', function () {
    $directory = workingDirectory();

    (new WordApplication())->writeResult($directory . '/ok.md', "fine\n");

    expect((string) file_get_contents($directory . '/ok.md'))->toBe("fine\n")
        ->and(glob($directory . '/.mdoffice_*'))->toBe([]);
});

it('leaves no staging file behind when the directory cannot be made', function () {
    // Fails before anything is staged, so there is nothing to strand.
    expect(fn () => (new WordApplication())->writeResult('/proc/mdoffice-no/out.md', 'x'))
        ->toThrow(ConsoleException::class);
});
