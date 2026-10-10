<?php
declare(strict_types=1);

namespace FloCMS\CLI\Commands\Db;

use FloCMS\CLI\Console\Input\Definition;
use FloCMS\CLI\Console\Input\Option;
use FloCMS\CLI\Database\ConnectionFactory;

final class DbShowCommand extends DatabaseCommand
{
    protected string $name = 'db:show';

    protected string $description = 'Show the database connection and its tables';

    protected function configure(Definition $definition): void
    {
        $definition->option('format', null, Option::VALUE_REQUIRED, 'txt or json', 'txt');
    }

    protected function handle(): int
    {
        $project = $this->project();
        $db = $project->database();
        $info = ConnectionFactory::describe($project) + ['server' => DbCheckCommand::serverVersion($db)];
        $tables = $this->migrator()->tables();

        if ($this->option('format') === 'json') {
            $this->output->write(json_encode(['connection' => $info, 'tables' => $tables], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

            return self::SUCCESS;
        }
        $this->section('Connection');
        $this->definitionList(array_combine(array_map('ucfirst', array_keys($info)), array_values($info)));
        $this->section('Tables (' . count($tables) . ')');
        if ($tables === []) {
            $this->line('  (none) Run "php flo migrate" to create them.');
        } else {
            $this->table(['Table', 'Rows'], array_map(static fn (array $t): array => [$t['table'], $t['rows']], $tables));
        }

        return self::SUCCESS;
    }
}
