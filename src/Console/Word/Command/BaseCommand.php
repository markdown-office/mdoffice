<?php

declare(strict_types=1);

namespace MarkdownOffice\Console\Word\Command;

use MarkdownOffice\Console\Command\Command;
use MarkdownOffice\Console\Word\WordApplication;
use MarkdownOffice\Console\CommandLine;
use MarkdownOffice\Console\ConsoleException;
use MarkdownOffice\Console\Overwrite;

/**
 * What the two directions have in common: the same command with the ends swapped,
 * so most of a run is the same either way. A subclass adds its own end of the
 * conversion and the options that configure it.
 */
abstract class BaseCommand implements Command
{
    public function __construct(protected readonly WordApplication $application)
    {
    }

    /**
     * Parse the options, answer `--help`, and check nothing contradicts.
     *
     * @param list<string> $argv
     * @return CommandLine|null Null when the run was already answered, which is
     *         only ever the help.
     * @throws ConsoleException
     */
    final protected function parseOptions(array $argv, string $summary): ?CommandLine
    {
        $command = CommandLine::parse(
            $argv,
            values: static::spec()['values'],
            flags: static::spec()['flags'],
            repeated: static::spec()['repeated'],
            aliases: static::spec()['aliases'],
            // So that reaching for an option of the other direction by mistake
            // names the command it does belong to.
            foreign: WordApplication::specFor(static::other()),
        );

        if ($command->flag('help')) {
            $this->application->print(WordApplication::commandHelp(static::class, $summary));

            return null;
        }

        static::assertDirection($command);

        return $command;
    }

    /**
     * Where the result should go, refusing to put it on top of its own input.
     *
     * Without the second half a run with no `-o` on a file whose extension is
     * already the one being written to would destroy that file.
     */
    final protected function outputPath(CommandLine $command, string $extension): string
    {
        $input = $command->input();
        $output = $this->application->outputPath($input, $extension, $command->value('output'));

        $this->guardAgainstOverwrite($input, $output);

        return $output;
    }

    /**
     * Refuse a result that would land on the file it was made from.
     *
     * The check itself is in {@see \MarkdownOffice\Console\Overwrite}, because both
     * halves of the tool can be pointed at the file they are converting and a
     * conversion that overwrites its own input destroys it.
     */
    final protected function guardAgainstOverwrite(?string $input, string $output): void
    {
        Overwrite::refuse($input, $output);
    }

    /**
     * Say on standard error what the run converted and where it put it. `-` is how
     * a stream is asked for, so a person is told which stream rather than being
     * shown a dash.
     */
    final protected function report(?string $input, string $output): void
    {
        $this->application->progress(sprintf(
            '%s → %s',
            $input === null || $input === '-' ? 'standard input' : $input,
            $output === '-' ? 'standard output' : $output,
        ));
    }

    /**
     * A `--to` naming this direction is redundant; one naming the other is a
     * contradiction worth reporting.
     */
    final protected static function assertDirection(CommandLine $command): void
    {
        $asked = strtolower((string) $command->value('to'));

        if ($asked === '' || in_array($asked, static::formats(), true)) {
            return;
        }

        throw new ConsoleException(
            sprintf(
                '--to %s does not match what this reads; it takes %s.',
                $asked,
                implode(' or ', static::formats()),
            ),
            ['Omit it and let mdoffice word work the direction out from the file.'],
        );
    }

    /**
     * @return class-string<Command>
     */
    abstract protected static function other(): string;

    /**
     * The formats `--to` may name for this direction.
     *
     * @return list<string>
     */
    abstract protected static function formats(): array;
}
