<?php
declare(strict_types=1);

namespace FloCMS\CLI\Commands\Cache;

use FloCMS\CLI\Console\Command;

final class OptimizeClearCommand extends Command
{
    protected string $name = 'optimize:clear';

    protected string $description = 'Clear the compiled views and the application cache';

    protected function handle(): int
    {
        $code = $this->call('view:clear');

        return $code !== self::SUCCESS ? $code : $this->call('cache:clear');
    }
}
