<?php
declare(strict_types=1);

namespace FloCMS\CLI\Tests;

use FloCMS\CLI\Gate;
use PHPUnit\Framework\TestCase;

/**
 * 1.0.5: old root "flo" launchers send every command here. Unknown commands
 * must fail (exit 1, message on STDERR) and point to the launcher upgrade.
 */
final class GateTest extends TestCase
{
    private string $project;

    protected function setUp(): void
    {
        $this->project = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'flocms-cli-' . bin2hex(random_bytes(6));
        mkdir($this->project);
    }

    protected function tearDown(): void
    {
        $this->remove($this->project);
    }

    public function testUnknownCommandExitsWithOneAndPrintsTheUpgradeHintOnStderr(): void
    {
        [$code, $stdout, $stderr] = $this->flo(['migrate']);

        self::assertSame(1, $code);
        self::assertSame('', $stdout);
        self::assertStringContainsString('Command "migrate" not found.', $stderr);
        self::assertStringContainsString('Your flo launcher is outdated', $stderr);
        self::assertStringContainsString(Gate::UPGRADE_URL, $stderr);
        self::assertStringNotContainsString('flo-api', $stderr);
    }

    public function testApiCommandsPointToFloApi(): void
    {
        [$code, , $stderr] = $this->flo(['api:key:create']);

        self::assertSame(1, $code);
        self::assertStringContainsString('Your flo launcher is outdated', $stderr);
        self::assertStringContainsString('php vendor/bin/flo-api api:key:create', $stderr);
    }

    public function testKeyGenerateOnAnOldLauncherGetsTheHint(): void
    {
        [$code, , $stderr] = $this->flo(['key:generate']);

        self::assertSame(1, $code);
        self::assertStringContainsString('Command "key:generate" not found.', $stderr);
    }

    public function testKnownCommandsStillSucceed(): void
    {
        [$code, $stdout] = $this->flo(['--help']);
        self::assertSame(0, $code);
        self::assertStringContainsString('create:controller', $stdout);

        [$code, $stdout] = $this->flo([]);
        self::assertSame(0, $code);
        self::assertStringContainsString('FloCMS Command line interface', $stdout);

        [$code] = $this->flo(['create:model', 'Pages']);
        self::assertSame(0, $code);
        self::assertFileExists($this->project . '/models/PagesModel.php');
    }

    public function testMessageIsReturnedForCallers(): void
    {
        $message = Gate::UnknownCommandMessage('api:list');

        self::assertStringStartsWith('Command "api:list" not found.', $message);
        self::assertStringEndsWith(PHP_EOL, $message);
    }

    /**
     * Run bin/flo the way the root launcher does, from the project directory.
     *
     * @param list<string> $args
     * @return array{0: int, 1: string, 2: string}
     */
    private function flo(array $args): array
    {
        $command = array_merge([PHP_BINARY, dirname(__DIR__) . '/bin/flo'], $args);
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->project);
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }

    private function remove(string $path): void
    {
        if (is_dir($path)) {
            foreach (array_diff((array) scandir($path), ['.', '..']) as $item) {
                $this->remove($path . DIRECTORY_SEPARATOR . $item);
            }
            rmdir($path);
        } elseif (is_file($path)) {
            unlink($path);
        }
    }
}
