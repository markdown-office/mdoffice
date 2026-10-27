<?php

declare(strict_types=1);

namespace MarkdownOffice\Console;

use MarkdownOffice\Console\Command\Command;

/**
 * What the commands of a part are called, and what they take.
 *
 * The two halves of the tool have the same shape — a command is typed under a name,
 * takes options out of a specification, and prints a help text — so the shape is
 * described in one place and the halves differ only in the commands they list. This
 * is that place: it is here rather than in {@see PartApplication} because a part
 * runs its commands, and describing them is a different job from running them.
 */
final class CommandReference
{
    /**
     * The parser specification of one command, for telling a person which command
     * an option they used actually belongs to.
     *
     * @param class-string<Command> $command
     * @return array<string, string>
     */
    public static function specFor(string $command): array
    {
        $foreign = [];
        $name = self::commandName($command);

        foreach (self::names($command::spec()) as $option) {
            $foreign[$option] = $name;
        }

        return $foreign;
    }

    /**
     * Every option name a specification mentions.
     *
     * @param array{values: list<string>, flags: list<string>, repeated: list<string>, aliases: array<string, string>} $spec
     * @return list<string>
     */
    public static function names(array $spec): array
    {
        return array_values(array_unique(array_merge(
            $spec['values'],
            $spec['flags'],
            $spec['repeated'],
            array_values($spec['aliases']),
        )));
    }

    /**
     * The help text of one command.
     *
     * @param string $part The part as it is typed on a command line.
     * @param class-string<Command> $command
     * @param string|null $usage The usage line, for the command whose own is not
     *        the usual one.
     */
    public static function help(string $part, string $command, string $summary, ?string $usage = null): string
    {
        // The name as it is typed, not as the heading spells it out: the heading
        // reads "TO DOCX" and the usage line has to read `to-docx`.
        $name = self::commandName($command);

        return implode(PHP_EOL, [
            $part . ' ' . $name . ' — ' . $summary,
            '',
            'USAGE',
            '  ' . ($usage ?? sprintf('%s %s [<file>] [options]', $part, $name)),
            '',
            'OPTIONS',
            ...self::describeCommand($command),
            '',
        ]) . PHP_EOL;
    }

    /**
     * The heading and option table for one command.
     *
     * @param class-string<Command> $command
     * @return list<string>
     */
    public static function describeCommand(string $command): array
    {
        // `ToDocx` reads as "to docx", which is how the command is written.
        $short = (string) preg_replace('/(?<!^)[A-Z]/', ' $0', substr($command, (int) strrpos($command, '\\') + 1));
        $heading = strtoupper(str_replace('_', ' ', $short));
        $lines = [$heading, str_repeat('-', strlen($heading))];

        foreach ($command::options() as [$flags, $description]) {
            $lines[] = sprintf('  %-30s %s', $flags, $description);
        }

        return $lines;
    }

    /**
     * One section per command over the option table each one publishes, each followed
     * by a blank line.
     *
     * @param array<string, class-string<Command>> $commands
     * @return list<string>
     */
    public static function sections(array $commands): array
    {
        $lines = [];

        foreach ($commands as $class) {
            foreach (self::describeCommand($class) as $line) {
                $lines[] = $line;
            }

            $lines[] = '';
        }

        return $lines;
    }

    /**
     * @param class-string<Command> $command
     */
    private static function commandName(string $command): string
    {
        // `ToMarkdown` reads as `to-markdown`. The matched letter is kept by
        // referring to it in the replacement; replacing it outright would give
        // `to-arkdown`.
        $short = substr($command, (int) strrpos($command, '\\') + 1);

        return strtolower((string) preg_replace('/(?<!^)([A-Z])/', '-$1', $short));
    }
}
