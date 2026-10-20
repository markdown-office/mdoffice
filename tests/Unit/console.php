<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use MarkdownOffice\Console\Application;
use MarkdownOffice\Console\Slides\SlidesApplication;
use MarkdownOffice\Console\Word\WordApplication;
use MarkdownOffice\Tests\Support\Scratch;
use MarkdownOffice\Console\Word\Command\ToDocx;
use MarkdownOffice\Console\Word\Command\ToMarkdown;
use MarkdownOffice\Tests\Support\Package;
use MarkdownOffice\Tests\Support\TemplateFactory;
use MarkdownOffice\Tests\Support\Upstream;
use MarkdownWord\WordPackage;

/*
 * The `mdoffice word` command.
 *
 * The application takes its three streams as arguments precisely so that a run
 * can be driven from a test and its output read back, which is what these tests
 * do. The alternative — running the real binary — would be a test of the shell
 * rather than of the code. Every run goes through the top-level dispatcher, so
 * each `$argv` begins with the `word` part name; `runCli()` says why.
 *
 * Writing a document reaches the one known upstream deprecation described in
 * tests/Support/Upstream, so the filter spans the whole test.
 */
beforeEach(fn () => Upstream::install());
afterEach(fn () => Upstream::restore());

/*
 * The entry script reads `$argv` guarded.
 *
 * This file is the phar's stub as well as the installed command, so it has to be
 * safe to include, and `$argv` is defined by the CLI SAPI and by nothing else.
 * PHPStan cannot see that the guard is there unless the file is named on its
 * command line — PHPStan takes its input by the `.php` suffix — so this is the
 * only thing holding the guard in place. It found the gap when `bin/mdoffice` was
 * added to the analysis, and reported `$argv might not be defined`.
 *
 * Only the negative half is asserted, and for the reason the bootstrap's own check
 * gives: a grep for the literal text would break on whitespace and on a
 * reformatting that still works. Every unguarded spelling has to be excluded
 * instead, which a negative regex does whether the code reads `$argv[1]`,
 * `$argv [1]` or `count($argv)`.
 */
it('reads the arguments of the entry script through a guard', function () {
    $entry = (string) file_get_contents(dirname(__DIR__, 2) . '/bin/mdoffice');

    // Comments are dropped first, since the file explains this very rule in prose
    // and naming the variable there must not count as reading it.
    $code = '';
    foreach (token_get_all($entry) as $token) {
        if (is_array($token) && ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT)) {
            continue;
        }

        $code .= is_array($token) ? $token[1] : $token;
    }

    // A `$argv` that is not immediately coalesced. The `??` has to be allowed to
    // have whitespace around it, or the check is a formatting rule.
    expect($code)->not->toMatch('/\$argv\b(?!\s*\?\?)/');
});

// Basics

// The two halves had a version each and disagreed: this one answered with a constant
// nobody checked. A bug report quotes the part's own answer, so the two must be one.
it('prints its version, and the install reports the same one', function () {
    $run = runCli(['word', '--version']);
    $installed = InstalledVersions::getPrettyVersion(WordApplication::PACKAGE);

    expect($installed)->not->toBeNull();
    expect($run['code'])->toBe(0);
    expect($run['out'])->toBe('mdoffice word ' . $installed . PHP_EOL);
    expect(runCli(['--version'])['out'])->toContain($installed);
});

// `Application::LIBRARIES` and each part's `PACKAGE` name the same packages twice, and
// a bug report is answered from the first list while a part answers from the second.
// The two fell out of step once — the presentation library was renamed — and
// `--version` then said `not installed` for a library whose classes loaded fine, which
// is the failure these two guards exist for.
it('names the same libraries the dispatcher lists as its parts do', function () {
    $parts = (new ReflectionClass(Application::class))->getConstant('PARTS');
    $listed = array_map(
        static fn (string $part): string => constant($part . '::PACKAGE'),
        array_values((array) $parts),
    );

    expect($listed)->toContain(WordApplication::PACKAGE);
    expect(Application::version())->toContain(WordApplication::PACKAGE);
    expect(Application::version())->toContain(SlidesApplication::PACKAGE);
});

