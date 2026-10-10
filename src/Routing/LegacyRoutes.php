<?php
declare(strict_types=1);

namespace FloCMS\CLI\Routing;

use FloCMS\CLI\Project;
use FloCMS\CLI\Support\Name;
use FloCMS\Core\Config;
use FloCMS\Core\Controller;
use ReflectionClass;
use ReflectionMethod;

/**
 * The convention-based routes of controllers/ (flocms-core's Router):
 * /[route]/<controller>/<action>, where the route ("admin", "login", ...)
 * maps to a method prefix (admin_, login_) in Config 'routes'.
 */
final class LegacyRoutes implements RouteProvider
{
    public function source(): string
    {
        return 'web';
    }

    public function routes(Project $project): iterable
    {
        $project->boot();
        $prefixes = Config::get('routes');
        if (!is_array($prefixes) || $prefixes === []) {
            $prefixes = ['default' => '', 'admin' => 'admin_'];
        }
        $defaultRoute = (string) Config::get('default_route', 'default');
        $defaultController = strtolower((string) Config::get('default_controller', 'pages'));
        $defaultAction = strtolower((string) Config::get('default_action', 'index'));

        // Longest prefix first, so admin_index belongs to "admin", not "default"
        uasort($prefixes, static fn ($a, $b): int => strlen((string) $b) <=> strlen((string) $a));

        foreach ($this->controllers($project) as $class) {
            $reflection = new ReflectionClass($class);
            if ($reflection->isAbstract()) {
                continue;
            }
            $controller = Name::kebab(substr($reflection->getShortName(), 0, -strlen('Controller')));
            $permissions = $reflection->getDefaultProperties()['actionPermissions'] ?? [];
            $permissions = is_array($permissions) ? $permissions : [];

            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->isStatic() || $method->isConstructor() || str_starts_with($method->getName(), '__')
                    || $method->getDeclaringClass()->getName() === Controller::class) {
                    continue;
                }
                $name = strtolower($method->getName());
                foreach ($prefixes as $route => $prefix) {
                    $prefix = strtolower((string) $prefix);
                    if ($prefix !== '' && !str_starts_with($name, $prefix)) {
                        continue;
                    }
                    if ($prefix === '' && self::hasOtherPrefix($name, $prefixes)) {
                        continue 2;
                    }
                    $action = substr($name, strlen($prefix));
                    $isDefaultRoute = $route === $defaultRoute;
                    $uri = '/' . ($isDefaultRoute ? '' : $route . '/') . $controller . '/' . $action;
                    if ($isDefaultRoute && $controller === $defaultController && $action === $defaultAction) {
                        $uri = '/';
                    }
                    yield [
                        'method' => 'ANY',
                        'uri' => $uri,
                        'name' => null,
                        'action' => $reflection->getShortName() . '@' . $method->getName(),
                        'permission' => self::permissionFor($permissions, $name),
                        'middleware' => $route === 'admin' && $name !== 'admin_login' ? ['admin'] : [],
                    ];
                    continue 2;
                }
                // A prefix whose route is switched off (e.g. api_ without LEGACY_API): not reachable
            }
        }
    }

    /** @param array<string, string> $prefixes */
    private static function hasOtherPrefix(string $method, array $prefixes): bool
    {
        if (preg_match('/^(admin|api|login)_/', $method) === 1) {
            return true;
        }
        foreach ($prefixes as $prefix) {
            if ($prefix !== '' && str_starts_with($method, strtolower((string) $prefix))) {
                return true;
            }
        }

        return false;
    }

    /** Same rules as core's Controller::permissionFor(). */
    public static function permissionFor(array $permissions, string $method): ?string
    {
        foreach ($permissions as $action => $permission) {
            if (strtolower((string) $action) === strtolower($method)) {
                return $permission === null ? null : (string) $permission;
            }
        }
        $wildcard = $permissions['*'] ?? null;

        return $wildcard === null ? null : (string) $wildcard;
    }

    /** @return list<class-string> */
    public function controllers(Project $project): array
    {
        $directory = $project->path('controllers');
        $classes = [];
        foreach (glob($directory . DIRECTORY_SEPARATOR . '*Controller.php') ?: [] as $file) {
            $class = 'FloCMS\\Controllers\\' . basename($file, '.php');
            if (!class_exists($class)) {
                require_once $file;
            }
            if (class_exists($class) && is_subclass_of($class, Controller::class)) {
                $classes[] = $class;
            }
        }
        sort($classes);

        return $classes;
    }
}
