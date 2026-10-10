<?php
declare(strict_types=1);

namespace FloCMS\CLI\Commands;

use FloCMS\CLI\Console\Command;

final class ListCommand extends Command
{
    protected string $name = 'list';

    protected string $description = 'List all commands';

    protected function handle(): int
    {
        $application = $this->getApplication();
        $this->line(trim($application?->getName() . ' ' . $application?->getVersion()));
        $this->line();
        $this->line('Available commands:');
        foreach ($application?->all() ?? [] as $name => $command) {
            if (!$command->isHidden()) {
                $this->line('  ' . str_pad($name, 24) . ' ' . $command->getDescription());
            }
        }

        return self::SUCCESS;
    }
}
