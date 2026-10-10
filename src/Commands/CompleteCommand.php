<?php
declare(strict_types=1);

namespace FloCMS\CLI\Commands;

use FloCMS\CLI\Console\Application;
use FloCMS\CLI\Console\Command;
use FloCMS\CLI\Console\Input\Argument;
use FloCMS\CLI\Console\Input\Definition;
use FloCMS\CLI\Console\Input\Option;
use Throwable;

/**
 * Used by the completion scripts: prints the candidates for the word at
 * --index (1 = the command name) of the words after "flo".
 */
final class CompleteCommand extends Command
{
    protected string $name = '_complete';

    protected string $description = 'Completion candidates (used by the completion scripts)';

    protected bool $hidden = true;

    protected function configure(Definition $definition): void
    {
        $definition
            ->option('index', 'i', Option::VALUE_REQUIRED, 'Position of the word being completed (1 = the command)', '1')
            ->argument('words', Argument::IS_ARRAY, 'The words after flo');
    }

    protected function handle(): int
    {
        foreach ($this->candidates((array) $this->argument('words'), max(1, (int) $this->option('index'))) as $candidate) {
            $this->output->write($candidate . "\n");
        }

        return self::SUCCESS;
    }

    /**
     * @param list<string> $words
     * @return list<string>
     */
    public function candidates(array $words, int $index): array
    {
        $application = $this->getApplication();
        if ($application === null) {
            return [];
        }
        $current = (string) ($words[$index - 1] ?? '');
        if ($index === 1) {
            $names = [];
            foreach ($application->all() as $name => $command) {
                if (!$command->isHidden()) {
                    $names[] = $name;
                    array_push($names, ...$command->getAliases());
                }
            }
            sort($names);

            return array_values(array_filter($names, static fn (string $n): bool => str_starts_with($n, $current)));
        }
        if (!str_starts_with($current, '-')) {
            return [];
        }
        try {
            $command = $application->find((string) ($words[0] ?? ''));
        } catch (Throwable) {
            return [];
        }
        $options = [];
        foreach ($command->getDefinition()->merge(Application::globalDefinition())->options() as $option) {
            $options[] = '--' . $option->name . ($option->requiresValue() ? '=' : '');
        }

        return array_values(array_filter($options, static fn (string $o): bool => str_starts_with($o, $current)));
    }
}
