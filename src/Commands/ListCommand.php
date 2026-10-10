<?php
declare(strict_types=1);

namespace FloCMS\CLI\Commands;

use FloCMS\CLI\Console\Command;
use FloCMS\CLI\Console\Descriptor;
use FloCMS\CLI\Console\Input\Argument;
use FloCMS\CLI\Console\Input\Definition;
use FloCMS\CLI\Console\Input\Option;

final class ListCommand extends Command
{
    protected string $name = 'list';

    protected string $description = 'List all commands, grouped by namespace';

    protected array $examples = [
        'php flo list',
        'php flo list make',
        'php flo list --format=json',
    ];

    protected function configure(Definition $definition): void
    {
        $definition
            ->argument('namespace', Argument::OPTIONAL, 'Only list the commands of this namespace (e.g. make, db, api)')
            ->option('format', null, Option::VALUE_REQUIRED, 'Output format: txt or json', 'txt');
    }

    protected function handle(): int
    {
        $application = $this->getApplication() ?? $this->fail('No application.');
        $descriptor = new Descriptor($application);
        $namespace = $this->argument('namespace');
        if ($namespace !== null && $descriptor->groups($namespace) === []) {
            $this->fail('There are no commands in the "' . $namespace . '" namespace.');
        }

        return match ($this->option('format')) {
            'json' => $this->json($descriptor->listAsArray($namespace)),
            'txt' => $this->text($descriptor, $namespace),
            default => $this->invalid('Unknown format "' . $this->option('format') . '" (use txt or json).'),
        };
    }

    private function text(Descriptor $descriptor, ?string $namespace): int
    {
        $descriptor->describeList($this->output, $this->getApplication()?->header() ?? [], $namespace);

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $data */
    private function json(array $data): int
    {
        $this->output->write(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");

        return self::SUCCESS;
    }
}
