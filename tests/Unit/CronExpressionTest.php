<?php
declare(strict_types=1);

namespace FloCMS\CLI\Tests\Unit;

use DateTimeImmutable;
use FloCMS\CLI\Scheduling\CronExpression;
use FloCMS\CLI\Scheduling\Event;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CronExpressionTest extends TestCase
{
    #[DataProvider('dueCases')]
    public function testIsDue(string $expression, string $time, bool $due): void
    {
        self::assertSame($due, (new CronExpression($expression))->isDue(new DateTimeImmutable($time)), $expression . ' at ' . $time);
    }

    public static function dueCases(): array
    {
        return [
            ['* * * * *', '2026-10-10 13:37', true],
            ['*/5 * * * *', '2026-10-10 13:35', true],
            ['*/5 * * * *', '2026-10-10 13:37', false],
            ['10 3 * * *', '2026-10-10 03:10', true],
            ['10 3 * * *', '2026-10-10 04:10', false],
            ['0 9-17/2 * * *', '2026-10-10 11:00', true],
            ['0 9-17/2 * * *', '2026-10-10 12:00', false],
            ['0 0 1,15 * *', '2026-10-15 00:00', true],
            ['0 0 * * mon-fri', '2026-10-10 00:00', false], // Saturday
            ['0 0 * * mon-fri', '2026-10-12 00:00', true],
            ['0 0 * * 7', '2026-10-11 00:00', true], // 7 = Sunday
            ['0 0 1 jan *', '2026-01-01 00:00', true],
            // Both day fields restricted: either matches (cron semantics)
            ['0 0 13 * fri', '2026-10-09 00:00', true],
            ['0 0 13 * fri', '2026-10-13 00:00', true],
            ['0 0 13 * fri', '2026-10-14 00:00', false],
            ['@daily', '2026-10-10 00:00', true],
            ['@hourly', '2026-10-10 05:01', false],
        ];
    }

    #[DataProvider('nextCases')]
    public function testNextRun(string $expression, string $from, string $next): void
    {
        self::assertSame($next, (new CronExpression($expression))->nextRun(new DateTimeImmutable($from))?->format('Y-m-d H:i'));
    }

    public static function nextCases(): array
    {
        return [
            ['* * * * *', '2026-10-10 13:37:20', '2026-10-10 13:38'],
            ['10 3 * * *', '2026-10-10 03:10', '2026-10-11 03:10'],
            ['10 3 * * *', '2026-10-10 02:00', '2026-10-10 03:10'],
            ['0 0 1 * *', '2026-12-15 10:00', '2027-01-01 00:00'],
            ['0 0 29 2 *', '2026-03-01 00:00', '2028-02-29 00:00'],
            ['30 23 * * 1-5', '2026-10-09 23:31', '2026-10-12 23:30'],
            ['0 */6 * * *', '2026-10-10 23:59', '2026-10-11 00:00'],
        ];
    }

    #[DataProvider('invalid')]
    public function testInvalidExpressions(string $expression): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CronExpression($expression);
    }

    public static function invalid(): array
    {
        return [['* * * *'], ['60 * * * *'], ['* 24 * * *'], ['* * 0 * *'], ['* * * 13 *'], ['*/0 * * * *'], ['5-1 * * * *'], ['* * * * funday'], ['@sometimes']];
    }

    public function testEventHelpers(): void
    {
        $event = new Event('api:gc');
        self::assertSame('10 3 * * *', $event->dailyAt('03:10')->expression());
        self::assertSame('10 3 * * 1-5', $event->weekdays()->expression());
        self::assertSame('0 1,13 * * *', $event->twiceDaily()->expression());
        self::assertSame('30 8 * * 1', $event->weeklyOn(1, '8:30')->expression());
        self::assertSame('0 0 15 * *', $event->monthlyOn(15)->expression());
        self::assertSame('*/15 * * * *', $event->everyFifteenMinutes()->expression());
        self::assertSame('api:gc --database', (new Event('api:gc', ['--database']))->name());

        $this->expectException(InvalidArgumentException::class);
        $event->dailyAt('25:00');
    }

    public function testTimezones(): void
    {
        $event = (new Event('x'))->dailyAt('09:00')->timezone('Asia/Baghdad');

        self::assertTrue($event->isDue(new DateTimeImmutable('2026-10-10 06:00', new \DateTimeZone('UTC'))));
        self::assertFalse($event->isDue(new DateTimeImmutable('2026-10-10 09:00', new \DateTimeZone('UTC'))));
    }
}
