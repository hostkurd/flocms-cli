<?php
declare(strict_types=1);

namespace FloCMS\CLI\Scheduling;

use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * One scheduled task: a flo command or a closure, with its frequency.
 * Every method returns the event, so calls chain:
 *
 *     $schedule->command('api:gc', ['--database'])->dailyAt('03:10');
 */
final class Event
{
    private string $expression = '* * * * *';

    private ?DateTimeZone $timezone = null;

    private bool $withoutOverlapping = true;

    /** @var list<string> */
    private array $environments = [];

    private ?string $description = null;

    /**
     * @param string|null $command a flo command name (with $arguments), or null for a callback
     * @param list<string> $arguments
     * @param null|Closure(): (int|null|void) $callback
     */
    public function __construct(
        public readonly ?string $command,
        public readonly array $arguments = [],
        public readonly ?Closure $callback = null,
        private string $name = ''
    ) {
        if ($command === null && $callback === null) {
            throw new InvalidArgumentException('A scheduled task needs a command or a callback.');
        }
        if ($this->name === '') {
            $this->name = $command !== null ? trim($command . ' ' . implode(' ', $arguments)) : 'closure';
        }
    }

    public function name(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return $this->description ?? $this->name;
    }

    public function description(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function expression(): string
    {
        return $this->expression;
    }

    public function cron(string $expression): self
    {
        new CronExpression($expression);
        $this->expression = $expression;

        return $this;
    }

    public function everyMinute(): self
    {
        return $this->cron('* * * * *');
    }

    public function everyFiveMinutes(): self
    {
        return $this->cron('*/5 * * * *');
    }

    public function everyTenMinutes(): self
    {
        return $this->cron('*/10 * * * *');
    }

    public function everyFifteenMinutes(): self
    {
        return $this->cron('*/15 * * * *');
    }

    public function everyThirtyMinutes(): self
    {
        return $this->cron('0,30 * * * *');
    }

    public function hourly(): self
    {
        return $this->cron('0 * * * *');
    }

    public function hourlyAt(int $minute): self
    {
        return $this->cron($minute . ' * * * *');
    }

    public function daily(): self
    {
        return $this->cron('0 0 * * *');
    }

    /** @param string $time "HH:MM" */
    public function dailyAt(string $time): self
    {
        [$hour, $minute] = self::time($time);

        return $this->cron($minute . ' ' . $hour . ' * * *');
    }

    public function twiceDaily(int $first = 1, int $second = 13): self
    {
        return $this->cron('0 ' . $first . ',' . $second . ' * * *');
    }

    public function weekly(): self
    {
        return $this->cron('0 0 * * 0');
    }

    /** @param int $weekday 0 (Sunday) to 6 */
    public function weeklyOn(int $weekday, string $time = '00:00'): self
    {
        [$hour, $minute] = self::time($time);

        return $this->cron($minute . ' ' . $hour . ' * * ' . $weekday);
    }

    public function monthly(): self
    {
        return $this->cron('0 0 1 * *');
    }

    public function monthlyOn(int $day = 1, string $time = '00:00'): self
    {
        [$hour, $minute] = self::time($time);

        return $this->cron($minute . ' ' . $hour . ' ' . $day . ' * *');
    }

    public function yearly(): self
    {
        return $this->cron('0 0 1 1 *');
    }

    /** Limit the current schedule to Monday-Friday. */
    public function weekdays(): self
    {
        $parts = explode(' ', $this->expression);
        $parts[4] = '1-5';

        return $this->cron(implode(' ', $parts));
    }

    public function timezone(string $timezone): self
    {
        $this->timezone = new DateTimeZone($timezone);

        return $this;
    }

    /** Allow a new run while the previous one is still running (off by default). */
    public function allowOverlapping(): self
    {
        $this->withoutOverlapping = false;

        return $this;
    }

    public function preventsOverlapping(): bool
    {
        return $this->withoutOverlapping;
    }

    /** Only run in these environments (APP_ENV), e.g. ->environments('production'). */
    public function environments(string ...$environments): self
    {
        $this->environments = array_values($environments);

        return $this;
    }

    public function runsInEnvironment(string $environment): bool
    {
        return $this->environments === [] || in_array($environment, $this->environments, true);
    }

    public function isDue(DateTimeInterface $now): bool
    {
        return (new CronExpression($this->expression))->isDue($this->localize($now));
    }

    public function nextRun(DateTimeInterface $now): ?DateTimeImmutable
    {
        return (new CronExpression($this->expression))->nextRun($this->localize($now));
    }

    /** A stable id for locks and state. */
    public function id(): string
    {
        return substr(sha1($this->name . '|' . $this->expression), 0, 16);
    }

    private function localize(DateTimeInterface $time): DateTimeImmutable
    {
        $time = DateTimeImmutable::createFromInterface($time);

        return $this->timezone !== null ? $time->setTimezone($this->timezone) : $time;
    }

    /** @return array{0: int, 1: int} */
    private static function time(string $time): array
    {
        if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/D', $time, $match) !== 1) {
            throw new InvalidArgumentException('Invalid time "' . $time . '" (use HH:MM).');
        }

        return [(int) $match[1], (int) $match[2]];
    }
}
