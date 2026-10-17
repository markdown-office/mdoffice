<?php

declare(strict_types=1);

/*
 * The help text, checked against the parser.
 *
 * The usage text names options so that a reader can copy a line and run it. That makes
 * it a specification, and it was wrong: it carried `--region body --define
 * customer=Northwind`, and neither has ever been an option of any command, so the one
 * worked example of filling a template was a command that fails at runtime.
 *
 * Nothing caught it, because the two halves of the text are written in different
 * places — the table beside a command's code, and the prose of `usage()` — and nothing
 * compared them. An example nobody pastes is not noticed to be broken.
 *
 * So this reads every option out of the printed help and asks the parser whether it
 * takes one.
 */

use MarkdownOffice\Console\Application;
use MarkdownOffice\Console\Slides\SlidesApplication;
use MarkdownOffice\Console\Word\WordApplication;

/**
 * Every long option named anywhere in a command's own help.
 *
 * Read from the printed text rather than from the tables it is built from, because the
 * tables are where a mistake would be corrected and the printed text is what a reader
 * copies. Checking the source of the text would not have caught this.
 *
 * @return list<string>
 */
function optionsInHelp(string $help): array
{
    $found = [];

    // A long option is two dashes then a name. `--` on its own is the end-of-options
    // marker rather than an option, and `-o` is a short spelling the parser folds into
    // its long one, so neither is what is being looked for here.
    preg_match_all('/(?<![\w-])--([a-z][a-z-]*)/', $help, $matches);

    foreach ($matches[1] as $name) {
        $found[$name] = true;
    }

    $names = array_keys($found);
    sort($names);

    return $names;
}

/**
 * Every long option the given part's commands accept, together with their short
 * spellings.
 *
 * Read from each command's own specification, which is the same list the parser is
 * built from — so this cannot fall out of step with what a command accepts, and the
 * test is about the *text* disagreeing with the parser rather than two copies of the
 * parser disagreeing with each other.
 *
 * The part's own options are added on top, and are in no command's specification
 * because they are answered by the part before it reaches one: `mdoffice word
 * --version` is a question about the program, not about a conversion. They are spelled
 * out here rather than read from the source, because each part accepts them in its own
 * `run()` and a list shared with them would be a second thing to keep in step.
 *
 * @return list<string>
 */
function acceptedOptions(string $part): array
{
    $accepted = ['help' => true, 'version' => true];

    foreach (commandNamesOf($part) as $command) {
        $spec = $command::spec();

        foreach ([...$spec['values'], ...$spec['flags'], ...$spec['repeated']] as $name) {
            $accepted[$name] = true;
        }

        foreach (array_keys($spec['aliases']) as $alias) {
            $accepted[$alias] = true;
        }
    }

    $names = array_keys($accepted);
    sort($names);

    return $names;
}

/**
 * The commands a part dispatches to, taken from the part's own table.
 *
 * Read by reflection because the table is a private constant: it exists so the
 * dispatcher, the usage text and the alias list cannot disagree, and a test that
 * hardcoded a second copy would reintroduce exactly that.
 *
 * @return list<class-string>
 */
function commandNamesOf(string $part): array
{
    $reflection = new ReflectionClass($part === 'slides' ? SlidesApplication::class : WordApplication::class);
    $commands = $reflection->getConstant('COMMANDS');

    if ($commands === false) {
        throw new RuntimeException('The part has no COMMANDS table to read.');
    }

    return array_values($commands);
}

it('names no option in the word help that the word parser will not take', function () {
    $help = runCli(['word', 'help'])['out'];
    $named = optionsInHelp($help);
    $accepted = acceptedOptions('word');

    expect($named)->not->toBe([]);

    $unknown = array_values(array_diff($named, $accepted));

    // The whole point: a line copied out of this text has to run.
    expect($unknown)->toBe([], sprintf(
        "The help for `mdoffice word` names %s, which %s no option of any of its commands."
        . "\n\n%s",
        implode(', ', array_map(static fn (string $o): string => '--' . $o, $unknown)),
        $unknown === [] ? 'are' : 'is',
        $help,
    ));
});

it('names no option in the slides help that the slides parser will not take', function () {
    $help = runCli(['slides', 'help'])['out'];
    $named = optionsInHelp($help);

    expect($named)->not->toBe([]);

    $unknown = array_values(array_diff($named, acceptedOptions('slides')));

    expect($unknown)->toBe([], sprintf(
        "The help for `mdoffice slides` names %s, which no option of any of its commands takes.",
        implode(', ', array_map(static fn (string $o): string => '--' . $o, $unknown)),
    ));
});

it('names no command option in the dispatcher help', function () {
    // The dispatcher lists parts, not commands, so anything in its help that a
    // *command* takes would be an option nobody can reach: the part has to come
    // first, and the dispatcher rejects everything else as an unknown part.
    //
    // `--version` and `--help` are the dispatcher's own and belong there.
    $named = optionsInHelp(runCli(['help'])['out']);
    $reachable = array_diff($named, ['help', 'version']);

    expect($reachable)->toBe([], sprintf(
        'The help for `mdoffice` names %s, which only a part answers.',
        implode(', ', array_map(static fn (string $o): string => '--' . $o, $reachable)),
    ));
});

it('finds the options it is meant to find', function () {
    // The same reason the dead-import guard carries its own test: a pattern that stopped
    // matching would report nothing wrong with every help text in the suite.
    expect(optionsInHelp('  mdoffice word to-docx --template report.dotx --plain'))->toBe(['plain', 'template'])
        // A single dash is a short spelling, and `--` alone is the end-of-options marker.
        ->and(optionsInHelp('  mdoffice word to-docx -o out.docx -- notes.md'))->toBe([])
        // And a doubled dash inside a word is not an option.
        ->and(optionsInHelp('  a well-known note'))->toBe([]);
});

it('keeps an option the parser knows in the text', function () {
    // The other half of the guard's usefulness: it must not be satisfiable by deleting
    // the examples. Every option the commands actually accept, and the parser's own
    // alias table, are in `acceptedOptions()` by construction — so this asserts the
    // comparison is against a non-empty set on both sides.
    expect(acceptedOptions('word'))->toContain('template')
        ->and(acceptedOptions('word'))->toContain('output')
        ->and(acceptedOptions('slides'))->toContain('strict');
});

it('names no option in a command\'s own help that its parser will not take', function () {
    // The two tests above read the help a *part* prints. A command also prints its own,
    // reached by `mdoffice slides to-pptx --help`, and that text is built separately —
    // from `Command::options()`, a second list beside the specification the parser is
    // made from. Nothing compared the two, so an option table there could name anything
    // at all and the suite would be green.
    foreach (commandNamesOf('slides') as $command) {
        $name = substr($command, (int) strrpos($command, '\\') + 1);
        $run = runCli(['slides', strtolower((string) preg_replace('/(?<!^)([A-Z])/', '-$1', $name)), '--help']);

        expect($run['code'])->toBe(0)
            ->and($run['out'])->toContain('OPTIONS');

        $named = optionsInHelp($run['out']);
        $accepted = acceptedOptions('slides');
        $unknown = array_values(array_diff($named, $accepted));

        expect($named)->not->toBe([], sprintf('The help for `%s` names no option at all.', $name));
        expect($unknown)->toBe([], sprintf(
            "The help for `mdoffice slides %s` names %s, which no option takes.",
            $name,
            implode(', ', array_map(static fn (string $o): string => '--' . $o, $unknown)),
        ));
    }
});