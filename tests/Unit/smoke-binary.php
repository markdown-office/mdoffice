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
