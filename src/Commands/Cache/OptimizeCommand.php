<?php
declare(strict_types=1);

namespace FloCMS\CLI\Commands\Cache;

use FloCMS\CLI\Console\Command;
use FloCMS\CLI\Console\Output\Formatter;

final class OptimizeCommand extends Command
{
    protected string $name = 'optimize';

    protected string $description = 'Compile every view and template ahead of time (for deployments)';

    protected function handle(): int
    {
        $files = new CacheFiles($this->project());
        $templates = $files->templates();
        $bar = $this->progress(count($templates));
        $result = $files->warmViews();
        $bar->finish();

        foreach ($result['failed'] as $template) {
            $this->warn('Could not compile ' . Formatter::escape($template) . ' (is views/cache writable?)');
        }
        $this->success($result['compiled'] . ' template(s) compiled into views/cache.');
        $this->note('For faster class loading on production, also run: composer dump-autoload --optimize --no-dev');

        return $result['failed'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
