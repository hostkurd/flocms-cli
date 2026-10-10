<?php
declare(strict_types=1);

namespace FloCMS\CLI\Scheduling;

use Closure;
use FloCMS\CLI\Project;

/**
 * The task list of `php flo schedule:run`, defined in config/schedule.php:
 *
 *     use FloCMS\CLI\Scheduling\Schedule;
 *
 *     return static function (Schedule $schedule): void {
 *         $schedule->defaults();                                   // api:gc, log rotation, upload cleanup
 *         $schedule->command('reports:send')->weekdays()->dailyAt('07:30');
 *         $schedule->call(fn () => file_put_contents('...', time()), 'heartbeat')->everyFiveMinutes();
 *     };
 *
 * One cron line runs everything:  * * * * * php /path/to/site/flo schedule:run
 */
final class Schedule
{
    /** @var list<Event> */
    private array $events = [];

    /** @param callable(string): bool $hasCommand */
    public function __construct(private readonly Project $project, private readonly ?\Closure $hasCommand = null)
    {
    }

    /** @param list<string> $arguments */
    public function command(string $command, array $arguments = []): Event
    {
        return $this->events[] = new Event($command, array_values(array_map('strval', $arguments)));
    }

    /** @param callable(): (int|null|void) $callback */
    public function call(callable $callback, string $name): Event
    {
        return $this->events[] = new Event(null, [], Closure::fromCallable($callback), $name);
    }

    /** Delete files older than $hours in a project directory (e.g. abandoned chunked uploads). */
    public function cleanDirectory(string $directory, int $hours = 24): Event
    {
        $project = $this->project;

        return $this->call(static fn (): int => Maintenance::cleanDirectory($project, $directory, $hours), 'clean ' . $directory)
            ->description('Delete files older than ' . $hours . 'h in ' . $directory);
    }

    /** Rotate storage/logs/*.log files larger than $maxBytes, keeping $keep old copies. */
    public function rotateLogs(int $maxBytes = 10 * 1024 * 1024, int $keep = 5): Event
    {
        $project = $this->project;

        return $this->call(static fn (): int => Maintenance::rotateLogs($project, $maxBytes, $keep), 'rotate logs')
            ->description('Rotate storage/logs/*.log over ' . (int) ($maxBytes / 1048576) . ' MB (keep ' . $keep . ')');
    }

    /**
     * The built-in tasks: api:gc (when flocms-api is installed), log
     * rotation and the cleanup of stale chunked uploads.
     */
    public function defaults(string $uploadsDirectory = 'storage/uploads/.chunks'): void
    {
        if ($this->hasCommand === null || ($this->hasCommand)('api:gc')) {
            $this->command('api:gc')->dailyAt('03:10')->description('Delete stale API rate-limit and idempotency state');
        }
        $this->rotateLogs()->dailyAt('03:20');
        $this->cleanDirectory($uploadsDirectory, 24)->hourlyAt(25);
    }

    /** @return list<Event> */
    public function events(): array
    {
        return $this->events;
    }

    /**
     * The project's schedule: config/schedule.php, or the built-in tasks
     * when the file does not exist.
     *
     * @param null|callable(string): bool $hasCommand
     */
    public static function forProject(Project $project, ?callable $hasCommand = null): self
    {
        $schedule = new self($project, $hasCommand !== null ? Closure::fromCallable($hasCommand) : null);
        $file = $project->path('config/schedule.php');
        if (!is_file($file)) {
            $schedule->defaults();

            return $schedule;
        }
        $definition = (static fn (string $__file): mixed => require $__file)($file);
        if (!is_callable($definition)) {
            throw new \FloCMS\CLI\Console\CommandFailedException('config/schedule.php must return a function (Schedule $schedule): void.');
        }
        $definition($schedule);

        return $schedule;
    }
}
