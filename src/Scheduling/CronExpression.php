<?php
declare(strict_types=1);

namespace FloCMS\CLI\Scheduling;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * A standard 5-field cron expression: minute hour day-of-month month
 * day-of-week. Supports *, lists (1,15), ranges (1-5), steps (*\/5, 10-30/10),
 * month and weekday names (jan, mon) and the macros @yearly, @monthly,
 * @weekly, @daily, @hourly. When both day fields are restricted, a day
 * matches either one (like cron).
 */
final class CronExpression
{
    private const MACROS = [
        '@yearly' => '0 0 1 1 *',
        '@annually' => '0 0 1 1 *',
        '@monthly' => '0 0 1 * *',
        '@weekly' => '0 0 * * 0',
        '@daily' => '0 0 * * *',
        '@midnight' => '0 0 * * *',
        '@hourly' => '0 * * * *',
    ];

    private const RANGES = [[0, 59], [0, 23], [1, 31], [1, 12], [0, 6]];

    private const NAMES = [
        3 => ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12],
        4 => ['sun' => 0, 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6],
    ];

    /** @var list<list<int>> allowed values per field */
    private array $fields;

    /** @var array{0: bool, 1: bool} whether day-of-month / day-of-week are restricted */
    private array $restricted;

    public function __construct(public readonly string $expression)
    {
        $normalized = self::MACROS[strtolower(trim($expression))] ?? trim($expression);
        $parts = preg_split('/\s+/', $normalized) ?: [];
        if (count($parts) !== 5) {
            throw new InvalidArgumentException('Invalid cron expression "' . $expression . '": expected 5 fields (minute hour day month weekday).');
        }
        $this->fields = [];
        foreach ($parts as $index => $part) {
            $this->fields[$index] = self::parseField(strtolower($part), $index, $expression);
        }
        $this->restricted = [$parts[2] !== '*', $parts[4] !== '*'];
    }

    public static function isValid(string $expression): bool
    {
        try {
            new self($expression);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    public function isDue(DateTimeInterface $time): bool
    {
        [$minute, $hour, $day, $month, $weekday] = array_map('intval', explode(' ', $time->format('i G j n w')));
        if (!in_array($minute, $this->fields[0], true) || !in_array($hour, $this->fields[1], true) || !in_array($month, $this->fields[3], true)) {
            return false;
        }
        $dayMatches = in_array($day, $this->fields[2], true);
        $weekdayMatches = in_array($weekday, $this->fields[4], true);

        return match (true) {
            $this->restricted[0] && $this->restricted[1] => $dayMatches || $weekdayMatches,
            $this->restricted[0] => $dayMatches,
            $this->restricted[1] => $weekdayMatches,
            default => true,
        };
    }

    /** The next matching minute after $from (at most ~5 years ahead). */
    public function nextRun(DateTimeInterface $from): ?DateTimeImmutable
    {
        $time = DateTimeImmutable::createFromInterface($from)->setTime((int) $from->format('G'), (int) $from->format('i'))->modify('+1 minute');
        $limit = $time->modify('+5 years');
        while ($time < $limit) {
            if (!in_array((int) $time->format('n'), $this->fields[3], true)) {
                $time = $time->modify('first day of next month')->setTime(0, 0);
                continue;
            }
            if (!$this->dayMatches($time)) {
                $time = $time->modify('+1 day')->setTime(0, 0);
                continue;
            }
            if (!in_array((int) $time->format('G'), $this->fields[1], true)) {
                $time = $time->modify('+1 hour')->setTime((int) $time->modify('+1 hour')->format('G'), 0);
                continue;
            }
            if (!in_array((int) $time->format('i'), $this->fields[0], true)) {
                $time = $time->modify('+1 minute');
                continue;
            }

            return $time;
        }

        return null;
    }

    private function dayMatches(DateTimeImmutable $time): bool
    {
        $dayMatches = in_array((int) $time->format('j'), $this->fields[2], true);
        $weekdayMatches = in_array((int) $time->format('w'), $this->fields[4], true);

        return match (true) {
            $this->restricted[0] && $this->restricted[1] => $dayMatches || $weekdayMatches,
            $this->restricted[0] => $dayMatches,
            $this->restricted[1] => $weekdayMatches,
            default => true,
        };
    }

    /** @return list<int> */
    private static function parseField(string $field, int $index, string $expression): array
    {
        [$min, $max] = self::RANGES[$index];
        $values = [];
        foreach (explode(',', $field) as $part) {
            if (preg_match('#^(\*|[a-z0-9]+(?:-[a-z0-9]+)?)(?:/(\d+))?$#D', $part, $match) !== 1) {
                throw new InvalidArgumentException('Invalid cron expression "' . $expression . '": cannot read "' . $part . '".');
            }
            $step = isset($match[2]) ? (int) $match[2] : 1;
            if ($step < 1) {
                throw new InvalidArgumentException('Invalid cron expression "' . $expression . '": step must be at least 1.');
            }
            if ($match[1] === '*') {
                [$from, $to] = [$min, $max];
            } elseif (str_contains($match[1], '-')) {
                [$from, $to] = array_map(static fn (string $v): int => self::value($v, $index, $expression), explode('-', $match[1], 2));
            } else {
                $from = self::value($match[1], $index, $expression);
                $to = isset($match[2]) ? $max : $from;
            }
            if ($index === 4 && $to === 7) {
                $to = 6;
                $values[] = 0;
            }
            if ($from < $min || $to > $max || $from > $to) {
                throw new InvalidArgumentException('Invalid cron expression "' . $expression . '": "' . $part . '" is out of range ' . $min . '-' . $max . '.');
            }
            for ($value = $from; $value <= $to; $value += $step) {
                $values[] = $value;
            }
        }
        $values = array_values(array_unique($values));
        sort($values);

        return $values;
    }

    private static function value(string $value, int $index, string $expression): int
    {
        if (ctype_digit($value)) {
            $number = (int) $value;

            return $index === 4 && $number === 7 ? 0 : $number;
        }
        if (isset(self::NAMES[$index][$value])) {
            return self::NAMES[$index][$value];
        }

        throw new InvalidArgumentException('Invalid cron expression "' . $expression . '": unknown value "' . $value . '".');
    }
}
