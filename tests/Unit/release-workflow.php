<?php

declare(strict_types=1);

/**
 * The release workflow, and the two places a published binary has to be made
 * runnable.
 *
 * The executable is the interpreter with the archive appended to it. The
 * pipeline writes it with `cat`, and `cat` writes a *new* file — and a new
 * file is not runnable: the mode of the interpreter is not carried over to what
 * is written from it. So the file has to be made runnable where it is written,
 * or the smoke test cannot run it and the published binary cannot be run.
 *
 * The second place is the release job. The artifact store does not keep the
 * mode of a file: everything comes back 0644, whatever it was uploaded with, so
 * the files that are published have to be made runnable again.
 *
 * Both are here rather than in the pipeline's own smoke test because the
 * smoke test only runs on a runner: the release workflow runs on a tag or on a
 * manual dispatch, and neither happens on every change.
 */

it('makes the executable runnable before the smoke test runs it', function () {
    $combine = releaseStep('Combine the archive with the interpreter');
    $smoke = releaseStep('The binary works');

    // The file is made runnable where it is written, and the smoke test runs
    // that same file — the one that is published.
    expect($combine)->toMatch('/chmod\s+(\+x|7[0-7][0-7])/')
        ->and($smoke)->toContain('--binary "${{ matrix.executable }}"');
});

it('runs the smoke test after the executable is made runnable', function () {
    $steps = releaseSteps();

    expect($steps)->toHaveKey('Combine the archive with the interpreter')
        ->and($steps)->toHaveKey('The binary works')
        ->and($steps['Combine the archive with the interpreter'])
        ->toBeLessThan($steps['The binary works']);
});

it('makes the published binaries runnable', function () {
    // The artifact store gives every file back as 0644, so the binaries are
    // made runnable again before they are published.
    expect(releaseStep('Make the binaries runnable'))->toMatch('/chmod\s+(\+x|7[0-7][0-7])/');
});

it('publishes the binaries after they are made runnable', function () {
    $steps = releaseSteps();

    expect($steps)->toHaveKey('Make the binaries runnable')
        ->and($steps)->toHaveKey('Publish the release')
        ->and($steps['Make the binaries runnable'])->toBeLessThan($steps['Publish the release']);
});

/**
 * The steps of the release workflow, and the line each of them is on.
 *
 * @return array<string, int> The name of each step and where it is.
 */
function releaseSteps(): array
{
    $steps = [];

    foreach (explode("\n", releaseWorkflow()) as $number => $line) {
        if (preg_match('/^\s+- name: (.+?)\s*$/', $line, $match) === 1) {
            $steps[$match[1]] = $number;
        }
    }

    return $steps;
}

/**
 * The shell a step of the release workflow runs.
 *
 * A reader rather than a parser, because the workflow is the file this
 * repository writes and the shape of it is what the tests above are about: a
 * step is a `- name:` line and the lines under it.
 *
 * @param string $name The name the step carries.
 *
 * @return string The step's lines, or an empty string when there is no such
 *         step.
 */
function releaseStep(string $name): string
{
    $lines = explode("\n", releaseWorkflow());
    $start = null;

    foreach ($lines as $number => $line) {
        if (preg_match('/^\s+- name: ' . preg_quote($name, '/') . '\s*$/', $line) === 1) {
            $start = $number;

            break;
        }
    }

    if ($start === null) {
        return '';
    }

    $indent = strlen($lines[$start]) - strlen(ltrim($lines[$start]));
    $step = [];

    for ($number = $start + 1; $number < count($lines); $number++) {
        $line = $lines[$number];

        if (trim($line) === '') {
            $step[] = $line;

            continue;
        }

        if (strlen($line) - strlen(ltrim($line)) <= $indent) {
            break;
        }

        $step[] = $line;
    }

    return implode("\n", $step);
}

/**
 * The release workflow.
 */
function releaseWorkflow(): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/.github/workflows/release.yml');
}
