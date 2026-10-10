<?php
declare(strict_types=1);

namespace FloCMS\CLI\Commands;

use FloCMS\CLI\Console\Command;
use FloCMS\CLI\Console\Descriptor;
use FloCMS\CLI\Console\Input\Argument;
use FloCMS\CLI\Console\Input\Definition;
use FloCMS\CLI\Console\Input\Option;

final class HelpCommand extends Command
{
    protected string $name = 'help';

    protected string $description = 'Show the usage, arguments and options of a command';

    protected array $examples = [
        'php flo help make:controller',
        'php flo make:controller --help',
        'php flo help migrate --format=json',
    ];

    protected function configure(Definition $definition): void
    {
        $definition
            ->argument('command_name', Argument::OPTIONAL, 'The command (abbreviations work)', 'help')
            ->option('format', null, Option::VALUE_REQUIRED, 'Output format: txt or json', 'txt');
    }

    protected function handle(): int
    {
        $application = $this->getApplication() ?? $this->fail('No application.');
        $command = $application->find((string) $this->argument('command_name'));
        $descriptor = new Descriptor($application);

        if ($this->option('format') === 'json') {
            $this->output->write(json_encode($descriptor->commandAsArray($command), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

            return self::SUCCESS;
        }
        if ($this->option('format') !== 'txt') {
            $this->invalid('Unknown format "' . $this->option('format') . '" (use txt or json).');
        }
        $descriptor->describeCommand($this->output, $command);

        return self::SUCCESS;
    }
}
