<?php
declare(strict_types=1);

namespace FloCMS\CLI\Commands\Cache;

use FloCMS\CLI\Console\Command;
use FloCMS\CLI\Console\Input\Definition;
use FloCMS\CLI\Console\Input\Option;

final class CacheClearCommand extends Command
{
    protected string $name = 'cache:clear';

    protected string $description = 'Clear storage/cache (rate limits, login throttle, idempotency, ...)';

    protected string $help = 'The module registry (storage/cache/modules.php, modules-state.json) is kept unless --all.';

    protected function configure(Definition $definition): void
    {
        $definition->option('all', null, Option::VALUE_NONE, 'Also remove the module registry caches');
    }

    protected function handle(): int
    {
        $removed = (new CacheFiles($this->project()))->clearStorageCache((bool) $this->option('all'));
        $this->success('Application cache cleared (' . $removed . ' file(s)).');

        return self::SUCCESS;
    }
}
