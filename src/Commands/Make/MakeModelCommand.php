<?php
declare(strict_types=1);

namespace FloCMS\CLI\Commands\Make;

use FloCMS\CLI\Console\Input\Argument;
use FloCMS\CLI\Console\Input\Definition;
use FloCMS\CLI\Console\Input\Option;
use FloCMS\CLI\Support\Name;
use FloCMS\CLI\Support\PhpClassEditor;

final class MakeModelCommand extends GeneratorCommand
{
    protected string $name = 'make:model';

    protected string $description = 'Create a model in models/';

    protected array $aliases = ['create:model'];

    protected array $examples = [
        'php flo make:model Post',
        'php flo make:model BlogPost --table=posts --migration',
    ];

    protected function arguments(Definition $definition): void
    {
        $definition
            ->argument('name', Argument::REQUIRED, 'The model name (Post, BlogPost)')
            ->option('table', 't', Option::VALUE_REQUIRED, 'The table name (default: the plural snake_case name)')
            ->option('migration', null, Option::VALUE_NONE, 'Also create a migration for the table');
    }

    protected function generate(): int
    {
        $name = Name::studly((string) $this->argument('name'), 'model name', 'Model');
        $table = $this->option('table') !== null
            ? Name::snake((string) $this->option('table'), 'table name')
            : self::plural(Name::snake($name));

        $this->create('models/' . $name . 'Model.php', $this->stub('model', [
            'class' => $name . 'Model',
            'table' => $table,
        ]));

        // Wire an existing controller to the new model
        $controller = 'controllers/' . $name . 'Controller.php';
        if ($this->files()->exists($controller)) {
            $code = $this->files()->read($controller);
            if (!str_contains($code, $name . 'Model')) {
                $code = PhpClassEditor::addUse($code, 'FloCMS\\Models\\' . $name . 'Model');
                $code = PhpClassEditor::addMethod($code, trim($this->stub('controller.model', ['modelClass' => $name . 'Model']), "\n"));
                $this->update($controller, $code, 'Updated');
            }
        }

        if ($this->option('migration')) {
            return $this->call('make:migration', [...$this->passOptions(), 'create_' . $table . '_table', '--create=' . $table]);
        }

        return self::SUCCESS;
    }
}
