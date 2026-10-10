<?php
declare(strict_types=1);

namespace FloCMS\CLI\Commands\Log;

use FloCMS\CLI\Console\Command;
use FloCMS\CLI\Console\Input\Definition;
use FloCMS\CLI\Console\Input\Option;
use FloCMS\CLI\Scheduling\Maintenance;

final class LogRotateCommand extends Command
{
    protected string $name = 'log:rotate';

    protected string $description = 'Rotate large logs (app.log -> app.log.1 ...); schedule:run does this daily';

    protected function configure(Definition $definition): void
    {
        $definition
            ->option('max-size', null, Option::VALUE_REQUIRED, 'Rotate logs larger than this many MB', '10')
            ->option('keep', null, Option::VALUE_REQUIRED, 'Old copies to keep', '5');
    }

    protected function handle(): int
    {
        foreach (['max-size', 'keep'] as $option) {
            if (!ctype_digit((string) $this->option($option)) || (int) $this->option($option) < 1) {
                $this->invalid('--' . $option . ' must be a positive number.');
            }
        }
        $rotated = Maintenance::rotateLogs($this->project(), (int) $this->option('max-size') * 1024 * 1024, (int) $this->option('keep'));
        $this->success($rotated . ' log file(s) rotated.');

        return self::SUCCESS;
    }
}
