<?php
declare(strict_types=1);

namespace FloCMS\CLI\Commands\Make;

use FloCMS\CLI\Console\Input\Argument;
use FloCMS\CLI\Console\Input\Definition;
use FloCMS\CLI\Console\Input\Option;
use FloCMS\CLI\Support\Name;

final class MakeApiControllerCommand extends GeneratorCommand
{
    protected string $name = 'make:api-controller';

    protected string $description = 'Create an API controller in api/Controllers (App\Api\Controllers)';

    protected array $examples = [
        'php flo make:api-controller Posts',
        'php flo make:api-controller Posts --resource',
    ];

    protected function arguments(Definition $definition): void
    {
        $definition
            ->argument('name', Argument::REQUIRED, 'The controller name')
            ->option('resource', 'r', Option::VALUE_NONE, 'index, store, show, update and destroy actions');
    }

    protected function generate(): int
    {
        $name = Name::studly((string) $this->argument('name'), 'controller name', 'Controller');
        $route = Name::kebab($name);
        $written = $this->create('api/Controllers/' . $name . 'Controller.php', $this->stub($this->option('resource') ? 'api-controller.resource' : 'api-controller', [
            'class' => $name . 'Controller',
            'route' => $route,
            'tag' => self::humanize($name),
            'singular' => strtolower(self::humanize($name)),
        ]));
        if ($written) {
            $this->line('Register its routes in <comment>api/routes.php</comment> (see the comment at the top of the controller).');
        }

        return self::SUCCESS;
    }
}
