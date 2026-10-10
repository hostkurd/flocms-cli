<?php
declare(strict_types=1);

namespace FloCMS\CLI\Commands\Route;

use FloCMS\CLI\Console\Command;
use FloCMS\CLI\Console\Input\Definition;
use FloCMS\CLI\Console\Input\Option;
use FloCMS\CLI\Discovery\PackageManifest;
use FloCMS\CLI\Routing\LegacyRoutes;
use FloCMS\CLI\Routing\RouteProvider;
use Throwable;

final class RouteListCommand extends Command
{
    protected string $name = 'route:list';

    protected string $description = 'List the web routes (controllers/) and API routes in one table';

    protected string $help = <<<'TXT'
Web routes follow the controller/action conventions: /<controller>/<action>,
/admin/<controller>/<action> for admin_ methods (and the other prefixes in
config 'routes'), with the permission from $actionPermissions. API routes
come from packages that register a route provider (flocms-api: api/routes.php).
TXT;

    protected array $examples = [
        'php flo route:list',
        'php flo route:list --path=/admin',
        'php flo route:list --method=POST --source=api',
        'php flo route:list --format=json',
    ];

    protected function configure(Definition $definition): void
    {
        $definition
            ->option('method', null, Option::VALUE_REQUIRED, 'Only routes answering this HTTP method')
            ->option('path', null, Option::VALUE_REQUIRED, 'Only URIs containing this text')
            ->option('name', null, Option::VALUE_REQUIRED, 'Only route names or actions containing this text')
            ->option('source', null, Option::VALUE_REQUIRED, 'Only "web" or a package source such as "api"')
            ->option('format', null, Option::VALUE_REQUIRED, 'txt or json', 'txt');
    }

    /** @return list<array{source: string, method: string, uri: string, name: ?string, action: string, permission: ?string, middleware: list<string>}> */
    public function collect(): array
    {
        $routes = [];
        foreach ($this->providers() as $provider) {
            try {
                foreach ($provider->routes($this->project()) as $route) {
                    $routes[] = [
                        'source' => $provider->source(),
                        'method' => strtoupper((string) $route['method']),
                        'uri' => (string) $route['uri'],
                        'name' => isset($route['name']) && $route['name'] !== '' ? (string) $route['name'] : null,
                        'action' => (string) $route['action'],
                        'permission' => isset($route['permission']) ? (string) $route['permission'] : null,
                        'middleware' => array_values(array_map('strval', (array) ($route['middleware'] ?? []))),
                    ];
                }
            } catch (Throwable $error) {
                $this->warn('Routes from ' . $provider->source() . ' could not be loaded: ' . $error->getMessage());
            }
        }

        return array_values(array_filter($routes, fn (array $route): bool => $this->matches($route)));
    }

    protected function handle(): int
    {
        $routes = $this->collect();
        if ($this->option('format') === 'json') {
            $this->output->write(json_encode($routes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

            return self::SUCCESS;
        }
        if ($routes === []) {
            $this->line('No routes match.');

            return self::SUCCESS;
        }
        $this->table(['Source', 'Method', 'URI', 'Name', 'Action', 'Permission / middleware'], array_map(static fn (array $r): array => [
            $r['source'],
            $r['method'],
            $r['uri'],
            $r['name'] ?? '',
            $r['action'],
            implode(', ', array_filter([$r['permission'] !== null ? 'permission: ' . $r['permission'] : null, ...$r['middleware']])),
        ], $routes));
        $this->line(count($routes) . ' route(s).');

        return self::SUCCESS;
    }

    /** @return list<RouteProvider> */
    private function providers(): array
    {
        $providers = [new LegacyRoutes()];
        foreach ((new PackageManifest($this->project()->root(), $this->project()->vendorDir()))->listOf('route-providers') as $package => $classes) {
            foreach ($classes as $class) {
                if (class_exists($class) && is_subclass_of($class, RouteProvider::class)) {
                    $providers[] = new $class();
                } else {
                    $this->warn('Route provider ' . $class . ' (from ' . $package . ') not found.');
                }
            }
        }

        return $providers;
    }

    /** @param array{source: string, method: string, uri: string, name: ?string, action: string} $route */
    private function matches(array $route): bool
    {
        $method = $this->option('method');
        if ($method !== null && $route['method'] !== 'ANY' && !in_array(strtoupper((string) $method), explode('|', $route['method']), true)) {
            return false;
        }
        $path = $this->option('path');
        if ($path !== null && stripos($route['uri'], (string) $path) === false) {
            return false;
        }
        $name = $this->option('name');
        if ($name !== null && stripos((string) $route['name'], (string) $name) === false && stripos($route['action'], (string) $name) === false) {
            return false;
        }
        $source = $this->option('source');

        return $source === null || strcasecmp($route['source'], (string) $source) === 0;
    }
}
