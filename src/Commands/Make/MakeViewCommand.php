<?php
declare(strict_types=1);

namespace FloCMS\CLI\Commands\Make;

use FloCMS\CLI\Console\Input\Argument;
use FloCMS\CLI\Console\Input\Definition;
use FloCMS\CLI\Console\Input\Option;
use FloCMS\CLI\Support\Name;
use FloCMS\CLI\Support\PhpClassEditor;

final class MakeViewCommand extends GeneratorCommand
{
    protected string $name = 'make:view';

    protected string $description = 'Create a view and add its action to the controller';

    protected array $aliases = ['create:view'];

    protected string $help = <<<'TXT'
Views live in views/<controller>/<action>.html (admin views: admin_<action>.html).
When the controller exists, the matching action method is added to it.
TXT;

    protected array $examples = [
        'php flo make:view Blog show',
        'php flo make:view Blog edit --admin',
    ];

    protected function arguments(Definition $definition): void
    {
        $definition
            ->argument('controller', Argument::REQUIRED, 'The controller (Blog, blog-post)')
            ->argument('view', Argument::OPTIONAL, 'The view / action name', 'index')
            ->option('admin', null, Option::VALUE_NONE, 'An admin view (admin_ prefix)');
    }

    protected function generate(): int
    {
        $controller = Name::studly((string) $this->argument('controller'), 'controller name', 'Controller');
        self::addView($this, $controller, (string) $this->argument('view'), (bool) $this->option('admin'));

        return self::SUCCESS;
    }

    /** Shared with make:route. */
    public static function addView(GeneratorCommand $command, string $controller, string $view, bool $admin): void
    {
        $view = Name::action($view);
        $action = strtolower($view);
        if ($admin && !str_starts_with($action, 'admin_')) {
            $action = 'admin_' . $action;
        }
        $directory = Name::kebab($controller);
        $path = $directory . '/' . $action . '.html';
        $url = (str_starts_with($action, 'admin_') ? 'admin/' : '') . $directory . '/' . (str_starts_with($action, 'admin_') ? substr($action, 6) : $action);

        $created = $command->create('views/' . $path, $command->stub('view', [
            'title' => self::humanize($controller),
            'view' => $action,
            'controller' => $controller . 'Controller',
            'path' => $path,
        ]));

        $file = 'controllers/' . $controller . 'Controller.php';
        if (!$created || !$command->files()->exists($file)) {
            return;
        }
        $code = $command->files()->read($file);
        if (PhpClassEditor::hasMethod($code, $action)) {
            return;
        }
        $command->update($file, PhpClassEditor::addMethod($code, $command->stub('action', [
            'action' => $action,
            'url' => $url,
            'title' => self::humanize($controller),
        ])), 'Updated');
    }
}
