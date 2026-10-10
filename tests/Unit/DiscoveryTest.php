<?php
declare(strict_types=1);

namespace FloCMS\CLI\Tests\Unit;

use FloCMS\CLI\Console\Output\Output;
use FloCMS\CLI\Discovery\PackageManifest;
use FloCMS\CLI\Kernel;
use FloCMS\CLI\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

final class DiscoveryTest extends TestCase
{
    private TempProject $project;

    protected function setUp(): void
    {
        $this->project = new TempProject();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testReadsPackageCommandsAndMigrationsFromInstalledJson(): void
    {
        $this->project->installed([
            [
                'name' => 'hostkurd/flocms-api',
                'install-path' => '../hostkurd/flocms-api',
                'extra' => ['flocms' => ['commands' => ['Api\\FooCommand'], 'migrations' => ['database/migrations']]],
            ],
            ['name' => 'other/package', 'install-path' => '../other/package'],
        ]);

        $manifest = new PackageManifest($this->project->root);

        self::assertSame(['hostkurd/flocms-api' => ['Api\\FooCommand']], $manifest->commands());
        self::assertSame(
            ['hostkurd/flocms-api' => [$this->project->path('vendor/hostkurd/flocms-api/database/migrations')]],
            $manifest->migrations()
        );
    }

    public function testReadsTheProjectsOwnComposerExtra(): void
    {
        $this->project->write('composer.json', json_encode([
            'name' => 'test/site',
            'extra' => ['flocms' => ['commands' => ['App\\Other\\BarCommand']]],
        ]));

        self::assertSame(['test/site' => ['App\\Other\\BarCommand']], (new PackageManifest($this->project->root))->commands());
    }

    public function testRegistersPackageCommandsWithTheKernel(): void
    {
        $class = 'FloCMSCliTestPackage' . bin2hex(random_bytes(4)) . '\\PingCommand';
        [$namespace, $short] = explode('\\', $class);
        eval('namespace ' . $namespace . '; final class ' . $short . ' extends \FloCMS\CLI\Console\Command {
            protected string $name = "pkg:ping"; protected string $description = "Package ping";
            protected function handle(): int { $this->line("pong"); return 0; } }');
        $this->project->installed([
            ['name' => 'vendor/pkg', 'install-path' => '../vendor/pkg', 'extra' => ['flocms' => ['commands' => [$class, 'Missing\\Command']]]],
        ]);

        [$app, $out] = $this->boot();

        self::assertTrue($app->has('pkg:ping'));
        self::assertSame(0, $app->run(['pkg:ping']));
        self::assertSame("pong\n", $this->read($out));
        self::assertStringContainsString('Missing\\Command', implode("\n", Kernel::warnings()));
    }

    public function testDiscoversApplicationCommandsInCommandsFolder(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $this->project->write('commands/Reports/Daily' . $suffix . 'Command.php', '<?php
namespace App\Commands\Reports;
final class Daily' . $suffix . 'Command extends \FloCMS\CLI\Console\Command {
    protected string $name = "report:daily";
    protected function handle(): int { $this->line("daily report"); return 0; }
}');
        $this->project->write('commands/Helper' . $suffix . '.php', '<?php
namespace App\Commands;
final class Helper' . $suffix . ' {}');

        [$app, $out] = $this->boot();

        self::assertTrue($app->has('report:daily'));
        self::assertSame(0, $app->run(['report:daily']));
        self::assertSame("daily report\n", $this->read($out));
    }

    public function testApplicationCommandsOverrideBuiltIns(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $this->project->write('commands/List' . $suffix . '.php', '<?php
namespace App\Commands;
final class List' . $suffix . ' extends \FloCMS\CLI\Console\Command {
    protected string $name = "list";
    protected function handle(): int { $this->line("custom list"); return 0; }
}');

        [$app, $out] = $this->boot();
        $app->run([]);

        self::assertSame("custom list\n", $this->read($out));
    }

    /** @return array{0: \FloCMS\CLI\Console\Application, 1: resource} */
    private function boot(): array
    {
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');

        return [Kernel::boot($this->project->root, new Output($out, $err)), $out];
    }

    /** @param resource $stream */
    private function read($stream): string
    {
        rewind($stream);

        return (string) stream_get_contents($stream);
    }
}
