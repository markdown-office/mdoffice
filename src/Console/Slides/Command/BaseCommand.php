<?php

declare(strict_types=1);

namespace MarkdownOffice\Console\Slides\Command;

use MarkdownOffice\Console\Command\Command;
use MarkdownOffice\Console\CommandLine;
use MarkdownOffice\Console\CommandReference;
use MarkdownOffice\Console\ConsoleException;
use MarkdownOffice\Console\Overwrite;
use MarkdownOffice\Console\Slides\SlidesApplication;

/**
 * What the two directions have in common: the same part of the tool, the same
 * options around them, and the same guard against a result landing on the file
 * it was made from.
 *
 * A subclass adds its own end of the conversion and the options that configure
 * it.
 */
abstract class BaseCommand implements Command
{
    public function __construct(protected readonly SlidesApplication $application)
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
            foreign: CommandReference::specFor(static::other()),
        );

        if ($command->flag('help')) {
            $this->application->print(CommandReference::help(SlidesApplication::NAME, static::class, $summary));

            return null;
        }

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

        Overwrite::refuse($input, $output);

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
     * The command on the other end of the conversion.
     *
     * @return class-string<Command>
     */
    abstract protected static function other(): string;
}
