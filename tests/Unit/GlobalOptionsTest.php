<?php
declare(strict_types=1);

namespace FloCMS\CLI\Tests\Unit;

use FloCMS\CLI\Console\Application;
use FloCMS\CLI\Console\ClosureCommand;
use FloCMS\CLI\Console\Input\Input;
use FloCMS\CLI\Console\Output\Output;
use FloCMS\CLI\Console\Testing\ApplicationTester;
use PHPUnit\Framework\TestCase;

final class GlobalOptionsTest extends TestCase
{
    private ApplicationTester $tester;

    protected function setUp(): void
    {
        $app = new Application('FloCMS CLI', '2.0.0');
        $app->add(new ClosureCommand('show', 'Show the state', static function (Input $input, Output $output): int {
            $output->writeln('normal');
            $output->writeln('verbose', Output::VERBOSE);
            $output->writeln('debug', Output::DEBUG);
            $output->writeln('interactive=' . ($input->isInteractive() ? 'yes' : 'no'));
            $output->writeln('env=' . getenv('APP_ENV'));
            $output->error('problem');

            return 0;
        }));
        $this->tester = new ApplicationTester($app);
    }

    protected function tearDown(): void
    {
        putenv('APP_ENV');
        unset($_ENV['APP_ENV'], $_SERVER['APP_ENV']);
    }

    public function testQuietKeepsErrorsOnly(): void
    {
        $this->tester->run(['show', '-q']);

        self::assertSame('', $this->tester->getDisplay());
        self::assertSame("problem\n", $this->tester->getErrorOutput());
    }

    public function testVerbosityLevels(): void
    {
        $this->tester->run(['show', '-v']);
        self::assertStringContainsString("verbose\n", $this->tester->getDisplay());
        self::assertStringNotContainsString('debug', $this->tester->getDisplay());

        $this->tester->run(['-vvv', 'show']);
        self::assertStringContainsString("debug\n", $this->tester->getDisplay());
    }

    public function testNoInteractionAndEnv(): void
    {
        $this->tester->run(['show', '-n', '--env=production']);

        self::assertStringContainsString('interactive=no', $this->tester->getDisplay());
        self::assertStringContainsString('env=production', $this->tester->getDisplay());

        $this->tester->run(['--env', 'staging', 'show']);
        self::assertStringContainsString('env=staging', $this->tester->getDisplay());
    }

    public function testVersion(): void
    {
        self::assertSame(0, $this->tester->run(['-V']));
        self::assertSame("FloCMS CLI 2.0.0\n", $this->tester->getDisplay());

        self::assertSame(0, $this->tester->run(['show', '--version']));
        self::assertSame("FloCMS CLI 2.0.0\n", $this->tester->getDisplay());
    }

    public function testHelpOption(): void
    {
        self::assertSame(0, $this->tester->run(['show', '--help']));
        self::assertStringContainsString('show [options]', $this->tester->getDisplay());
        self::assertStringNotContainsString('normal', $this->tester->getDisplay());
    }

    public function testUnknownGlobalOptionWithoutCommand(): void
    {
        self::assertSame(2, $this->tester->run(['--nope']));
        self::assertStringContainsString('"--nope"', $this->tester->getErrorOutput());
    }
}
