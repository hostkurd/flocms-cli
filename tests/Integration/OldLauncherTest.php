<?php
declare(strict_types=1);

namespace FloCMS\CLI\Tests\Integration;

use FloCMS\CLI\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

/**
 * Sites keep the root `flo` file they were created with. Older launchers run
 * vendor/bin/flo, which must boot the same kernel for the project that owns
 * vendor/ and pass the exit code back.
 */
final class OldLauncherTest extends TestCase
{
    private TempProject $project;

    protected function setUp(): void
    {
        $this->project = new TempProject();
        $cli = dirname(__DIR__, 2);
        // An installed copy: <project>/vendor/hostkurd/flocms-cli/bin/flo
        $this->project->write('vendor/autoload.php', '<?php return require ' . var_export($cli . '/vendor/autoload.php', true) . ';');
        $this->project->write('vendor/hostkurd/flocms-cli/bin/flo', (string) file_get_contents($cli . '/bin/flo'));
        // Composer's bin proxy
        $this->project->write('vendor/bin/flo', "<?php\n\$GLOBALS['_composer_autoload_path'] = __DIR__ . '/..'.'/autoload.php';\ninclude __DIR__ . '/..'.'/hostkurd/flocms-cli/bin/flo';\n");
        $this->project->write('commands/Hello.php', '<?php
namespace App\Commands;
final class Hello extends \FloCMS\CLI\Console\Command {
    protected string $name = "hello";
    protected function handle(): int { $this->line("hello from " . basename($this->project()->root())); return 3; }
}');
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    /** @return list<array{0: string, 1: string}> */
    public static function launchers(): array
    {
        return [
            // FloCMS 1.2.4 - 1.4
            'skeleton 1.2.4' => ['1.2.4', <<<'PHP'
#!/usr/bin/env php
<?php

require __DIR__ . '/vendor/autoload.php';

$bin = __DIR__ . '/vendor/bin/flo';
$php = PHP_BINARY;

$args = array_slice($argv, 1);
$cmd = escapeshellarg($php) . ' ' . escapeshellarg($bin);
if ($args) {
    $cmd .= ' ' . implode(' ', array_map('escapeshellarg', $args));
}

passthru($cmd, $exitCode);
exit($exitCode);
PHP],
            // FloCMS 1.6 forwards everything but key:generate and api:* to vendor/bin/flo
            'skeleton 1.6.0' => ['1.6.0', <<<'PHP'
#!/usr/bin/env php
<?php

require __DIR__ . '/vendor/autoload.php';

$args = array_slice($argv, 1);

$bin = __DIR__ . '/vendor/bin/flo';
$php = PHP_BINARY;

$cmd = escapeshellarg($php) . ' ' . escapeshellarg($bin);
if ($args) {
    $cmd .= ' ' . implode(' ', array_map('escapeshellarg', $args));
}

passthru($cmd, $exitCode);
exit($exitCode);
PHP],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('launchers')]
    public function testOldLaunchersRunTheNewKernel(string $version, string $launcher): void
    {
        $this->project->write('flo', $launcher);

        $list = $this->project->flo(['list']);
        self::assertSame(0, $list['code'], $list['stderr']);
        self::assertStringContainsString('key:generate', $list['stdout']);
        self::assertStringContainsString('hello', $list['stdout']);

        $hello = $this->project->flo(['hello']);
        self::assertSame(3, $hello['code']);
        self::assertSame('hello from ' . basename($this->project->root) . "\n", $hello['stdout']);

        $unknown = $this->project->flo(['no-such-command']);
        self::assertSame(1, $unknown['code']);
        self::assertStringContainsString('is not defined', $unknown['stderr']);
    }
}
