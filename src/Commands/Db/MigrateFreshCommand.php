<?php
declare(strict_types=1);

namespace FloCMS\CLI\Commands\Db;

use FloCMS\CLI\Console\Input\Definition;
use FloCMS\CLI\Console\Input\Option;

final class MigrateFreshCommand extends DatabaseCommand
{
    protected string $name = 'migrate:fresh';

    protected string $description = 'Drop ALL tables and run every migration again';

    protected string $help = <<<'TXT'
Deletes every table in the database (including tables not created by
migrations), then runs all migrations. Always asks first; on a production
site it refuses unless you pass --force.
TXT;

    protected function configure(Definition $definition): void
    {
        $definition
            ->option('force', 'f', Option::VALUE_NONE, 'Required on production; skips the question')
            ->option('seed', null, Option::VALUE_NONE, 'Run db:seed afterwards');
    }

    protected function handle(): int
    {
        $project = $this->project();
        if ($project->isProduction() && !$this->option('force')) {
            $this->fail('migrate:fresh deletes every table. Refusing on a production site (APP_ENV=' . $project->environment() . ') without --force.');
        }
        $database = \FloCMS\CLI\Database\ConnectionFactory::describe($project);
        if (!$this->option('force')) {
            if (!$this->input->isInteractive()) {
                $this->fail('migrate:fresh deletes every table. Run interactively or pass --force.');
            }
            if (!$this->confirm('Drop ALL tables in "' . ($database['database'] ?? $database['dsn'] ?? '') . '" and migrate again?', false)) {
                $this->comment('Cancelled.');

                return self::FAILURE;
            }
        }

        $migrator = $this->migrator();
        $dropped = $migrator->dropAllTables();
        $this->line('Dropped ' . count($dropped) . ' table(s).');
        $ran = $migrator->migrate();
        $this->success(count($ran) . ' migration(s) ran.');

        return $this->option('seed') ? $this->call('db:seed', ['--force']) : self::SUCCESS;
    }
}
