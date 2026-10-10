<?php
declare(strict_types=1);

namespace FloCMS\CLI\Commands\Legacy;

use FloCMS\CLI\Console\Command;
use FloCMS\CLI\Console\Input\Argument;
use FloCMS\CLI\Console\Input\Definition;
use FloCMS\CLI\Core;
use FloCMS\CLI\Support\Name;

/**
 * The 1.x create:* and delete:* commands, run through the new kernel until
 * the make:* generators replace them.
 */
final class LegacyCommand extends Command
{
    public function __construct(string $name, string $description, private readonly string $method, private readonly bool $takesView)
    {
        $this->name = $name;
        $this->description = $description;
    }

    /** @return list<self> */
    public static function all(): array
    {
        return [
            new self('create:controller', 'Create a controller', 'CreateController', false),
            new self('create:model', 'Create a model', 'CreateModel', false),
            new self('create:view', 'Create a view and its controller action', 'CreateView', true),
            new self('create:route', 'Create a controller, model and view', 'CreateRoute', true),
            new self('delete:controller', 'Delete a controller', 'DeleteController', false),
            new self('delete:model', 'Delete a model', 'DeleteModel', false),
            new self('delete:view', 'Delete a view and its controller action', 'DeleteView', true),
            new self('delete:route', 'Delete a controller, model and view', 'DeleteRoute', true),
        ];
    }

    protected function configure(Definition $definition): void
    {
        $definition->argument('name', Argument::OPTIONAL, 'Controller name');
        if ($this->takesView) {
            $definition->argument('view', Argument::OPTIONAL, 'View name');
        }
    }

    protected function handle(): int
    {
        $command = ['flo', $this->name];
        if ($this->input->argument('name') !== null) {
            $command[] = Name::validate((string) $this->input->argument('name'), 'controller name');
        }
        if ($this->input->hasArgument('view') && $this->input->argument('view') !== null) {
            $command[] = Name::action((string) $this->input->argument('view'));
        }
        $data = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/data.json'), true);
        $core = new Core($command, $data, $this->project()->root());
        $this->line((string) $core->{$this->method}());

        return self::SUCCESS;
    }
}
