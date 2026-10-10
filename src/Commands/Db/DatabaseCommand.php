<?php
declare(strict_types=1);

namespace FloCMS\CLI\Commands\Db;

use FloCMS\CLI\Console\Command;
use FloCMS\CLI\Database\Migrator;

/**
 * Shared helpers for the migrate and db commands.
 */
abstract class DatabaseCommand extends Command
{
    protected function migrator(): Migrator
    {
        $migrator = Migrator::forProject($this->project());
        $migrator->onProgress(function (string $event, string $key): void {
            $label = str_starts_with($key, Migrator::APP . ':') ? substr($key, strlen(Migrator::APP) + 1) : $key;
            match ($event) {
                'running' => $this->output->write('  <comment>Migrating</comment> ' . $label . ' ... '),
                'done' => $this->line('<info>done</info>'),
                'rolling back' => $this->output->write('  <comment>Rolling back</comment> ' . $label . ' ... '),
                'rolled back' => $this->line('<info>done</info>'),
                default => null,
            };
        });

        return $migrator;
    }

    /**
     * Destructive or data-changing commands ask on production sites, unless
     * --force. Without interaction they refuse.
     */
    protected function confirmInProduction(string $question): bool
    {
        if ($this->option('force') || !$this->project()->isProduction()) {
            return true;
        }
        if (!$this->input->isInteractive()) {
            $this->fail('This is a production site (APP_ENV=' . $this->project()->environment() . '). Pass --force to run anyway.');
        }

        return $this->confirm($question, false);
    }

    protected static function displayKey(string $key): string
    {
        return str_starts_with($key, Migrator::APP . ':') ? substr($key, strlen(Migrator::APP) + 1) : $key;
    }
}
