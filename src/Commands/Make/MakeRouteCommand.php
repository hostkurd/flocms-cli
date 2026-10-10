<?php
declare(strict_types=1);

namespace FloCMS\CLI\Commands\Make;

use FloCMS\CLI\Console\Input\Argument;
use FloCMS\CLI\Console\Input\Definition;
use FloCMS\CLI\Console\Input\Option;
use FloCMS\CLI\Support\Name;

final class MakeRouteCommand extends GeneratorCommand
{
    protected string $name = 'make:route';

    protected string $description = 'Create a controller, its model and a view';

    protected array $aliases = ['create:route'];

    protected array $examples = [
        'php flo make:route Blog',
        'php flo make:route Blog show',
        'php flo make:route Reports index --admin',
    ];

    protected function arguments(Definition $definition): void
    {
        $definition
            ->argument('name', Argument::REQUIRED, 'The route / controller name')
            ->argument('view', Argument::OPTIONAL, 'The first view', 'index')
            ->option('admin', null, Option::VALUE_NONE, 'Admin controller and view')
            ->option('resource', 'r', Option::VALUE_NONE, 'Public and admin CRUD actions')
            ->option('table', 't', Option::VALUE_REQUIRED, 'The model\'s table name');
    }

    protected function generate(): int
    {
        $name = Name::studly((string) $this->argument('name'), 'route name', 'Controller');
        $table = $this->option('table') !== null
            ? Name::snake((string) $this->option('table'), 'table name')
            : self::plural(Name::snake($name));

        $this->create('models/' . $name . 'Model.php', $this->stub('model', ['class' => $name . 'Model', 'table' => $table]));
        $this->create('controllers/' . $name . 'Controller.php', MakeControllerCommand::render($this, $name, true));
        MakeViewCommand::addView($this, $name, (string) $this->argument('view'), (bool) $this->option('admin'));

        return self::SUCCESS;
    }
}
