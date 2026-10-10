<?php
declare(strict_types=1);

namespace FloCMS\CLI\Tests\Commands;

use FloCMS\CLI\Commands\CompletionCommand;
use FloCMS\CLI\Console\Testing\ApplicationTester;
use FloCMS\CLI\Tests\Support\TempProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CompletionTest extends TestCase
{
    private TempProject $project;

    private ApplicationTester $tester;

    protected function setUp(): void
    {
        $this->project = new TempProject();
        $this->project->write('commands/Reports.php', '<?php
namespace App\Commands;
final class Reports extends \FloCMS\CLI\Console\Command { protected string $name = "reports:send"; protected function handle(): int { return 0; } }');
        $this->tester = ApplicationTester::forProject($this->project->root);
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    /** @return list<string> */
    private function complete(int $index, string ...$words): array
    {
        self::assertSame(0, $this->tester->run(['_complete', '--index=' . $index, '--', ...$words]));

        return array_values(array_filter(explode("\n", $this->tester->getDisplay())));
    }

    public function testCommandNames(): void
    {
        $all = $this->complete(1, '');
        self::assertContains('make:controller', $all);
        self::assertContains('create:controller', $all, 'aliases complete too');
        self::assertContains('reports:send', $all, 'application commands complete too');
        self::assertNotContains('_complete', $all);

        self::assertSame(['migrate', 'migrate:fresh', 'migrate:rollback', 'migrate:status'], $this->complete(1, 'mig'));
        self::assertSame(['make:model', 'make:module'], $this->complete(1, 'make:mo'));
    }

    public function testOptions(): void
    {
        self::assertSame(['--force'], $this->complete(2, 'make:controller', '--fo'));
        self::assertSame(['--format='], $this->complete(2, 'list', '--fo'));
        $options = $this->complete(3, 'make:controller', 'Blog', '--');
        self::assertContains('--resource', $options);
        self::assertContains('--no-interaction', $options);
        self::assertContains('--env=', $options);
        self::assertSame([], $this->complete(3, 'make:controller', 'Blog', 'x'));
        self::assertSame([], $this->complete(2, 'nope', '--'));
    }

    #[DataProvider('shells')]
    public function testScripts(string $shell, string $marker): void
    {
        self::assertSame(0, $this->tester->run(['completion', $shell]));
        self::assertStringContainsString($marker, $this->tester->getDisplay());
        self::assertStringContainsString('_complete', $this->tester->getDisplay());
    }

    public static function shells(): array
    {
        return [
            ['bash', 'complete -o default -F _flo_complete flo ./flo'],
            ['zsh', 'compdef _flo flo ./flo'],
            ['fish', 'complete -c flo -f'],
            ['powershell', 'Register-ArgumentCompleter -Native -CommandName php'],
        ];
    }

    public function testUnknownShell(): void
    {
        self::assertSame(2, $this->tester->run(['completion', 'tcsh']));
    }

    public function testBashScriptCompletesThroughTheLauncher(): void
    {
        if (DIRECTORY_SEPARATOR === '\\' || trim((string) shell_exec('command -v bash')) === '') {
            self::markTestSkipped('bash is not available.');
        }
        $this->project->launcher();
        $script = $this->project->write('completion.bash', CompletionCommand::script('bash'));
        $run = static fn (string $line): string => (string) shell_exec('cd ' . escapeshellarg(dirname($script)) . ' && bash -c ' . escapeshellarg(
            'php() { ' . escapeshellarg(PHP_BINARY) . ' "$@"; }; source ' . escapeshellarg($script) . '; COMP_LINE=' . escapeshellarg($line) . '; COMP_POINT=${#COMP_LINE}; _flo_complete; printf "%s\n" "${COMPREPLY[@]}"'
        ));

        // bash treats ":" as a word break, so only the part after the last ":" is offered
        self::assertSame("status\n", $run('./flo migrate:st'));
        self::assertSame("migrate\nmigrate:fresh\nmigrate:rollback\nmigrate:status\n", $run('./flo migra'));
        self::assertStringContainsString("controller\n", $run('./flo make:c'));
        self::assertStringContainsString('--resource', $run('./flo make:controller Blog --res'));
    }
}
