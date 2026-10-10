<?php
declare(strict_types=1);

namespace FloCMS\CLI\Commands\Cache;

use FloCMS\CLI\Console\Command;

final class ViewClearCommand extends Command
{
    protected string $name = 'view:clear';

    protected string $description = 'Remove the compiled templates from views/cache';

    protected function handle(): int
    {
        $removed = (new CacheFiles($this->project()))->clearViews();
        $this->success('Compiled views cleared (' . $removed . ' file(s)).');

        return self::SUCCESS;
    }
}
