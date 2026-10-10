<?php
declare(strict_types=1);

namespace FloCMS\CLI\Commands\Make;

use FloCMS\CLI\Console\Input\Argument;
use FloCMS\CLI\Console\Input\Definition;
use FloCMS\CLI\Console\Input\Option;
use FloCMS\CLI\Support\Name;

final class MakeControllerCommand extends GeneratorCommand
{
    protected string $name = 'make:controller';

    protected string $description = 'Create a controller in controllers/';

    protected array $aliases = ['create:controller'];

    protected string $help = <<<'TXT'
The URL of a controller is its name in lowercase with dashes:
BlogPostController answers /blog-post (and /admin/blog-post for admin_ actions).
--model also creates the model and adds a lazy model() accessor.
TXT;

    protected array $examples = [
        'php flo make:controller Blog',
        'php flo make:controller BlogPost --resource --model',
        'php flo make:controller Reports --admin',
        'php flo make:controller Posts --api',
    ];

    protected function arguments(Definition $definition): void
    {
        $definition
            ->argument('name', Argument::REQUIRED, 'The controller name (Blog, BlogPost, blog-post)')
            ->option('admin', null, Option::VALUE_NONE, 'Admin actions (admin_index) with a permission check')
            ->option('resource', 'r', Option::VALUE_NONE, 'Public and admin CRUD actions')
            ->option('model', 'm', Option::VALUE_NONE, 'Also create the model')
            ->option('api', null, Option::VALUE_NONE, 'Create an API controller in api/Controllers instead (make:api-controller)');
    }

    protected function generate(): int
    {
        $name = Name::studly((string) $this->argument('name'), 'controller name', 'Controller');
        if ($this->option('api')) {
            $options = $this->passOptions();
            if ($this->option('resource')) {
                $options[] = '--resource';
            }

            return $this->call('make:api-controller', [...$options, $name]);
        }

        if ($this->option('model')) {
            $code = $this->call('make:model', [...array_values(array_filter($this->passOptions(), static fn (string $o): bool => $o !== '--force')), $name]);
            if ($code !== self::SUCCESS && !$this->files()->exists('models/' . $name . 'Model.php') && !$this->isDryRun()) {
                return $code;
            }
        }

        $this->create('controllers/' . $name . 'Controller.php', self::render($this, $name, $this->option('model') || $this->files()->exists('models/' . $name . 'Model.php')));

        return self::SUCCESS;
    }

    /** The controller source; shared with make:route. */
    public static function render(GeneratorCommand $command, string $name, bool $withModel): string
    {
        $stub = match (true) {
            (bool) $command->option('resource') => 'controller.resource',
            (bool) $command->option('admin') => 'controller.admin',
            default => 'controller',
        };

        return $command->stub($stub, [
            'class' => $name . 'Controller',
            'route' => Name::kebab($name),
            'title' => self::humanize($name),
            'permission' => Name::snake($name),
            'uses' => $withModel ? "\nuse FloCMS\\Models\\{$name}Model;" : '',
            'model' => $withModel ? $command->stub('controller.model', ['modelClass' => $name . 'Model']) : '',
        ]);
    }
}
