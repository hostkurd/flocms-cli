<?php
declare(strict_types=1);

namespace FloCMS\CLI\Tests\Commands;

use FloCMS\CLI\Commands\Log\LogFiles;
use FloCMS\CLI\Console\Testing\ApplicationTester;
use FloCMS\CLI\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

final class LogCommandsTest extends TestCase
{
    private TempProject $project;

    private ApplicationTester $tester;

    protected function setUp(): void
    {
        $this->project = new TempProject();
        $lines = [];
        for ($i = 1; $i <= 120; $i++) {
            $lines[] = '[2026-10-10 10:00:' . sprintf('%02d', $i % 60) . '] ' . ($i % 10 === 0 ? 'error' : 'info') . ': line ' . $i;
        }
        $this->project->write('storage/logs/app.log', implode("\n", $lines) . "\n");
        $this->project->write('storage/logs/api.log', "[2026-10-10 09:00:00] warning: <script> slow\n");
        touch($this->project->path('storage/logs/api.log'), time() - 3600);
        $this->tester = ApplicationTester::forProject($this->project->root);
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testTail(): void
    {
        self::assertSame(0, $this->tester->run(['log:tail']));
        $lines = explode("\n", rtrim($this->tester->getDisplay()));
        self::assertCount(50, $lines);
        self::assertStringEndsWith('error: line 120', $lines[49]);
        self::assertStringContainsString('==> storage/logs/app.log <==', $this->tester->getErrorOutput());

        $this->tester->run(['log:tail', 'api', '--lines=5']);
        self::assertSame("[2026-10-10 09:00:00] warning: <script> slow\n", $this->tester->getDisplay());

        self::assertSame(1, $this->tester->run(['log:tail', 'nope']));
        self::assertStringContainsString('Logs: app.log, api.log', $this->tester->getErrorOutput());
        self::assertSame(2, $this->tester->run(['log:tail', '../.env']));
        self::assertSame(2, $this->tester->run(['log:tail', '--lines=0']));
    }

    public function testTailReadsLargeFilesFromTheEnd(): void
    {
        $file = $this->project->write('storage/logs/big.log', str_repeat(str_repeat('x', 99) . "\n", 5000) . "last\n");

        self::assertSame(['last'], LogFiles::tail($file, 1));
        self::assertCount(3, LogFiles::tail($file, 3));
        self::assertSame([], LogFiles::tail($this->project->write('storage/logs/empty.log', ''), 10));
    }

    public function testClear(): void
    {
        $this->project->write('storage/logs/app.log.1', 'old');

        self::assertSame(1, $this->tester->run(['log:clear'], ['no']));
        self::assertNotSame('', $this->project->read('storage/logs/app.log'));
        self::assertSame(1, $this->tester->run(['log:clear'], [], ['interactive' => false]));

        self::assertSame(0, $this->tester->run(['log:clear', 'api', '--force']));
        self::assertSame('', $this->project->read('storage/logs/api.log'));
        self::assertNotSame('', $this->project->read('storage/logs/app.log'));

        self::assertSame(0, $this->tester->run(['log:clear'], ['yes']));
        self::assertStringContainsString('3 log file(s) cleared.', $this->tester->getDisplay());
        self::assertSame('', $this->project->read('storage/logs/app.log'));
        self::assertFileDoesNotExist($this->project->path('storage/logs/app.log.1'));
    }

    public function testRotate(): void
    {
        self::assertSame(0, $this->tester->run(['log:rotate']));
        self::assertStringContainsString('0 log file(s) rotated.', $this->tester->getDisplay());
        self::assertSame(2, $this->tester->run(['log:rotate', '--keep=0']));
    }

    public function testStorageCheck(): void
    {
        self::assertSame(1, $this->tester->run(['storage:check']));
        self::assertMatchesRegularExpression('/\| views\/cache\/ +\| - +\| - +\| missing +\|/', $this->tester->getDisplay());

        self::assertSame(0, $this->tester->run(['storage:check', '--fix']), $this->tester->getDisplay());
        self::assertDirectoryExists($this->project->path('public/uploads'));
        self::assertStringContainsString('All storage directories are writable.', $this->tester->getDisplay());
    }
}
