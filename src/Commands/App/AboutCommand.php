<?php
declare(strict_types=1);

namespace FloCMS\CLI\Commands\App;

use FloCMS\CLI\Console\Command;
use FloCMS\CLI\Console\Input\Definition;
use FloCMS\CLI\Console\Input\Option;
use FloCMS\CLI\Database\ConnectionFactory;
use FloCMS\CLI\Support\Maintenance;
use FloCMS\CLI\Support\Versions;

final class AboutCommand extends Command
{
    protected string $name = 'about';

    protected string $description = 'Show PHP, framework, environment, database and path information';

    public const EXTENSIONS = ['pdo', 'pdo_mysql', 'pdo_sqlite', 'mbstring', 'fileinfo', 'gd', 'openssl', 'json', 'curl', 'intl', 'apcu'];

    protected function configure(Definition $definition): void
    {
        $definition->option('format', null, Option::VALUE_REQUIRED, 'txt or json', 'txt');
    }

    /** @return array<string, array<string, mixed>> */
    public function data(): array
    {
        $project = $this->project();
        $db = ConnectionFactory::describe($project);

        return [
            'Environment' => [
                'Application' => (string) $project->env('APP_NAME', 'FloCMS'),
                'Environment' => $project->hasEnvFile() ? $project->environment() : 'no .env file',
                'Debug' => $project->env('APP_DEBUG') === true,
                'URL' => $project->env('APP_URL'),
                'Maintenance' => (new Maintenance($project))->isDown(),
                'Languages' => $project->env('LANGUAGES'),
            ],
            'PHP' => [
                'Version' => PHP_VERSION,
                'SAPI' => PHP_SAPI,
                'OS' => PHP_OS_FAMILY . ' (' . php_uname('r') . ')',
                'Memory limit' => (string) ini_get('memory_limit'),
                'Extensions' => implode(', ', array_filter(self::EXTENSIONS, 'extension_loaded')),
                'Missing' => implode(', ', array_filter(self::EXTENSIONS, static fn (string $e): bool => !extension_loaded($e))) ?: null,
            ],
            'Versions' => array_combine(
                array_map(static fn (string $k): string => match ($k) { 'cli' => 'flocms-cli', 'core' => 'flocms-core', 'api' => 'flocms-api', 'uploader' => 'flocms-uploader', default => 'Application' }, array_keys(Versions::framework($project))),
                array_values(Versions::framework($project))
            ),
            'Database' => [
                'Configured' => ConnectionFactory::isConfigured($project),
                'Driver' => $db['driver'] ?? null,
                'Host' => isset($db['host']) ? $db['host'] . ':' . $db['port'] : null,
                'Database' => ($db['database'] ?? $db['dsn'] ?? '') ?: null,
            ],
            'Paths' => [
                'Root' => $project->root(),
                'Storage' => $project->path('storage'),
                'Views cache' => $project->path('views/cache'),
                'Logs' => $project->path('storage/logs'),
            ],
        ];
    }

    protected function handle(): int
    {
        $data = $this->data();
        if ($this->option('format') === 'json') {
            $this->output->write(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

            return self::SUCCESS;
        }
        foreach ($data as $section => $items) {
            $this->section($section);
            $this->definitionList($items);
        }

        return self::SUCCESS;
    }
}
