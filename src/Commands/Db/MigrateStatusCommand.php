<?php
declare(strict_types=1);

namespace FloCMS\CLI\Commands\Db;

use FloCMS\CLI\Console\Input\Definition;
use FloCMS\CLI\Console\Input\Option;
use FloCMS\CLI\Console\Output\Styled;

final class MigrateStatusCommand extends DatabaseCommand
{
    protected string $name = 'migrate:status';

    protected string $description = 'Show which migrations have run';

    protected function configure(Definition $definition): void
    {
        $definition->option('package', 'p', Option::VALUE_REQUIRED, 'Only the migrations of this package');
    }

    protected function handle(): int
    {
        $rows = $this->migrator()->status($this->option('package'));
        if ($rows === []) {
            $this->line('No migrations found in database/migrations or in installed packages.');

            return self::SUCCESS;
        }
        $table = [];
        $pending = 0;
        foreach ($rows as $row) {
            $state = match (true) {
                $row['missing'] => '<comment>Ran (file missing)</comment>',
                $row['modified'] => '<comment>Ran (changed since)</comment>',
                $row['ran'] => '<info>Ran</info>',
                default => '<comment>Pending</comment>',
            };
            $pending += $row['ran'] ? 0 : 1;
            $table[] = [$row['name'], $row['package'], $row['batch'] ?? '', new Styled($state)];
        }
        $this->table(['Migration', 'Package', 'Batch', 'Status'], $table);
        $this->line($pending === 0 ? 'Everything has run.' : $pending . ' pending; run "php flo migrate".');

        return self::SUCCESS;
    }
}
