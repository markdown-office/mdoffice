<?php

declare(strict_types=1);

/*
 * The OOXML gate: the documents this program writes, checked against the schema.
 *
 * The validator is Microsoft's own — the one the Open XML SDK Productivity Tool
 * uses — and it is run over what the binary writes rather than over what the
 * library writes, because the binary is what the release publishes and it is
 * the one thing the two libraries do not measure.
 *
 * The test skips itself where the .NET SDK is not, and says what is missing. A
 * machine without the SDK can still run the rest of the suite; a machine with
 * it cannot pass this test with a document the schema rejects.
 */

use MarkdownOffice\Tests\Support\Packages;
use MarkdownOffice\Tests\Support\Scratch;

uses()->beforeEach(function (): void {
    $missing = whyTheValidatorCannotRun();

    if ($missing !== '') {
        test()->markTestSkipped($missing);
    }
});

it('writes a document the schema accepts', function () {
    $document = Scratch::path('smoke', '.docx');

    $run = runTheBinary(['word', exampleFile('01-smoke.md'), '--to', 'docx', '-o', $document]);

    expect($run['code'])->toBe(0, 'The binary did not write the document:' . PHP_EOL . $run['out']);

    $answer = runTheValidator([$document]);

    expect($answer['code'])->toBe(0, 'The validator reported:' . PHP_EOL . $answer['out']);
});

it('writes a deck the schema accepts', function () {
    $deck = Scratch::path('deck', '.pptx');

    $run = runTheBinary(['slides', exampleFile('02-deck.md'), '-o', $deck]);

    expect($run['code'])->toBe(0, 'The binary did not write the deck:' . PHP_EOL . $run['out']);

    $answer = runTheValidator([$deck]);

    expect($answer['code'])->toBe(0, 'The validator reported:' . PHP_EOL . $answer['out']);
});

it('finds an error in a document that has one', function () {
    // The gate is worth nothing if it cannot fail. A validator that answered "no
    // errors" about every document would pass the two tests above, so this one
    // asks it about a document with an error in it and expects to be told.
    //
    // The error is an element no schema names, and the document is otherwise the
    // one the binary wrote: it still opens, so what refuses it is the schema and
    // not a broken archive.
    $document = Scratch::path('smoke', '.docx');

    $run = runTheBinary(['word', exampleFile('01-smoke.md'), '--to', 'docx', '-o', $document]);

    expect($run['code'])->toBe(0, 'The binary did not write the document:' . PHP_EOL . $run['out']);

    $broken = Packages::copy(
        $document,
        ['word/document.xml' => bodyPartWithAnUnknownElement($document)],
        [],
        'broken',
        '.docx',
    );

    $answer = runTheValidator([$broken]);

    expect($answer['code'])->toBe(1, 'The validator did not refuse the document:' . PHP_EOL . $answer['out'])
        ->and($answer['out'])->toContain('notAnElement');
});

/**
 * An example of this repository, by name.
 */
function exampleFile(string $name): string
{
    return dirname(__DIR__, 2) . '/examples/' . $name;
}

/**
 * The body part of a Word document, with an element no schema names in it.
 *
 * The element is one no schema has, so a reader has to refuse the document
 * whatever else it thinks of it — and the document still opens, so what refuses
 * it is the schema and not a broken archive.
 */
function bodyPartWithAnUnknownElement(string $document): string
{
    $zip = new ZipArchive();

    if ($zip->open($document) !== true) {
        throw new RuntimeException(sprintf('Unable to open the document "%s".', $document));
    }

    $xml = $zip->getFromName('word/document.xml');
    $zip->close();

    if ($xml === false) {
        throw new RuntimeException(sprintf('"%s" does not contain a body.', $document));
    }

    $broken = str_replace('<w:body>', '<w:body><w:notAnElement/>', $xml);

    if ($broken === $xml) {
        throw new RuntimeException(sprintf('"%s" does not carry a body the element can be put into.', $document));
    }

    return $broken;
}

/**
 * Why the validator cannot run on this machine, or an empty string when it can.
 *
 * The SDK is not on every machine, and a test that fails where it cannot run
 * says nothing about the code — so the test skips itself and says what is
 * missing instead.
 */
function whyTheValidatorCannotRun(): string
{
    static $answer = null;

    if ($answer !== null) {
        return $answer;
    }

    $dotnet = commandOnPath('dotnet');

    if ($dotnet === null) {
        return $answer = 'The .NET SDK is not on this machine, so the OOXML validator cannot be run.';
    }

    $lines = [];
    $status = 0;

    exec(escapeshellarg($dotnet) . ' --list-sdks 2>&1', $lines, $status);

    if ($status !== 0 || $lines === []) {
        return $answer = 'This machine has the .NET runtime but no .NET SDK, so the validator cannot be built.';
    }

    return $answer = '';
}

/**
 * The command on this machine's PATH, or nothing.
 *
 * The PATH is walked rather than the shell asked, because `command -v` is a
 * POSIX command and a Windows command prompt does not have it.
 */
function commandOnPath(string $command): ?string
{
    $path = getenv('PATH');

    if ($path === false) {
        return null;
    }

    foreach (explode(PATH_SEPARATOR, $path) as $directory) {
        if ($directory === '') {
            continue;
        }

        $candidate = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $command;

        if (PHP_OS_FAMILY === 'Windows') {
            $candidate .= '.exe';
        }

        if (is_file($candidate) && is_executable($candidate)) {
            return $candidate;
        }
    }

    return null;
}

/**
 * What the binary did, and what it said.
 *
 * The binary and not the library: the library is measured by the two libraries'
 * own suites, and the binary is the one thing neither of them measures.
 *
 * @param list<string> $arguments The command line, without the program itself.
 * @return array{code: int, out: string}
 */
function runTheBinary(array $arguments): array
{
    $command = sprintf(
        '%s %s %s 2>&1',
        escapeshellarg(PHP_BINARY),
        escapeshellarg(dirname(__DIR__, 2) . '/bin/mdoffice'),
        implode(' ', array_map('escapeshellarg', $arguments)),
    );

    $lines = [];
    $code = 0;

    exec($command, $lines, $code);

    return ['code' => $code, 'out' => implode(PHP_EOL, $lines)];
}

/**
 * What the validator says about a set of files.
 *
 * The exit code is the answer: 0 when every file is clean, 1 when one of them is
 * not, and 2 when there was nothing to check. A test that read the output alone
 * would have to know what the output says about a file it never opened.
 *
 * @param list<string> $files
 * @return array{code: int, out: string}
 */
function runTheValidator(array $files): array
{
    $dotnet = commandOnPath('dotnet');

    if ($dotnet === null) {
        throw new RuntimeException('The .NET SDK was there a moment ago and is not there now.');
    }

    $command = sprintf(
        '%s run --project %s -- %s 2>&1',
        escapeshellarg($dotnet),
        escapeshellarg(dirname(__DIR__, 2) . '/tools/ooxml-validator'),
        implode(' ', array_map('escapeshellarg', $files)),
    );

    $lines = [];
    $code = 0;

    exec($command, $lines, $code);

    return ['code' => $code, 'out' => implode(PHP_EOL, $lines)];
}
