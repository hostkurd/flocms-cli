<?php
declare(strict_types=1);

namespace FloCMS\CLI\Tests\Unit;

use FloCMS\CLI\Console\ClosureCommand;
use FloCMS\CLI\Console\Command;
use FloCMS\CLI\Console\Prompt;
use FloCMS\CLI\Console\Testing\CommandTester;
use PHPUnit\Framework\TestCase;

final class PromptTest extends TestCase
{
    private static function command(callable $ask): Command
    {
        return new class ($ask) extends Command {
            protected string $name = 'ask';

            public function __construct(private $ask)
            {
            }

            protected function handle(): int
            {
                $this->line(var_export(($this->ask)($this), true));

                return 0;
            }

            public function __call(string $method, array $args): mixed
            {
                return $this->{$method}(...$args);
            }
        };
    }

    public function testAskUsesTheAnswerOrTheDefault(): void
    {
        $tester = new CommandTester(self::command(static fn ($c) => $c->ask('Name?', 'World')));

        $tester->execute([], ['Ada']);
        self::assertSame("'Ada'\n", $tester->getDisplay());
        self::assertStringContainsString('Name? [World]', $tester->getErrorOutput());

        $tester->execute([], ['']);
        self::assertSame("'World'\n", $tester->getDisplay());

        $tester->execute([], [], ['interactive' => false]);
        self::assertSame("'World'\n", $tester->getDisplay());
    }

    public function testAskValidatesAndRetries(): void
    {
        $tester = new CommandTester(self::command(static fn ($c) => $c->ask('Email?', null, static fn (string $v) => str_contains($v, '@') ? null : 'Enter a valid email.')));

        $tester->execute([], ['nope', 'a@b.c']);

        self::assertSame("'a@b.c'\n", $tester->getDisplay());
        self::assertStringContainsString('Enter a valid email.', $tester->getErrorOutput());
    }

    public function testQuestionWithoutDefaultFailsWithoutInteraction(): void
    {
        $tester = new CommandTester(self::command(static fn ($c) => $c->ask('Email?')));

        self::assertSame(1, $tester->execute([], [], ['interactive' => false]));
        self::assertStringContainsString('"Email?" needs an answer', $tester->getErrorOutput());
    }

    public function testConfirm(): void
    {
        $tester = new CommandTester(self::command(static fn ($c) => $c->confirm('Delete?')));

        $tester->execute([], ['y']);
        self::assertSame("true\n", $tester->getDisplay());
        $tester->execute([], ['maybe', 'no']);
        self::assertSame("false\n", $tester->getDisplay());
        $tester->execute([], ['']);
        self::assertSame("false\n", $tester->getDisplay());

        $yes = new CommandTester(self::command(static fn ($c) => $c->confirm('Continue?', true)));
        $yes->execute([], [], ['interactive' => false]);
        self::assertSame("true\n", $yes->getDisplay());
    }

    public function testChoice(): void
    {
        $roles = [0 => 'User', 1 => 'Editor', 2 => 'Admin', 3 => 'Super Admin'];
        $tester = new CommandTester(self::command(static fn ($c) => $c->choice('Role?', $roles, 0)));

        $tester->execute([], ['3']);
        self::assertSame("3\n", $tester->getDisplay());
        $tester->execute([], ['admin']);
        self::assertSame("2\n", $tester->getDisplay());
        $tester->execute([], ['9', '1']);
        self::assertSame("1\n", $tester->getDisplay());
        $tester->execute([], [], ['interactive' => false]);
        self::assertSame("0\n", $tester->getDisplay());

        $list = new CommandTester(self::command(static fn ($c) => $c->choice('Driver?', ['mysql', 'sqlite'])));
        $list->execute([], ['sqlite']);
        self::assertSame("1\n", $list->getDisplay());
    }

    public function testSecretReadsFromNonTerminalInput(): void
    {
        $tester = new CommandTester(self::command(static fn ($c) => $c->secret('Password?')));

        $tester->execute([], ['s3cret!']);

        self::assertSame("'s3cret!'\n", $tester->getDisplay());
        self::assertStringNotContainsString('s3cret', $tester->getErrorOutput());
    }

    public function testWindowsHiddenInputCanPrompt(): void
    {
        $command = Prompt::windowsHiddenCommand();

        // Read-Host fails in -NonInteractive mode, so the password prompt was skipped
        self::assertStringNotContainsString('-NonInteractive', $command);
        self::assertStringContainsString('Read-Host -AsSecureString', $command);
    }

    public function testOutputHelpers(): void
    {
        $tester = new CommandTester(new ClosureCommand('x', '', static fn () => 0));
        $command = self::command(static function ($c): string {
            $c->title('Title');
            $c->success('Saved');
            $c->definitionList(['PHP' => '8.3', 'Debug' => false, 'Key' => null]);
            $c->table(['a'], [['1']]);
            $c->warn('Careful');

            return 'end';
        });
        $tester = new CommandTester($command);
        $tester->execute();

        $display = $tester->getDisplay();
        self::assertStringContainsString("Title\n=====\n", $display);
        self::assertStringContainsString('[OK] Saved', $display);
        self::assertStringContainsString('  PHP    8.3', $display);
        self::assertStringContainsString('  Debug  no', $display);
        self::assertStringContainsString('| 1 |', $display);
        self::assertStringContainsString('WARNING  Careful', $tester->getErrorOutput());
    }
}
