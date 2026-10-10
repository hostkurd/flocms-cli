<?php
declare(strict_types=1);

namespace FloCMS\CLI\Commands\Make;

use FloCMS\CLI\Console\Input\Argument;
use FloCMS\CLI\Console\Input\Definition;
use FloCMS\CLI\Console\Input\Option;
use FloCMS\CLI\Support\Name;

final class MakeMigrationCommand extends GeneratorCommand
{
    protected string $name = 'make:migration';

    protected string $description = 'Create a migration in database/migrations';

    protected array $examples = [
        'php flo make:migration create_posts_table',
        'php flo make:migration add_slug_to_posts --table=posts',
    ];

    protected function arguments(Definition $definition): void
    {
        $definition
            ->argument('name', Argument::REQUIRED, 'What the migration does, e.g. create_posts_table')
            ->option('create', null, Option::VALUE_REQUIRED, 'Create this table')
            ->option('table', 't', Option::VALUE_REQUIRED, 'Change this table');
    }

    protected function generate(): int
    {
        $name = Name::snake((string) $this->argument('name'), 'migration name');
        $create = $this->option('create');
        $table = $this->option('table');
        if ($create === null && $table === null && preg_match('/^create_(\w+)_table$/', $name, $match) === 1) {
            $create = $match[1];
        }

        [$stub, $tableName] = match (true) {
            $create !== null => ['migration.create', Name::snake((string) $create, 'table name')],
            $table !== null => ['migration.update', Name::snake((string) $table, 'table name')],
            default => ['migration', ''],
        };

        $existing = glob($this->project()->path('database/migrations') . DIRECTORY_SEPARATOR . '*_' . $name . '.php') ?: [];
        if ($existing !== [] && !$this->option('force')) {
            $this->fail('A migration named "' . $name . '" already exists: database/migrations/' . basename($existing[0]));
        }

        $this->create('database/migrations/' . date('Y_m_d_His') . '_' . $name . '.php', $this->stub($stub, ['table' => $tableName]));

        return self::SUCCESS;
    }
}
