<?php
declare(strict_types=1);

namespace FloCMS\CLI\Commands\Make;

use FloCMS\CLI\Console\Command;
use FloCMS\CLI\Console\Input\Argument;
use FloCMS\CLI\Console\Input\Definition;
use FloCMS\CLI\Console\Input\Option;
use FloCMS\CLI\Console\Output\Formatter;
use FloCMS\CLI\Support\Filesystem;
use FloCMS\CLI\Support\Name;
use FloCMS\CLI\Support\PhpClassEditor;

/**
 * delete:controller, delete:model, delete:view and delete:route (aliases
 * remove:*). Shows what will change and asks first, unless --force.
 */
final class DeleteCommand extends Command
{
    /** @var list<array{type: 'delete'|'edit', path: string, note: string, contents?: string}> */
    private array $plan = [];

    private function __construct(string $kind, string $description)
    {
        $this->name = 'delete:' . $kind;
        $this->aliases = ['remove:' . $kind];
        $this->description = $description;
    }

    /** @return list<self> */
    public static function all(): array
    {
        return [
            new self('controller', 'Delete a controller'),
            new self('model', 'Delete a model and unlink it from its controller'),
            new self('view', 'Delete a view and its controller action'),
            new self('route', 'Delete a controller, its model and its views'),
        ];
    }

    protected function configure(Definition $definition): void
    {
        $kind = substr($this->name, 7);
        $definition->argument('name', Argument::REQUIRED, $kind === 'model' ? 'The model name' : 'The controller name');
        if ($kind === 'view') {
            $definition
                ->argument('view', Argument::REQUIRED, 'The view / action name')
                ->option('admin', null, Option::VALUE_NONE, 'An admin view (admin_ prefix)');
        }
        $definition
            ->option('force', 'f', Option::VALUE_NONE, 'Delete without asking')
            ->option('dry-run', null, Option::VALUE_NONE, 'Only show what would be deleted');
    }

    protected function handle(): int
    {
        $this->plan = [];
        $files = new Filesystem($this->project());
        $name = Name::studly((string) $this->argument('name'), 'name', substr($this->name, 7) === 'model' ? 'Model' : 'Controller');

        match (substr($this->name, 7)) {
            'controller' => $this->planDelete($files, 'controllers/' . $name . 'Controller.php'),
            'model' => $this->planModel($files, $name),
            'view' => $this->planView($files, $name, (string) $this->argument('view'), (bool) $this->option('admin')),
            'route' => $this->planRoute($files, $name),
        };

        if ($this->plan === []) {
            $this->fail('Nothing to delete for "' . $name . '".');
        }

        $this->line('This will change:');
        foreach ($this->plan as $step) {
            $this->line('  <comment>' . ($step['type'] === 'delete' ? 'delete' : 'edit  ') . '</comment> ' . Formatter::escape($step['path']) . ($step['note'] !== '' ? ' <muted>(' . $step['note'] . ')</muted>' : ''));
        }
        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }
        if (!$this->option('force')) {
            if (!$this->input->isInteractive()) {
                $this->fail('Not deleting without confirmation. Run interactively or pass --force.');
            }
            if (!$this->confirm('Continue?', false)) {
                $this->comment('Nothing was deleted.');

                return self::FAILURE;
            }
        }

        foreach ($this->plan as $step) {
            if ($step['type'] === 'delete') {
                $files->delete($step['path']);
                $files->deleteEmptyDirectory(dirname($step['path']));
            } else {
                $files->write($step['path'], $step['contents'] ?? '');
            }
        }
        $this->success(count($this->plan) . ' change(s) made.');

        return self::SUCCESS;
    }

    private function planDelete(Filesystem $files, string $path, string $note = ''): void
    {
        if ($files->exists($path)) {
            $this->plan[] = ['type' => 'delete', 'path' => $path, 'note' => $note];
        }
    }

    private function planModel(Filesystem $files, string $name, bool $unlink = true): void
    {
        $this->planDelete($files, 'models/' . $name . 'Model.php');
        $controller = 'controllers/' . $name . 'Controller.php';
        if ($unlink && $files->exists($controller) && $this->plan !== []) {
            $code = $files->read($controller);
            $changed = PhpClassEditor::removeUse($code, 'FloCMS\\Models\\' . $name . 'Model');
            if (PhpClassEditor::hasMethod($changed, 'model') && str_contains((string) self::method($changed, 'model'), $name . 'Model')) {
                $changed = PhpClassEditor::removeMethod($changed, 'model');
            }
            // 1.x wiring: $this->model = new XModel();
            $changed = (string) preg_replace('/^[ \t]*\$this->model\s*=\s*new\s+' . preg_quote($name, '/') . 'Model\s*\(\s*\)\s*;[ \t]*\R/m', '', $changed);
            if ($changed !== $code) {
                $this->plan[] = ['type' => 'edit', 'path' => $controller, 'note' => 'remove the model wiring', 'contents' => $changed];
            }
        }
    }

    private function planView(Filesystem $files, string $controller, string $view, bool $admin): void
    {
        $action = strtolower(Name::action($view));
        if ($admin && !str_starts_with($action, 'admin_')) {
            $action = 'admin_' . $action;
        }
        $this->planDelete($files, 'views/' . Name::kebab($controller) . '/' . $action . '.html');
        $file = 'controllers/' . $controller . 'Controller.php';
        if ($files->exists($file)) {
            $code = $files->read($file);
            if (PhpClassEditor::hasMethod($code, $action)) {
                $this->plan[] = ['type' => 'edit', 'path' => $file, 'note' => 'remove the ' . $action . '() action', 'contents' => PhpClassEditor::removeMethod($code, $action)];
            }
        }
    }

    private function planRoute(Filesystem $files, string $name): void
    {
        $directory = 'views/' . Name::kebab($name);
        $views = glob($files->safePath($directory) . DIRECTORY_SEPARATOR . '*.html') ?: [];
        sort($views);
        foreach ($views as $view) {
            $this->planDelete($files, $directory . '/' . basename($view));
        }
        $this->planModel($files, $name, false);
        $this->planDelete($files, 'controllers/' . $name . 'Controller.php');
    }

    private static function method(string $code, string $method): ?string
    {
        $range = PhpClassEditor::methodRange($code, $method);

        return $range === null ? null : substr($code, $range[0], $range[1] - $range[0]);
    }
}
