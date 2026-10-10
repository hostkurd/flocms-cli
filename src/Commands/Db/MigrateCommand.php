<?php
declare(strict_types=1);

namespace FloCMS\CLI\Commands\Db;

use FloCMS\CLI\Console\Input\Definition;
use FloCMS\CLI\Console\Input\Option;

final class MigrateCommand extends DatabaseCommand
{
    protected string $name = 'migrate';

    protected string $description = 'Run the pending database migrations';

    protected string $help = <<<'TXT'
Runs the migrations in database/migrations and those of installed packages
(e.g. hostkurd/flocms-api) that have not run yet, as one batch. On a
production site (APP_ENV=production) it asks first; --force skips the question.
TXT;

    protected array $examples = [
        'php flo migrate',
        'php flo migrate --pretend',
        'php flo migrate --package=hostkurd/flocms-api',
        'php flo migrate --force   # deployments',
    ];

    protected function configure(Definition $definition): void
    {
        $definition
            ->option('force', 'f', Option::VALUE_NONE, 'Run on production without asking')
            ->option('package', 'p', Option::VALUE_REQUIRED, 'Only the migrations of this package ("app" for database/migrations)')
            ->option('pretend', null, Option::VALUE_NONE, 'List what would run, without running it')
            ->option('seed', null, Option::VALUE_NONE, 'Run db:seed afterwards');
    }

    protected function handle(): int
    {
        $migrator = $this->migrator();
        $package = $this->option('package');
        $pending = $migrator->pending($package);
        if ($pending === []) {
            $this->info('Nothing to migrate.');

            return $this->option('seed') ? $this->call('db:seed', $this->option('force') ? ['--force'] : []) : self::SUCCESS;
        }
        if ($this->option('pretend')) {
            $this->line('Would run:');
            foreach ($pending as $key) {
                $this->line('  ' . self::displayKey($key));
            }

            return self::SUCCESS;
        }
        if (!$this->confirmInProduction('Run ' . count($pending) . ' migration(s) on this production site?')) {
            $this->comment('Cancelled.');

            return self::FAILURE;
        }

        $ran = $migrator->migrate($package);
        $this->success(count($ran) . ' migration(s) ran (batch ' . $migrator->lastBatch() . ').');

        return $this->option('seed') ? $this->call('db:seed', ['--force']) : self::SUCCESS;
    }
}
