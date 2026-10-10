<?php
declare(strict_types=1);

namespace FloCMS\CLI\Tests\Commands;

use FloCMS\CLI\Console\Testing\ApplicationTester;
use FloCMS\CLI\Project;
use FloCMS\CLI\Scheduling\Maintenance;
use FloCMS\CLI\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

final class ScheduleCommandsTest extends TestCase
{
    private TempProject $project;

    protected function setUp(): void
    {
        $this->project = new TempProject();
        $this->project->write('.env', "APP_ENV=local\n");
        mkdir($this->project->path('storage/logs'), 0777, true);
        $this->project->write('commands/Ping.php', '<?php
namespace App\Commands;
use FloCMS\CLI\Console\Input\Argument;
use FloCMS\CLI\Console\Input\Definition;
final class Ping extends \FloCMS\CLI\Console\Command {
    protected string $name = "ping";
    protected function configure(Definition $d): void { $d->argument("who", Argument::OPTIONAL, "", "world"); }
    protected function handle(): int {
        file_put_contents($this->project()->root() . "/pinged.txt", $this->argument("who") . "\n", FILE_APPEND);
        return $this->argument("who") === "fail" ? 3 : 0;
    }
}');
        $this->project->write('config/schedule.php', '<?php
use FloCMS\CLI\Scheduling\Schedule;
return static function (Schedule $schedule): void {
    $schedule->command("ping", ["daily"])->dailyAt("03:10");
    $schedule->command("ping", ["often"])->everyFiveMinutes();
    $schedule->command("ping", ["fail"])->hourlyAt(7);
    $schedule->command("ping", ["prod"])->everyMinute()->environments("production");
    $schedule->call(static function (): int { throw new RuntimeException("boom"); }, "explode")->dailyAt("04:00");
};');
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    private function tester(): ApplicationTester
    {
        return ApplicationTester::forProject($this->project->root);
    }

    private function pinged(): string
    {
        return is_file($this->project->path('pinged.txt')) ? $this->project->read('pinged.txt') : '';
    }

    public function testRunsOnlyTheDueTasks(): void
    {
        $tester = $this->tester();

        self::assertSame(0, $tester->run(['schedule:run', '--time=2026-10-10 03:10']));
        self::assertSame("daily\noften\n", $this->pinged());
        self::assertStringContainsString('Running ping daily', $tester->getDisplay());

        self::assertSame(0, $tester->run(['schedule:run', '--time=2026-10-10 03:11']));
        self::assertSame("daily\noften\n", $this->pinged());

        $log = $this->project->read('storage/logs/schedule.log');
        self::assertStringContainsString('ok: ping daily', $log);
    }

    public function testFailuresAreReportedAndLogged(): void
    {
        $tester = $this->tester();

        self::assertSame(1, $tester->run(['schedule:run', '--time=2026-10-10 05:07']));
        self::assertStringContainsString('failed exit code 3', $tester->getDisplay());
        self::assertSame(1, $tester->run(['schedule:run', '--time=2026-10-10 04:00']));
        self::assertStringContainsString('RuntimeException: boom', $tester->getDisplay());
        self::assertStringContainsString('failed: explode (RuntimeException: boom)', $this->project->read('storage/logs/schedule.log'));
    }

    public function testEnvironmentsAndForcedTasks(): void
    {
        $tester = $this->tester();
        $tester->run(['schedule:run', '--time=2026-10-10 05:01']);
        self::assertSame('', $this->pinged());

        self::assertSame(0, $tester->run(['schedule:run', '--task=ping prod']));
        self::assertSame("prod\n", $this->pinged());
        self::assertSame(1, $tester->run(['schedule:run', '--task=nope']));
        self::assertSame(2, $tester->run(['schedule:run', '--time=yesterday-ish']));
    }

    public function testRunningTasksAreNotStartedTwice(): void
    {
        $tester = $this->tester();
        $tester->run(['schedule:list', '--format=json']);
        mkdir($this->project->path('storage/framework/schedule'), 0777, true);
        // Find the lock id of "ping often" by running it once
        $tester->run(['schedule:run', '--task=ping often']);
        $state = json_decode($this->project->read('storage/framework/schedule/state.json'), true);
        $id = (string) array_key_first(array_filter($state, static fn (array $s): bool => $s['name'] === 'ping often'));

        $lock = fopen($this->project->path('storage/framework/schedule/' . $id . '.lock'), 'c');
        flock($lock, LOCK_EX);
        try {
            self::assertSame(0, $tester->run(['schedule:run', '--task=ping often']));
            self::assertStringContainsString('skipped still running', $tester->getDisplay());
            self::assertSame("often\n", $this->pinged());
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function testList(): void
    {
        $tester = $this->tester();
        $tester->run(['schedule:run', '--task=ping daily']);

        self::assertSame(0, $tester->run(['schedule:list', '--time=2026-10-10 03:10']));
        $display = $tester->getDisplay();
        self::assertMatchesRegularExpression('/\| ping daily +\| 10 3 \* \* \* +\| 2026-10-11 03:10 +\| \d{4}-\d\d-\d\d \d\d:\d\d ok +\|/', $display);
        self::assertMatchesRegularExpression('/\| ping often +\| \*\/5 \* \* \* \* +\| 2026-10-10 03:15 +\| never +\|/', $display);
        self::assertStringContainsString('not in local', $display);
        self::assertStringContainsString('schedule:run', $display);

        $tester->run(['schedule:list', '--format=json']);
        self::assertCount(5, json_decode($tester->getDisplay(), true));
    }

    public function testBuiltInTasksWithoutAScheduleFile(): void
    {
        unlink($this->project->path('config/schedule.php'));
        $tester = $this->tester();

        $tester->run(['schedule:list', '--format=json']);
        $tasks = array_column(json_decode($tester->getDisplay(), true), 'task');
        self::assertSame(['rotate logs', 'clean storage/uploads/.chunks'], $tasks, 'api:gc only when flocms-api is installed');

        $this->project->write('commands/Gc.php', '<?php
namespace App\Commands;
final class Gc extends \FloCMS\CLI\Console\Command { protected string $name = "api:gc"; protected function handle(): int { return 0; } }');
        $tester = $this->tester();
        $tester->run(['schedule:list', '--format=json']);
        self::assertSame(['api:gc', 'rotate logs', 'clean storage/uploads/.chunks'], array_column(json_decode($tester->getDisplay(), true), 'task'));
    }

    public function testCleanDirectoryAndRotateLogs(): void
    {
        $project = new Project($this->project->root);
        $old = $this->project->write('storage/uploads/.chunks/abc/part1', 'x');
        $new = $this->project->write('storage/uploads/.chunks/def/part1', 'x');
        $this->project->write('storage/uploads/.chunks/.gitignore', '*');
        touch($old, time() - 30 * 3600);

        self::assertSame(1, Maintenance::cleanDirectory($project, 'storage/uploads/.chunks', 24));
        self::assertFileDoesNotExist($old);
        self::assertDirectoryDoesNotExist(dirname($old));
        self::assertFileExists($new);
        self::assertFileExists($this->project->path('storage/uploads/.chunks/.gitignore'));
        self::assertSame(0, Maintenance::cleanDirectory($project, 'storage/missing', 24));

        $log = $this->project->write('storage/logs/app.log', str_repeat('x', 100));
        $this->project->write('storage/logs/small.log', 'x');
        for ($i = 1; $i <= 3; $i++) {
            self::assertSame(1, Maintenance::rotateLogs($project, 50, 2));
            file_put_contents($log, str_repeat((string) $i, 100));
        }
        self::assertSame('', file_get_contents($this->project->path('storage/logs/app.log')) === false ? 'missing' : '');
        self::assertSame(str_repeat('2', 100), file_get_contents($log . '.1'));
        self::assertSame(str_repeat('1', 100), file_get_contents($log . '.2'));
        self::assertFileDoesNotExist($log . '.3');
        self::assertFileDoesNotExist($this->project->path('storage/logs/small.log.1'));
    }
}
