<?php

declare(strict_types=1);

use MarkdownOffice\Console\Application;
use MarkdownOffice\Tests\Support\Scratch;

/*
 * The dispatcher: which part a run is for, and the help that says so.
 *
 * Everything else in the suite reaches the Word half through here, so what is left
 * to test is the answering — the two ways a run ends without converting anything, and
 * the third where a part that does exist is not the one that was named.
 */

it('prints its version, and the libraries it is holding', function () {
    $run = runCli(['--version']);

    // The first line on its own and in the shape every other PHP tool prints, so a
    // script reading `--version` keeps working. The build reads exactly that line
    // back and fails the release when it disagrees with the tag.
    expect($run['code'])->toBe(Application::SUCCESS)
        ->and($run['out'])->toStartWith(Application::NAME . ' ' . Application::VERSION . PHP_EOL)
        ->and($run['err'])->toBe('');

    // The libraries below it, because a bug report that says which tool produced a
    // document but not which library did is half an answer. `\S+` rather than a fixed
    // string: the version is whatever the lock resolved, and the assertion is that
    // there is one.
    expect($run['out'])->toMatch('~markdown-office/markdown-word\s+\S+~');
});

it('says a library it is not holding rather than leaving it out', function () {
    // The presentation half is not a dependency yet, so this run is the only place
    // the "not installed" branch is reached. A version list that quietly omitted a
    // half would be the one thing that made `--version` useless for the job it is
    // there for.
    expect(runCli(['--version'])['out'])
        ->toMatch('~markdown-office/markdown-presentation\s+\S+~');
});

it('prints the parts when given nothing to do', function () {
    foreach ([[], ['help'], ['--help'], ['-h']] as $argv) {
        $run = runCli($argv);

        expect($run['code'])->toBe(Application::SUCCESS)
            ->and($run['out'])->toContain('PARTS')
            ->and($run['out'])->toContain('word')
            // The parts, not the Word commands: the whole point of naming a part is
            // that `to-docx` is not on this screen.
            ->and($run['out'])->not->toContain('to-docx')
            ->and($run['err'])->toBe('');
    }
});

it('refuses a first argument that is not a part, and names the ones that are', function () {
    $run = runCli(['spreadsheet']);

    expect($run['code'])->toBe(Application::FAILURE)
        ->and($run['err'])->toContain('Unknown part "spreadsheet".')
        ->and($run['err'])->toContain('word')
        // The error is the one thing here that goes to standard error, so a piped
        // standard output stays clean.
        ->and($run['out'])->toBe('');
});

it('offers the correction for a bare file, which is the likely mistake', function () {
    // `mdword notes.md` worked and `mdoffice notes.md` does not, because with more
    // than one converter a bare name cannot say which way the data goes. Someone
    // typing it is nearly always right about the file and wrong about the syntax, so
    // the error carries the line that would have worked.
    $run = runCli(['notes.md']);

    expect($run['code'])->toBe(Application::FAILURE)
        ->and($run['err'])->toContain('Did you mean `mdoffice word notes.md`?');
});

it('hands the rest of the line to the part that was named', function () {
    $input = inputFile('dispatch', "# Dispatched\n");
    $output = Scratch::path('dispatched', '.docx');

    $run = runCli(['word', 'to-docx', $input, '-o', $output]);

    // Proved by the document arriving, not by the exit code: a dispatcher that dropped
    // the part and treated `word` as the input would convert a file called `word`,
    // which does not exist, and fail rather than pass.
    expect($run['code'])->toBe(Application::SUCCESS)
        ->and($run['err'])->toContain('dispatched')
        ->and(is_file($output))->toBeTrue()
        // A `.docx` is a zip archive and begins `PK`, which is also what says the
        // bytes are a document rather than a message that happened to land here.
        ->and(file_get_contents($output))->toStartWith('PK');
});

it('sends the document to standard output and the progress to standard error', function () {
    $input = inputFile('streams', "# Streams\n");

    $run = runCli(['word', 'to-docx', $input, '-o', '-']);

    // The separation is what lets `mdoffice word to-docx in.md -o - | pbcopy` do what
    // it looks like it does, so it is checked as the two streams rather than as the
    // absence of an error: a progress line on standard output would corrupt the pipe,
    // and a document on standard error would simply be missing.
    expect($run['out'])->toStartWith('PK')
        ->and($run['out'])->not->toContain('→')
        ->and($run['err'])->toContain('→');
});