<?php
declare(strict_types=1);

namespace FloCMS\CLI\Commands\Make;

use FloCMS\CLI\Console\Input\Argument;
use FloCMS\CLI\Console\Input\Definition;
use FloCMS\CLI\Console\Input\InputException;
use FloCMS\CLI\Console\Input\Option;
use FloCMS\CLI\Support\Name;

final class MakeCommandCommand extends SimpleClassGenerator
{
    protected string $name = 'make:command';

    protected string $description = 'Create a console command in commands/ (App\Commands)';

    protected array $examples = [
        'php flo make:command SendReports',
        'php flo make:command SendReports --command=reports:send',
    ];

    protected string $stubName = 'command';

    protected string $suffix = 'Command';

    protected string $directory = 'commands';

    protected function arguments(Definition $definition): void
    {
        $definition
            ->argument('name', Argument::REQUIRED, 'The class name')
            ->option('command', 'c', Option::VALUE_REQUIRED, 'The command name (default: app:<name>)');
    }

    protected function replacements(string $name, string $class): array
    {
        $command = (string) ($this->option('command') ?? 'app:' . Name::kebab($name));
        if (preg_match('/^[a-z][a-z0-9-]*(:[a-z][a-z0-9-]*)*$/D', $command) !== 1) {
            throw new InputException('Invalid command name "' . $command . '": use lowercase words separated by ":" or "-".');
        }

        return ['class' => $class, 'command' => $command];
    }
}
