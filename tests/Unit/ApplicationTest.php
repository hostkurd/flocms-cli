<?php
declare(strict_types=1);

namespace FloCMS\CLI\Tests\Unit;

use FloCMS\CLI\Console\Application;
use FloCMS\CLI\Console\ClosureCommand;
use FloCMS\CLI\Console\Command;
use FloCMS\CLI\Console\CommandNotFoundException;
use FloCMS\CLI\Console\Input\Argument;
use FloCMS\CLI\Console\Input\Definition;
use FloCMS\CLI\Console\Input\Input;
use FloCMS\CLI\Console\Input\Option;
use FloCMS\CLI\Console\Output\Output;
use PHPUnit\Framework\TestCase;

final class ApplicationTest extends TestCase
{
    /** @var resource */
    private $out;

    /** @var resource */
    private $err;

    private Application $app;

    protected function setUp(): void
    {
        $this->out = fopen('php://memory', 'w+');
        $this->err = fopen('php://memory', 'w+');
        $this->app = new Application('Test', '1.0', null, new Output($this->out, $this->err));
    }

    public function testRegistersAndRunsCommands(): void
    {
        $this->app->add(new ClosureCommand(
            'greet',
            'Say hello',
            static function (Input $input, Output $output): int {
                $output->writeln('Hello ' . $input->argument('who') . ($input->option('shout') ? '!' : '.'));

                return 0;
            },
            static fn (Definition $d) => $d->argument('who', Argument::REQUIRED)->option('shout', 's', Option::VALUE_NONE)
        ));

        self::assertSame(0, $this->app->run(['greet', 'World', '--shout']));
        self::assertSame("Hello World!\n", $this->stdout());
    }

    public function testFindsByAlias(): void
    {
        $this->app->add(new ClosureCommand('make:controller', '', static fn () => 0, null, ['create:controller']));

        self::assertSame('make:controller', $this->app->find('create:controller')->getName());
        self::assertTrue($this->app->has('create:controller'));
    }

    public function testLaterRegistrationReplacesEarlier(): void
    {
        $this->app->add(new ClosureCommand('about', 'built-in', static fn () => 0));
        $this->app->add(new ClosureCommand('about', 'override', static fn () => 0));

        self::assertSame('override', $this->app->find('about')->getDescription());
        self::assertCount(1, $this->app->all());
    }

    public function testUnknownCommandFails(): void
    {
        self::assertSame(1, $this->app->run(['nope']));
        self::assertStringContainsString('Command "nope" is not defined.', $this->stderr());

        $this->expectException(CommandNotFoundException::class);
        $this->app->find('nope');
    }

    public function testExitCodeOfTheCommandIsReturned(): void
    {
        $this->app->add(new ClosureCommand('fail', '', static fn () => 3));

        self::assertSame(3, $this->app->run(['fail']));
    }

    public function testCommandsCanCallOtherCommands(): void
    {
        $this->app->add(new ClosureCommand('inner', '', static function (Input $input, Output $output): int {
            $output->writeln('inner ' . $input->argument('x'));

            return 0;
        }, static fn (Definition $d) => $d->argument('x')));
        $this->app->add(new class extends Command {
            protected string $name = 'outer';

            protected function handle(): int
            {
                return $this->call('inner', ['42']);
            }
        });

        self::assertSame(0, $this->app->run(['outer']));
        self::assertSame("inner 42\n", $this->stdout());
    }

    private function stdout(): string
    {
        rewind($this->out);

        return (string) stream_get_contents($this->out);
    }

    private function stderr(): string
    {
        rewind($this->err);

        return (string) stream_get_contents($this->err);
    }
}
