<?php
declare(strict_types=1);

namespace FloCMS\CLI\Commands\Route;

use FloCMS\CLI\Console\Command;
use FloCMS\CLI\Console\Input\Definition;
use FloCMS\CLI\Console\Input\Option;
use FloCMS\CLI\Routing\LegacyRoutes;
use FloCMS\CLI\Support\Permissions;
use FloCMS\Core\Config;
use ReflectionClass;

final class PermissionListCommand extends Command
{
    protected string $name = 'permission:list';

    protected string $description = 'List roles with their permissions, and the permission each controller action needs';

    protected function configure(Definition $definition): void
    {
        $definition->option('format', null, Option::VALUE_REQUIRED, 'txt or json', 'txt');
    }

    protected function handle(): int
    {
        $project = $this->project();
        $project->boot();
        $map = Config::get('permissions');
        $map = is_array($map) ? $map : [];
        $names = Config::get('roles');
        $names = is_array($names) ? $names : Permissions::ROLES;

        $actions = [];
        foreach ((new LegacyRoutes())->controllers($project) as $class) {
            $reflection = new ReflectionClass($class);
            $permissions = $reflection->getDefaultProperties()['actionPermissions'] ?? [];
            foreach (is_array($permissions) ? $permissions : [] as $action => $permission) {
                $actions[] = [
                    'controller' => $reflection->getShortName(),
                    'action' => (string) $action,
                    'permission' => $permission === null ? null : (string) $permission,
                    'roles' => $permission === null ? [] : Permissions::rolesWith($map, (string) $permission),
                ];
            }
        }

        if ($this->option('format') === 'json') {
            $this->output->write(json_encode(['roles' => $map, 'actions' => $actions], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

            return self::SUCCESS;
        }

        $this->section('Roles (config "permissions")');
        if ($map === []) {
            $this->line('  No role map: admin pages only check the admin_access session flag.');
        } else {
            $this->table(['Role', 'Permissions'], array_map(
                static fn ($role, $granted): array => [Permissions::roleName($role, $names), implode(', ', (array) $granted) ?: '(none)'],
                array_keys($map),
                array_values($map)
            ));
        }

        $this->section('Controller actions ($actionPermissions)');
        if ($actions === []) {
            $this->line('  No controller declares $actionPermissions.');

            return self::SUCCESS;
        }
        $this->table(['Controller', 'Action', 'Permission', 'Roles with access'], array_map(static fn (array $a): array => [
            $a['controller'],
            $a['action'],
            $a['permission'] ?? '(none: open to every admin)',
            $a['permission'] === null ? '' : (implode(', ', array_map(static fn ($r) => Permissions::roleName($r, $names), $a['roles'])) ?: 'no role'),
        ], $actions));

        return self::SUCCESS;
    }
}
