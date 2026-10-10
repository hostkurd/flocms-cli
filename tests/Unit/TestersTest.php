<?php
declare(strict_types=1);

namespace FloCMS\CLI\Tests\Unit;

use FloCMS\CLI\Console\Application;
use FloCMS\CLI\Console\ClosureCommand;
use FloCMS\CLI\Console\Command;
use FloCMS\CLI\Console\Input\Argument;
use FloCMS\CLI\Console\Input\Definition;
use FloCMS\CLI\Console\Input\Input;
use FloCMS\CLI\Console\Input\Option;
use FloCMS\CLI\Console\Output\Output;
use FloCMS\CLI\Console\Testing\ApplicationTester;
use FloCMS\CLI\Console\Testing\CommandTester;
use FloCMS\CLI\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

final class TestersTest extends TestCase
{
    public function testCommandTesterCapturesOutputErrorsAndExitCode(): void
    {
        $tester = new CommandTester(self::greet());

        self::assertSame(0, $tester->execute(['World', '--shout']));
        self::assertSame("Hello World!\n", $tester->getDisplay());
        self::assertSame('', $tester->getErrorOutput());
        $tester->assertCommandIsSuccessful();

        self::assertSame(1, $tester->execute(['nobody']));
        self::assertSame("No one to greet\n", $tester->getErrorOutput());
    }

    public function testArgumentsCanBePassedByName(): void
    {
        $tester = new CommandTester(self::greet());

        $tester->execute(['who' => '--odd-name', '--shout' => true]);

        self::assertSame("Hello --odd-name!\n", $tester->getDisplay());
        self::assertSame(['--shout', '--', 'a', 'b'], CommandTester::tokens(['who' => ['a', 'b'], '--shout' => true, '--quiet' => false]));
        self::assertSame(['--scopes=a,b'], CommandTester::tokens(['--scopes' => 'a,b']));
    }

    public function testInputsFeedThePromptStream(): void
    {
        $command = new ClosureCommand('ask', '', static function (Input $input, Output $output): int {
            $output->writeln('got ' . trim((string) fgets($input->stream())) . ' and ' . trim((string) fgets($input->stream())));

            return $input->isInteractive() ? 0 : 5;
        });
        $tester = new CommandTester($command);

        self::assertSame(0, $tester->execute([], ['yes', 'no']));
        self::assertSame("got yes and no\n", $tester->getDisplay());
        self::assertSame(5, $tester->execute([], [], ['interactive' => false]));
    }

    public function testApplicationTesterRunsAProject(): void
    {
        $project = new TempProject();
        try {
            $tester = ApplicationTester::forProject($project->root);

            self::assertSame(0, $tester->run(['list']));
            self::assertStringContainsString('create:controller', $tester->getDisplay());
            self::assertSame(1, $tester->run(['nope']));
            self::assertStringContainsString('"nope"', $tester->getErrorOutput());
        } finally {
            $project->remove();
        }
    }

    public function testCommandTesterReusesAnApplication(): void
    {
        $application = new Application();
        $command = self::greet();
        $application->add($command);

        $tester = new CommandTester($command, null, $application);
        $tester->execute(['you']);

        self::assertSame("Hello you.\n", $tester->getDisplay());
    }

    private static function greet(): Command
    {
        return new ClosureCommand(
            'greet',
            'Say hello',
            static function (Input $input, Output $output): int {
                if ($input->argument('who') === 'nobody') {
                    $output->error('No one to greet');

                    return 1;
                }
                $output->writeln('Hello ' . $input->argument('who') . ($input->option('shout') ? '!' : '.'));

                return 0;
            },
            static fn (Definition $d) => $d->argument('who', Argument::REQUIRED)->option('shout', 's', Option::VALUE_NONE)
        );
    }
}
