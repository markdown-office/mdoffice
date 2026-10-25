<?php

declare(strict_types=1);

/**
 * The smoke test, and the paths it is given.
 *
 * The smoke test is given the path of a built binary and the path of the probe
 * that was built beside it, and it runs both. A path with no directory in it is
 * not a name the shell can find — the working directory is not in the shell's
 * PATH — so a binary given as `mdoffice` is not found at all, and a binary that
 * works is reported as one that does not run.
 *
 * The test runs the smoke test against a binary that answers, because that is
 * the only way to know that it found it.
 */

it('runs a binary it is given by a name with no directory in it', function () {
    $work = sys_get_temp_dir() . '/mdoffice-smoke-' . bin2hex(random_bytes(6));

    mkdir($work, 0o777, true);

    // A binary that answers the version, and the probe that answers about the
    // interpreter. Neither converts anything: the test is about the binary
    // being found, not about what it does with a document.
    file_put_contents($work . '/mdoffice', <<<'SH'
#!/usr/bin/env sh
echo "mdoffice 0.1.0"
echo "  markdown-office/markdown-word            0.2.0"
echo "  markdown-office/markdown-presentation    0.2.0"
SH);

    file_put_contents($work . '/probe', <<<'SH'
#!/usr/bin/env sh
echo "extensions: dom,gd,imagick,zip"
echo "jpeg: yes"
SH);

    chmod($work . '/mdoffice', 0o755);
    chmod($work . '/probe', 0o755);

    $root = dirname(__DIR__, 2);

    $output = (string) shell_exec(sprintf(
        'cd %s && php %s --binary mdoffice --probe probe --root %s 2>&1',
        escapeshellarg($work),
        escapeshellarg($root . '/tools/smoke-binary.php'),
        escapeshellarg($root),
    ));

    // The first thing the smoke test reports is whether the binary runs, and
    // that is the report this is about: a binary that is not found fails it.
    expect($output)->toMatch('/the binary runs\s+ok/');
});

it('reads a version whose lines end in a way the reader does not expect', function () {
    // The line ending of the program that wrote the output is not necessarily
    // the one of the machine that reads it — the Windows binary writes one the
    // reader does not expect. A version that is not split is a version that is
    // not found, and the report says the library is not a release.
    //
    // The binary here ends its lines with a carriage return alone, which no
    // platform's line ending is, so the test fails on every machine.
    $work = sys_get_temp_dir() . '/mdoffice-smoke-' . bin2hex(random_bytes(6));

    mkdir($work, 0o777, true);

    file_put_contents($work . '/mdoffice', <<<'SH'
#!/usr/bin/env sh
printf 'mdoffice 0.1.0\r  markdown-office/markdown-word              v0.2.0\r  markdown-office/markdown-presentation      v0.2.0'
SH);

    file_put_contents($work . '/probe', <<<'SH'
#!/usr/bin/env sh
echo "extensions: dom,gd,imagick,zip"
echo "jpeg: yes"
SH);

    chmod($work . '/mdoffice', 0o755);
    chmod($work . '/probe', 0o755);

    $root = dirname(__DIR__, 2);

    $output = (string) shell_exec(sprintf(
        'cd %s && php %s --binary mdoffice --probe probe --root %s 2>&1',
        escapeshellarg($work),
        escapeshellarg($root . '/tools/smoke-binary.php'),
        escapeshellarg($root),
    ));

    expect($output)->toMatch('/markdown-word is a release\s+ok/')
        ->and($output)->toMatch('/markdown-presentation is a release\s+ok/');
});

it('redirects from the device the platform has, and not from the POSIX one', function () {
    // The standard input of what the smoke test runs is closed, by redirecting
    // it from the platform's empty device. `/dev/null` is not a path on
    // Windows: the shell there does not know it, and a redirect from a file
    // that is not there stops the command from running at all — which is what
    // the smoke test says when the binary does not run.
    //
    // The device is in a file of its own, and the tool reads it from there, so
    // that the name is the platform's on every platform and not the one the
    // tool was written on.
    $root = dirname(__DIR__, 2);

    $device = (string) require $root . '/tools/null-device.php';

    // The device of the platform, worked out here rather than read: the name
    // of the POSIX device is not a path on Windows, and the name of the Windows
    // one is not a path on POSIX.
    expect($device)->toBe(PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');

    // And it is a device, and it is empty.
    $stream = @fopen($device, 'rb');
    $contents = is_resource($stream) ? stream_get_contents($stream) : null;

    if (is_resource($stream)) {
        fclose($stream);
    }

    expect($contents)->toBe('');

    // The tool takes the device from there, and not from a name written down
    // in it. A tool that redirects from `/dev/null` works on POSIX and does not
    // work at all on Windows, and the difference is invisible until a build
    // runs there.
    $source = (string) file_get_contents($root . '/tools/smoke-binary.php');

    expect($source)->toContain('null-device.php')
        ->and($source)->not->toMatch('/<\s*[\'"]?\/dev\/null/');
});
