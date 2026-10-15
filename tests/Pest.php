<?php

declare(strict_types=1);

use MarkdownOffice\Console\Application;
use MarkdownOffice\Tests\Support\Scratch;
use MarkdownOffice\Tests\Support\Upstream;
use MarkdownWord\Configuration;
use MarkdownWord\MarkdownToWord;

/**
 * Test bootstrap and the helpers the command line tests share.
 *
 * The helpers are functions rather than a base test case: Pest builds each test as a
 * closure, so a shared *capability* belongs here and a shared *assertion* in the test
 * that makes it.
 */

// `tmp/pest` is emptied after every test, failure or not, so a run leaves
// nothing behind. The hook goes through `pest()`: in Pest 5 a bare `afterEach()`
// written here binds to this file, which is not a test file, so it would never
// fire — nothing fails, every test passes, and the files simply stop being
// cleaned up.

pest()->afterEach(function (): void {
    Scratch::cleanUp();
});

/**
 * A file in the scratch directory with the given contents, and its path.
 *
 * The name may carry its own extension — the command line derives an output name
 * from it, and a few tests are about the name rather than only the bytes — and a
 * random suffix is added so that no two tests collide.
 */
function inputFile(string $name, string $contents, ?string $extension = null): string
{
    $given = pathinfo($name, PATHINFO_EXTENSION);

    $path = Scratch::path(
        pathinfo($name, PATHINFO_FILENAME),
        $extension ?? ($given === '' ? '.md' : '.' . $given),
    );

    file_put_contents($path, $contents);

    return $path;
}

/**
 * Write a `.docx` file. The wrapper is only there for the upstream deprecation
 * described in tests/Support/Upstream.
 */
function saveDocument(string $markdown, string $path, ?Configuration $config = null): void
{
    Upstream::quietly(static function () use ($markdown, $path, $config): void {
        (new MarkdownToWord($markdown, $config ?? new Configuration()))->save($path);
    });
}

/**
 * Run the command line in process, and hand back what it did.
 *
 * The top-level {@see Application} rather than a part, so the dispatcher is exercised
 * by every test here: a helper that reached for `WordApplication` directly would leave
 * the part name in `$argv` untested, and that name is what keeps `mdoffice notes.md`
 * from being ambiguous. So each call carries `word` as its first argument and the rest
 * of the line is what a user would type after it.
 *
 * @param list<string> $argv
 * @return array{code: int, out: string, err: string}
 */
function runCli(array $argv, string $stdin = ''): array
{
    $out = fopen('php://memory', 'r+b');
    $err = fopen('php://memory', 'r+b');
    $in = fopen('php://memory', 'r+b');

    fwrite($in, $stdin);
    rewind($in);

    $code = (new Application($out, $err, $in))->run($argv);

    rewind($out);
    rewind($err);

    $result = [
        'code' => $code,
        'out' => (string) stream_get_contents($out),
        'err' => (string) stream_get_contents($err),
    ];

    fclose($out);
    fclose($err);
    fclose($in);

    return $result;
}
