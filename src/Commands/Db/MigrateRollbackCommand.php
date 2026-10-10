<?php
declare(strict_types=1);

namespace FloCMS\CLI\Commands\Db;

use FloCMS\CLI\Console\Input\Definition;
use FloCMS\CLI\Console\Input\Option;

final class MigrateRollbackCommand extends DatabaseCommand
{
    protected string $name = 'migrate:rollback';

    protected string $description = 'Undo the last batch of migrations (or the last N with --step)';

    protected array $examples = [
        'php flo migrate:rollback',
        'php flo migrate:rollback --step=2',
    ];

    protected function configure(Definition $definition): void
    {
        $definition
            ->option('step', 's', Option::VALUE_REQUIRED, 'Roll back this many migrations instead of the last batch')
            ->option('package', 'p', Option::VALUE_REQUIRED, 'Only the migrations of this package')
            ->option('force', 'f', Option::VALUE_NONE, 'Run on production without asking')
            ->option('pretend', null, Option::VALUE_NONE, 'List what would be rolled back');
    }

    protected function handle(): int
    {
        $step = $this->option('step');
        if ($step !== null && (!ctype_digit((string) $step) || (int) $step < 1)) {
            $this->invalid('--step must be a positive number.');
        }
        $migrator = $this->migrator();
        $targets = $migrator->rollback($step === null ? null : (int) $step, $this->option('package'), true);
        if ($targets === []) {
            $this->info('Nothing to roll back.');

            return self::SUCCESS;
        }
        if ($this->option('pretend')) {
            $this->line('Would roll back:');
            foreach ($targets as $key) {
                $this->line('  ' . self::displayKey($key));
            }

            return self::SUCCESS;
        }
        if (!$this->confirmInProduction('Roll back ' . count($targets) . ' migration(s) on this production site?')) {
            $this->comment('Cancelled.');

            return self::FAILURE;
        }
        $done = $migrator->rollback($step === null ? null : (int) $step, $this->option('package'));
        $this->success(count($done) . ' migration(s) rolled back.');

        return self::SUCCESS;
    }
}
