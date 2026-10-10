<?php
declare(strict_types=1);

namespace FloCMS\CLI\Tests\Integration;

use FloCMS\CLI\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

/**
 * Backlog A5: 0 = success, 1 = failure, 2 = invalid usage; errors on STDERR.
 * Run through a real process, like cron and CI scripts do.
 */
final class ExitCodesTest extends TestCase
{
    private TempProject $project;

    protected function setUp(): void
    {
        $this->project = new TempProject();
        $this->project->write('commands/Boom.php', '<?php
namespace App\Commands;
use FloCMS\CLI\Console\Input\Definition;
use FloCMS\CLI\Console\Input\Argument;
final class Boom extends \FloCMS\CLI\Console\Command {
    protected string $name = "boom";
    protected function configure(Definition $d): void { $d->argument("kind", Argument::REQUIRED); }
    protected function handle(): int {
        return match ($this->argument("kind")) {
            "ok" => self::SUCCESS,
            "fail" => $this->fail("The import file is missing."),
            "crash" => throw new \RuntimeException("Unexpected state"),
            default => 7,
        };
    }
}');
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testSuccess(): void
    {
        $result = $this->project->flo(['boom', 'ok']);

        self::assertSame(0, $result['code']);
        self::assertSame('', $result['stderr']);
    }

    public function testUnknownCommandExitsWithOneOnStderr(): void
    {
        $result = $this->project->flo(['no-such-command']);

        self::assertSame(1, $result['code']);
        self::assertSame('', $result['stdout']);
        self::assertStringContainsString('[ERROR] Command "no-such-command" is not defined.', $result['stderr']);
    }

    public function testMissingArgumentIsInvalidUsage(): void
    {
        $result = $this->project->flo(['boom']);

        self::assertSame(2, $result['code']);
        self::assertSame('', $result['stdout']);
        self::assertStringContainsString('Not enough arguments (missing: "kind").', $result['stderr']);
        self::assertStringContainsString('Usage: boom [options] [--] <kind>', $result['stderr']);
    }

    public function testUnknownOptionIsInvalidUsage(): void
    {
        self::assertSame(2, $this->project->flo(['boom', 'ok', '--nope'])['code']);
    }

    public function testExpectedFailureShowsOnlyTheMessage(): void
    {
        $result = $this->project->flo(['boom', 'fail']);

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('[ERROR] The import file is missing.', $result['stderr']);
        self::assertStringNotContainsString('CommandFailedException', $result['stderr']);
        self::assertStringNotContainsString('#0 ', $result['stderr']);
    }

    public function testCrashShowsAFriendlyBlockAndTheTraceOnlyWhenAsked(): void
    {
        $result = $this->project->flo(['boom', 'crash']);
        self::assertSame(1, $result['code']);
        self::assertStringContainsString('[ERROR] Unexpected state', $result['stderr']);
        self::assertStringNotContainsString('#0 ', $result['stderr']);
        self::assertStringContainsString('Run with -v for details', $result['stderr']);

        $verbose = $this->project->flo(['boom', 'crash', '-v']);
        self::assertStringContainsString('RuntimeException in ', $verbose['stderr']);
        self::assertStringNotContainsString('#0 ', $verbose['stderr']);

        self::assertStringContainsString('#0 ', $this->project->flo(['boom', 'crash', '-vvv'])['stderr']);
        self::assertStringContainsString('#0 ', $this->project->flo(['boom', 'crash'], null, ['APP_DEBUG' => 'true'])['stderr']);
    }

    public function testCustomExitCodesPassThrough(): void
    {
        self::assertSame(7, $this->project->flo(['boom', 'other'])['code']);
    }
}
