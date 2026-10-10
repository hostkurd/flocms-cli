<?php
declare(strict_types=1);

namespace FloCMS\CLI\Commands\Make;

use FloCMS\CLI\Console\Input\Argument;
use FloCMS\CLI\Console\Input\Definition;
use FloCMS\CLI\Support\Name;

final class MakeModuleCommand extends GeneratorCommand
{
    protected string $name = 'make:module';

    protected string $description = 'Create a module in modules/<id> (manifest and service provider)';

    protected string $help = <<<'TXT'
Creates modules/<id>/module.php and a service provider in modules/<id>/src.
See the flocms-core README ("Module manifest") for the manifest keys.
TXT;

    protected array $examples = ['php flo make:module news'];

    protected function arguments(Definition $definition): void
    {
        $definition->argument('id', Argument::REQUIRED, 'The module id: lowercase letters, digits and dashes (news, shop-orders)');
    }

    protected function generate(): int
    {
        $id = Name::kebab((string) $this->argument('id'), 'module id');
        $studly = Name::studly($id, 'module id');
        $namespace = 'App\\Modules\\' . $studly;
        $provider = $studly . 'ServiceProvider';

        $this->create('modules/' . $id . '/module.php', $this->stub('module', [
            'id' => $id,
            'title' => self::humanize($studly),
            'namespace' => $namespace,
            'namespaceKey' => str_replace('\\', '\\\\', $namespace),
            'provider' => $provider,
        ]));
        $this->create('modules/' . $id . '/src/' . $provider . '.php', $this->stub('module.provider', [
            'namespace' => $namespace,
            'provider' => $provider,
        ]));

        return self::SUCCESS;
    }
}