// A requirement that resolves under a different name than it is written under is
// satisfied by classes that load and invisible to `InstalledVersions` — which is how
// `not installed` came to be printed for a library that was there.
it('reports every package the manifest requires as installed', function () {
    $required = array_keys(json_decode(
        (string) file_get_contents(__DIR__ . '/../../composer.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    )['require']);

    $packages = array_values(array_filter(
        $required,
        static fn (string $name): bool => !str_starts_with($name, 'ext-') && $name !== 'php',
    ));

    expect($packages)->not->toBe([]);

    foreach ($packages as $package) {
        expect(InstalledVersions::isInstalled($package))->toBeTrue($package . ' is required and not installed');
        expect(Application::version())->toContain($package);
    }
});

it('prints help when given no command', function () {
    $run = runCli(['word']);

    expect($run['code'])->toBe(0);
    expect($run['out'])->toContain('to-docx');
    expect($run['out'])->toContain('to-markdown');
});

it('prints an options section for each direction', function () {
    // What this checks is that `--help` works at all for both commands and lists
    // the options they share. That the help and the parser agree about which
    // options there are is a different question, asked by `keeps the help and the
    // parser specification in step`.
    foreach (['to-docx', 'to-markdown'] as $command) {
        $run = runCli(['word', $command, '--help']);

        expect($run['code'])->toBe(0);
        expect($run['out'])->toContain('OPTIONS');
        expect($run['out'])->toContain('--output');
    }
});

it('reports a file that is not there', function () {
    // A bare word is read as a filename rather than as a command, because a file
    // may be called anything at all and the direction is worked out from content.
    $run = runCli(['word', 'frobnicate']);

    expect($run['code'])->toBe(WordApplication::FAILURE);
    expect($run['err'])->toContain('The file "frobnicate" does not exist');
});

it('reports an unknown option with a hint', function () {
    $run = runCli(['word', 'to-docx', '--frobnicate']);

    expect($run['code'])->toBe(WordApplication::FAILURE);
    expect($run['err'])->toContain('Unknown option "--frobnicate"');
});

it('says which command an option belongs to when it is used with the wrong one', function () {
    $run = runCli(['word', 'to-docx', '-', '--media', 'assets']);

    expect($run['code'])->toBe(WordApplication::FAILURE);
    expect($run['err'])->toContain('The option "--media" does not apply here');
    expect($run['err'])->toContain('It belongs to `to-markdown`');
});

it('reports an option that is missing its value', function () {
    $run = runCli(['word', 'to-docx', '--output']);

    expect($run['code'])->toBe(WordApplication::FAILURE);
    expect($run['err'])->toContain('The option "output" needs a value');
});

// To-docx

it('converts a file to a document', function () {
    $input = inputFile('notes', "# Notes\n\nSome **bold** text.\n");
    $output = Scratch::path('notes');

    $run = runCli(['word', 'to-docx', $input, '-o', $output]);

    expect($run['code'])->toBe(0);
    expect(is_file($output))->toBeTrue();
    expect(Package::textOf($output))->toContain('Notes');
    expect(Package::textOf($output))->toContain('bold');
});

it('names the output after the input when told nothing', function () {
    $input = inputFile('report', '# Report');

    // The convention is the same one every other converter uses: the name with
    // its extension swapped, in the directory it was read from.
    $expected = preg_replace('/\.md$/', '.docx', $input);

    expect(runCli(['word', 'to-docx', $input])['code'])->toBe(0);
    expect(is_file((string) $expected))->toBeTrue();
});

it('reads standard input and writes standard output', function () {
    $run = runCli(['word', 'to-docx', '-', '-o', '-'], "# From standard input\n");

    expect($run['code'])->toBe(0);
    // The magic bytes of a zip archive, which is what a `.docx` is.
    expect(substr($run['out'], 0, 2))->toBe('PK');
});

it('writes to standard output when the output is left out and there is no file', function () {
    $run = runCli(['word', 'to-docx'], '# Piped');

    expect($run['code'])->toBe(0);
    expect(substr($run['out'], 0, 2))->toBe('PK');
});

it('keeps progress off standard output', function () {
    $run = runCli(['word', 'to-docx', '-', '-o', '-'], '# Quiet');

    // Anything but the document itself on standard output, or a pipe would
    // carry a progress line into whatever is reading it.
    expect(substr($run['out'], 0, 2))->toBe('PK');
    expect($run['err'])->toContain('standard input');
});

it('accepts an option value written with an equals sign', function () {
    $input = inputFile('eq', '# Equals');
    $output = Scratch::path('eq');

    expect(runCli(['word', 'to-docx', $input, '--output=' . $output])['code'])->toBe(0);
    expect(is_file($output))->toBeTrue();
});

it('reads a file whose name begins with a dash', function () {
    $input = inputFile('--weird', '# Odd name');
    $output = Scratch::path('weird');

    // Everything after `--` is an operand, so the separator goes last.
    expect(runCli(['word', 'to-docx', '-o', $output, '--', $input])['code'])->toBe(0);
    expect(is_file($output))->toBeTrue();
});

it('renders without decoration when asked to', function () {
    $input = inputFile('plain', "# Plain\n\n> quoted\n");
    $output = Scratch::path('plain');

    runCli(['word', 'to-docx', $input, '--plain', '-o', $output]);

    $xml = Package::xmlOf($output);

    expect($xml)->not->toContain('IntenseQuote');
});

it('leaves images out when told to', function () {
    Scratch::image('cli.png');

    $input = inputFile('pic', '![Some words](cli.png)');
    $output = Scratch::path('pic');

    runCli(['word', 'to-docx', $input, '--no-images', '-o', $output]);

    expect(Package::xmlOf($output))->not->toContain('imagedata');
});

it('rejects an image mode it does not know', function () {
    $run = runCli(['word', 'to-docx', '-', '--images', 'sideways'], '# X');

    expect($run['code'])->toBe(WordApplication::FAILURE);
    expect($run['err'])->toContain('Unknown image mode "sideways"');
    expect($run['err'])->toContain('embed, placeholder or skip');
});

it('rejects a table width that is not a number', function () {
    $run = runCli(['word', 'to-docx', '-', '--table-width', 'wide'], '# X');

    expect($run['code'])->toBe(WordApplication::FAILURE);
    expect($run['err'])->toContain('is not a number');
});

it('applies a configuration file', function () {
    $config = Scratch::path('config', '.php');
    file_put_contents($config, <<<'PHP'
        <?php

        use MarkdownWord\Configuration;

        return Configuration::fromArray(['styles' => ['heading.1' => 'ReportTitle']]);
        PHP);

    $input = inputFile('conf', '# Styled');
    $output = Scratch::path('conf');

    runCli(['word', 'to-docx', $input, '-c', $config, '-o', $output]);

    // A style the library does not define is left to the template, so the only
    // thing to check is that the heading points at it.
    expect(Package::xmlOf($output))->toContain('<w:pStyle w:val="ReportTitle"/>');
});

it('reads the same configuration file twice in one process', function () {
    $config = Scratch::path('config-twice', '.php');
    file_put_contents($config, <<<'PHP'
        <?php

        use MarkdownWord\Configuration;

        return Configuration::fromArray(['styles' => ['heading.1' => 'ReportTitle']]);
        PHP);

    // `include_once` answers `true` from the second read, so this only passes
    // because the value is kept by the loader rather than left to the include.
    // Two runs, one process, one path — which `runCli` gives for free.
    foreach (range(1, 2) as $ignored) {
        $input = inputFile('conf-twice-' . $ignored, '# Styled');
        $outputs[$ignored] = Scratch::path('conf-twice-' . $ignored);

        $run = runCli(['word', 'to-docx', $input, '-c', $config, '-o', $outputs[$ignored]]);

        expect($run['code'])->toBe(0);
    }

    expect(Package::xmlOf($outputs[2]))->toContain('<w:pStyle w:val="ReportTitle"/>');
});

it('reports a configuration file that is not there', function () {
    $run = runCli(['word', 'to-docx', '-', '-c', '/does/not/exist.php'], '# X');

    expect($run['code'])->toBe(WordApplication::FAILURE);
    expect($run['err'])->toContain('does not exist');
});

it('reports a configuration file that returns the wrong thing', function () {
    $config = Scratch::path('bad-config', '.php');
    file_put_contents($config, "<?php\n\nreturn 'not an array';\n");

    $run = runCli(['word', 'to-docx', '-', '-c', $config], '# X');

    expect($run['code'])->toBe(WordApplication::FAILURE);
    expect($run['err'])->toContain('must return an array or a Configuration');
});

// Templates

it('renders into a template and fills the fields the document supplies', function () {
    $template = TemplateFactory::report();
    $output = Scratch::path('from-template');

    // The values are in the document's own block, which is the whole of where they
    // come from: a document that says who it is for says so in one place.
    $run = runCli(
        ['word', 'to-docx', '-', '--template', $template, '-o', $output],
        "---\nfields:\n  reference: REF-42\n---\n\n## Overview\n\nRevenue **grew**.\n",
    );

    expect($run['code'])->toBe(0);

    $text = Package::textOf($output);

    expect($text)->toContain('REF-42');
    expect($text)->toContain('Overview');
    // The field is unlinked, so the document carries no field and no placeholder.
    expect($text)->not->toContain('«');
});

it('draws a template in the theme the command line named', function () {
    $markdown = "## Overview\n\nRevenue **grew**.\n";
    $argv = ['word', 'to-docx', '-', '--template', TemplateFactory::report()];

    $themed = Scratch::path('with-theme');
    expect(runCli([...$argv, '--theme', 'minimal', '-o', $themed], $markdown)['code'])->toBe(0);

    $plain = Scratch::path('without-theme');
    expect(runCli([...$argv, '-o', $plain], $markdown)['code'])->toBe(0);

    // `minimal` is about headings carrying no colour and no weight of their own, so the
    // markup it produces has to differ from the built-in look. A theme name that never
    // reached the template would render both documents identically and still exit zero.
    expect(Package::xmlOf($themed))->not->toBe(Package::xmlOf($plain));
    expect(Package::textOf($themed))->toContain('Overview');
});

it('resolves the named theme on the template path, and says so when it cannot', function () {
    $run = runCli([
        'word', 'to-docx', '-',
        '--template', TemplateFactory::report(),
        '--theme', 'no-such-theme',
        '-o', Scratch::path('never'),
    ], '# X');

    // `--theme` resolving on the plain path but not on the template one would make the
    // flag quietly do nothing for anyone using `--template`, which no amount of passing
    // the name along would show.
    expect($run['code'])->toBe(WordApplication::FAILURE);
    expect($run['err'])->toContain('no-such-theme');
});

it('renders into a template that has no content control at all', function () {
    // The default is what makes any `.dotx` usable, so the command line has to reach
    // it too: a letterhead nobody put a control in is the ordinary case now.
    $out = Scratch::path('letterhead.docx');

    $run = runCli([
        'word', 'to-docx', '-',
        '--template', TemplateFactory::letterhead(),
        '-o', $out,
    ], "# X");

    expect($run['code'])->toBe(WordApplication::SUCCESS);
    expect(Package::bodyTextOf($out))
        ->toContain('X')
        ->toContain('Yours sincerely');
});

it('says which content controls a template has when asked to insist on one', function () {
    $run = runCli([
        'word', 'to-docx', '-',
        '--template', TemplateFactory::noControl(),
        '--require-control',
        '-o', Scratch::path('never'),
    ], '# X');

    expect($run['code'])->toBe(WordApplication::FAILURE);
    expect($run['err'])->toContain('no "body" content control');
    expect($run['err'])->toContain('terms');
});

it('refuses a template that is not a document', function () {
    $notADocument = Scratch::path('not-a-template', '.md');
    file_put_contents($notADocument, '# Not a docx');

    $run = runCli(['word', 'to-docx', '-', '--template', $notADocument, '-o', Scratch::path('never')], '# X');

    expect($run['code'])->toBe(WordApplication::FAILURE);
    expect($run['err'])->toContain('could not be opened');
    // The two kinds of Word package, since refusing either would be the wrong answer
    // for the second of them.
    expect($run['err'])->toContain('.docx or .dotx');
});

it('renders into a .dotx and writes a document out', function () {
    $output = Scratch::path('from-dotx', '.docx');

    $run = runCli([
        'word', 'to-docx', '-',
        '--template', TemplateFactory::dotx(),
        '-o', $output,
    ], "## Overview\n\nRevenue **grew**.\n");

    expect($run['code'])->toBe(WordApplication::SUCCESS);
    // The extension says document and the package has to agree with it: Word opens a
    // package typed as a template by creating a new document from it.
    expect(Package::mainContentTypeOf($output))
        ->toBe(WordPackage::DOCUMENT_MAIN);
    expect(Package::bodyTextOf($output))->toContain('Overview');
});

it('finds the region in a template whose body part is not word/document.xml', function () {
    $output = Scratch::path('from-odd-dotx', '.docx');

    $run = runCli([
        'word', 'to-docx', '-',
        '--template', TemplateFactory::dotx('document22.xml'),
        '-o', $output,
    ], "## Overview\n\nRevenue **grew**.\n");

    // The check read word/document.xml by name and found no ${body} in a part
    // nothing pointed at, so a template that was entirely usable was refused.
    expect($run['code'])->toBe(WordApplication::SUCCESS);
    expect(Package::bodyTextOf($output))->toContain('Overview');
});

it('refuses a template a document names outside the directories a document may name', function () {
    // The system temporary directory, which is outside this repository and so outside
    // both roots a run has: the working directory and the document's own. The file is
    // real and readable; it is somewhere a document may not send this run.
    $outside = tempnam(sys_get_temp_dir(), 'mdoffice-outside-');
    copy(TemplateFactory::report(), $outside);

    $run = runCli(
        ['word', 'to-docx', '-'],
        sprintf("---\ntemplate_file: %s\n---\n\n## Overview\n", $outside),
    );

    expect($run['code'])->toBe(WordApplication::FAILURE);
    expect($run['err'])->toContain('outside the directories a document may name');

    @unlink($outside);
});

it('accepts a document pointing inside the working directory', function () {
    // The boundary is where a path lands, not where it was written from: a document
    // in `docs/` naming a template one directory up is inside the working directory,
    // so it is a template rather than an escape. Refusing it would make containment
    // mean "must be in the document's own directory", which is stricter than the
    // rule a `theme:` has always had.
    $directory = Scratch::directory() . '/sibling';
    $sibling = Scratch::directory() . '/sibling-template';
    mkdir($directory, 0o777, true);
    mkdir($sibling, 0o777, true);
    copy(TemplateFactory::report(), $sibling . '/report.dotx');
    $output = $directory . '/out.docx';

    $run = runCli(
        ['word', 'to-docx', '-', '-o', $output],
        sprintf("---\ntemplate_file: %s\n---\n\n## Overview\n", $sibling . '/report.dotx'),
    );

    expect($run['code'])->toBe(WordApplication::SUCCESS);

    // Text only the template carries, so this cannot pass against a build that
    // ignored the key and wrote the Markdown into a document of its own.
    expect(Package::bodyTextOf($output))->toContain('Quarterly Report');
});

it('refuses a template a document names by URL', function () {
    // A URL is not a path, and a path is what a run may read. The document did not
    // have to be trying: one written by a model, from a file it was given, is
    // enough.
    //
    // The refusal is asserted to be the URL one and not the missing-file one, because
    // a URL that is not refused as a URL is merely a file that is not there, and the
    // two say different things to whoever has to act on them.
    $run = runCli(
        ['word', 'to-docx', '-'],
        "---\ntemplate_file: https://example.com/report.dotx\n---\n\n## Overview\n",
    );

    expect($run['code'])->toBe(WordApplication::FAILURE);
    expect($run['err'])->toContain('is a URL');
    expect($run['err'])->not->toContain('could not be found');
});

it('reports --define as an option it no longer has', function () {
    // It went with the macro dialect: a value belongs to the document, under `fields:`,
    // and a flag that fills a template was a second way to say the same thing. An
    // unknown option says so rather than being silently ignored.
    $run = runCli(['word', 'to-docx', '-', '--define', 'a=b'], '# X');

    expect($run['code'])->toBe(WordApplication::FAILURE);
    expect($run['err'])->toContain('Unknown option "--define"');
});

// To-markdown

it('converts a document back to Markdown', function () {
    $document = Scratch::path('back', '.docx');
    saveDocument("# Round trip\n\nSome **bold** text.\n", $document);
    $output = Scratch::path('back', '.md');

    $run = runCli(['word', 'to-markdown', $document, '-o', $output]);

    expect($run['code'])->toBe(0);
    expect(file_get_contents($output))->toContain('# Round trip');
    expect(file_get_contents($output))->toContain('**bold**');
});

it('reads a document from standard input', function () {
    $document = Scratch::path('piped', '.docx');
    saveDocument("# Piped back\n", $document);

    $run = runCli(['word', 'to-markdown', '-', '-o', '-'], (string) file_get_contents($document));

    expect($run['code'])->toBe(0);
    expect($run['out'])->toContain('# Piped back');
});

it('writes underlined headings when told to', function () {
    $document = Scratch::path('setext', '.docx');
    saveDocument("# One\n\n## Two\n", $document);

    $run = runCli(['word', 'to-markdown', $document, '--setext', '-o', '-']);

    expect($run['out'])->toContain("One\n===");
    expect($run['out'])->toContain("Two\n---");
});

it('writes CRLF line endings when told to', function () {
    $document = Scratch::path('crlf', '.docx');
    saveDocument("# One\n\nText.\n", $document);

    $run = runCli(['word', 'to-markdown', $document, '--line-ending', 'crlf', '-o', '-']);

    expect($run['out'])->toContain("\r\n");
});

it('rejects a line ending it does not know', function () {
    $run = runCli(['word', 'to-markdown', '-', '--line-ending', 'cr']);

    expect($run['code'])->toBe(WordApplication::FAILURE);
    expect($run['err'])->toContain('Unknown line ending "cr"');
});

it('takes the images out beside the Markdown it writes', function () {
    Scratch::image('extract.png');

    $document = Scratch::path('extract', '.docx');
    saveDocument(
        '![A red square](extract.png)',
        $document,
        MarkdownWord\Configuration::create()->withOptions([
            'images' => MarkdownWord\Configuration\Options::IMAGE_EMBED,
            'imageBasePath' => Scratch::directory(),
        ]),
    );

    $output = Scratch::path('extracted', '.md');
    $run = runCli(['word', 'to-markdown', $document, '--media', 'assets', '-o', $output]);

    expect($run['code'])->toBe(0);

    preg_match('/!\[[^\]]*\]\(([^)]+)\)/', (string) file_get_contents($output), $match);

    expect($match)->not->toBeEmpty();
    // The reference has to resolve from where the Markdown is, or the images are
    // written somewhere the document does not point at.
    expect(is_file(dirname($output) . '/' . $match[1]))->toBeTrue();
});

// Direction

it('works the direction out from the file', function () {
    $input = inputFile('detect', "# Detected\n\nSome **bold**.\n");

    // No command in front: the file says which way it has to go.
    $run = runCli(['word', $input]);

    $document = preg_replace('/\.md$/', '.docx', (string) $input);

    expect($run['code'])->toBe(0);
    expect(is_file((string) $document))->toBeTrue();
    expect(Package::textOf((string) $document))->toContain('Detected');
});

it('works the direction out from a Word document too', function () {
    $document = Scratch::path('detect', '.docx');
    saveDocument("# Detected\n", $document);

    $run = runCli(['word', $document]);

    $markdown = preg_replace('/\.docx$/', '.md', $document);

    expect($run['code'])->toBe(0);
    expect(file_get_contents((string) $markdown))->toContain('# Detected');
});

it('goes by what the file is, not what it is called', function () {
    // A Markdown file with a misleading extension still goes to Word, because a
    // `.docx` is a zip archive and its first four bytes say so.
    $misnamed = Scratch::path('actually-markdown', '.docx');
    file_put_contents($misnamed, "# Not a document\n");
    $output = Scratch::path('converted');

    $run = runCli(['word', $misnamed, '-o', $output]);

    expect($run['code'])->toBe(0);
    expect(Package::textOf($output))->toContain('Not a document');
});

it('takes the direction from standard input when it is a document', function () {
    $document = Scratch::path('piped', '.docx');
    saveDocument("# Piped back\n", $document);

    $run = runCli(['word', '-', '-o', '-'], (string) file_get_contents($document));

    expect($run['code'])->toBe(0);
    expect($run['out'])->toContain('# Piped back');
});

it('needs --to for Markdown on standard input', function () {
    // A pipe of text is indistinguishable from an empty document by its first
    // four bytes alone, and guessing would be worse than asking.
    $run = runCli(['word', '--to', 'docx', '-', '-o', '-'], "# Piped in\n");

    expect($run['code'])->toBe(0);
    expect(substr($run['out'], 0, 2))->toBe('PK');
});

it('refuses a --to that contradicts the file', function () {
    $document = Scratch::path('real', '.docx');
    saveDocument("# A document\n", $document);

    $run = runCli(['word', $document, '--to', 'docx', '-o', Scratch::path('elsewhere')]);

    expect($run['code'])->toBe(WordApplication::FAILURE);
    expect($run['err'])->toContain('does not match');
    expect($run['err'])->toContain('That file is a Word document');
});

it('refuses a format it does not know', function () {
    $run = runCli(['word', '--to', 'pdf', '-']);

    expect($run['code'])->toBe(WordApplication::FAILURE);
    expect($run['err'])->toContain('Unknown format "pdf"');
});

it('refuses to write the result over the file it read', function () {
    // A `.md` in, a `.md` out: without this the run would destroy its own input.
    $document = Scratch::path('keep', '.docx');
    saveDocument("# Keep me\n", $document);

    $run = runCli(['word', $document, '-o', $document]);

    expect($run['code'])->toBe(WordApplication::FAILURE);
    expect($run['err'])->toContain('would overwrite the input file');
    expect(is_file($document))->toBeTrue();
});

it('keeps the help and the parser specification in step', function () {
    // The help table and the parser are two separate lists — one is for reading,
    // one is for parsing — so something has to hold them together. Either can
    // drift from the other without breaking anything at runtime.
    foreach ([ToDocx::class, ToMarkdown::class] as $command) {
        $spec = $command::spec();
        $accepted = WordApplication::names($spec);
        $documented = [];

        foreach ($command::options() as [$spelling, $description]) {
            preg_match_all('/--([a-z][a-z-]*)/', $spelling, $matches);
            $documented = array_merge($documented, $matches[1]);
        }

        $name = substr($command, (int) strrpos($command, '\\') + 1);

        expect(array_diff($documented, $accepted))->toBe([], $name . ': the help offers options the parser rejects');
        expect(array_diff($accepted, $documented))->toBe([], $name . ': the parser takes options the help does not mention');
    }
});

it('round trips a document through the command line unchanged', function () {
    $input = inputFile('pipeline', <<<'MD'
        # Heading

        A paragraph with **bold**, *italic*, `code` and a [link](https://example.com).

        - one
        - two

        > a quote

        | A | B |
        | --- | --- |
        | 1 | 2 |
        MD);

    $document = Scratch::path('pipeline', '.docx');
    $output = Scratch::path('pipeline', '.md');

    expect(runCli(['word', 'to-docx', $input, '-o', $document])['code'])->toBe(0);
    expect(runCli(['word', 'to-markdown', $document, '-o', $output])['code'])->toBe(0);

    $markdown = (string) file_get_contents($output);

    expect($markdown)->toContain('# Heading');
    expect($markdown)->toContain('**bold**');
    expect($markdown)->toContain('*italic*');
    expect($markdown)->toContain('`code`');
    expect($markdown)->toContain('[link](https://example.com)');
    expect($markdown)->toContain('- one');
    expect($markdown)->toContain('> a quote');
    expect($markdown)->toContain('| 1 | 2 |');
});
