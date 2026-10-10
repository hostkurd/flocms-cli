<?php
declare(strict_types=1);

namespace FloCMS\CLI\Commands\Log;

use FloCMS\CLI\Console\Command;
use FloCMS\CLI\Console\Input\Argument;
use FloCMS\CLI\Console\Input\Definition;
use FloCMS\CLI\Console\Input\Option;

final class LogClearCommand extends Command
{
    protected string $name = 'log:clear';

    protected string $description = 'Empty a log (or all logs) in storage/logs';

    protected function configure(Definition $definition): void
    {
        $definition
            ->argument('log', Argument::OPTIONAL, 'The log name (app, api, ...); default: all logs and rotated copies')
            ->option('force', 'f', Option::VALUE_NONE, 'Do not ask');
    }

    protected function handle(): int
    {
        $files = new LogFiles($this->project());
        $targets = $this->argument('log') !== null
            ? [$files->path($this->argument('log'))]
            : array_merge(
                glob($files->directory() . DIRECTORY_SEPARATOR . '*.log') ?: [],
                glob($files->directory() . DIRECTORY_SEPARATOR . '*.log.[0-9]*') ?: []
            );
        if ($targets === []) {
            $this->info('No logs to clear.');

            return self::SUCCESS;
        }
        if (!$this->option('force')) {
            if (!$this->input->isInteractive()) {
                $this->fail('Not clearing logs without confirmation. Run interactively or pass --force.');
            }
            $names = implode(', ', array_map('basename', $targets));
            if (!$this->confirm('Clear ' . $names . '?', false)) {
                $this->comment('Nothing was cleared.');

                return self::FAILURE;
            }
        }
        foreach ($targets as $file) {
            preg_match('/\.log\.\d+$/', $file) === 1 ? @unlink($file) : @file_put_contents($file, '');
        }
        $this->success(count($targets) . ' log file(s) cleared.');

        return self::SUCCESS;
    }
}
