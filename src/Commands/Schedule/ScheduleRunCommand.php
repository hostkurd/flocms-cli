<?php
declare(strict_types=1);

namespace FloCMS\CLI\Commands\Schedule;

use FloCMS\CLI\Console\Command;
use FloCMS\CLI\Console\Input\Definition;
use FloCMS\CLI\Console\Input\Option;
use FloCMS\CLI\Console\Output\Formatter;
use FloCMS\CLI\Scheduling\Runner;
use FloCMS\CLI\Scheduling\Schedule;

final class ScheduleRunCommand extends Command
{
    protected string $name = 'schedule:run';

    protected string $description = 'Run the scheduled tasks that are due (call it from cron every minute)';

    protected string $help = <<<'TXT'
Add one cron job (cPanel: Cron Jobs, "Once Per Minute"):

    * * * * * php /home/USER/site/flo schedule:run >> /dev/null 2>&1

Tasks come from config/schedule.php (or the built-in ones: api:gc, log
rotation, stale upload cleanup). A task still running is not started again.
Results go to storage/logs/schedule.log.
TXT;

    protected function configure(Definition $definition): void
    {
        $definition
            ->option('task', 't', Option::VALUE_REQUIRED, 'Run this task now, whether it is due or not (name as in schedule:list)')
            ->option('time', null, Option::VALUE_REQUIRED, 'Pretend it is this time, e.g. "2026-01-01 03:10" (testing)');
    }

    protected function handle(): int
    {
        $application = $this->getApplication() ?? $this->fail('No application.');
        $project = $this->project();
        $schedule = Schedule::forProject($project, static fn (string $name): bool => $application->has($name));
        try {
            $now = Runner::now($this->option('time'));
        } catch (\Exception) {
            $this->invalid('Invalid --time "' . $this->option('time') . '".');
        }

        $task = $this->option('task');
        $events = array_values(array_filter($schedule->events(), static fn ($e): bool => $task !== null
            ? $e->name() === $task
            : $e->isDue($now) && $e->runsInEnvironment($project->environment())));
        if ($task !== null && $events === []) {
            $this->fail('No scheduled task named "' . $task . '". See php flo schedule:list.');
        }
        if ($events === []) {
            $this->line('No scheduled tasks are due.', \FloCMS\CLI\Console\Output\Output::VERBOSE);

            return self::SUCCESS;
        }

        $runner = new Runner($project, $application);
        $failed = 0;
        foreach ($events as $event) {
            $this->line('<comment>Running</comment> ' . Formatter::escape($event->name()));
            $result = $runner->run($event, $this->output);
            $label = match ($result['status']) {
                'ok' => '<info>done</info>',
                'skipped' => '<comment>skipped</comment>',
                default => '<error>failed</error>',
            };
            $this->line('  ' . $label . ($result['message'] !== '' ? ' ' . Formatter::escape($result['message']) : '') . ' <muted>(' . $result['duration'] . 's)</muted>');
            $failed += $result['status'] === 'failed' ? 1 : 0;
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
