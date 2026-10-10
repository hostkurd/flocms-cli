<?php
declare(strict_types=1);

namespace FloCMS\CLI\Commands\Schedule;

use FloCMS\CLI\Console\Command;
use FloCMS\CLI\Console\Input\Definition;
use FloCMS\CLI\Console\Input\Option;
use FloCMS\CLI\Scheduling\Runner;
use FloCMS\CLI\Scheduling\Schedule;

final class ScheduleListCommand extends Command
{
    protected string $name = 'schedule:list';

    protected string $description = 'List the scheduled tasks with their next and last run';

    protected function configure(Definition $definition): void
    {
        $definition
            ->option('time', null, Option::VALUE_REQUIRED, 'Compute the next runs from this time (testing)')
            ->option('format', null, Option::VALUE_REQUIRED, 'txt or json', 'txt');
    }

    protected function handle(): int
    {
        $application = $this->getApplication() ?? $this->fail('No application.');
        $project = $this->project();
        $schedule = Schedule::forProject($project, static fn (string $name): bool => $application->has($name));
        $now = Runner::now($this->option('time'));
        $state = (new Runner($project, $application))->state();

        $rows = [];
        foreach ($schedule->events() as $event) {
            $last = $state[$event->id()] ?? null;
            $next = $event->nextRun($now);
            $rows[] = [
                'task' => $event->name(),
                'description' => $event->getDescription(),
                'cron' => $event->expression(),
                'next' => $next?->format('Y-m-d H:i'),
                'last' => $last === null ? null : date('Y-m-d H:i', (int) $last['started']) . ' ' . $last['status'],
                'environments' => $event->runsInEnvironment($project->environment()) ? null : 'not in ' . $project->environment(),
            ];
        }

        if ($this->option('format') === 'json') {
            $this->output->write(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

            return self::SUCCESS;
        }
        if ($rows === []) {
            $this->line('No scheduled tasks. Define them in config/schedule.php.');

            return self::SUCCESS;
        }
        $this->table(['Task', 'Cron', 'Next run', 'Last run'], array_map(static fn (array $r): array => [
            $r['description'] !== $r['task'] ? $r['task'] . ' - ' . $r['description'] : $r['task'],
            $r['cron'],
            $r['environments'] ?? $r['next'] ?? '-',
            $r['last'] ?? 'never',
        ], $rows));
        $this->line('Cron: * * * * * php ' . $project->path('flo') . ' schedule:run');

        return self::SUCCESS;
    }
}
