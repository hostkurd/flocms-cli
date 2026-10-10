<?php
declare(strict_types=1);

namespace FloCMS\CLI\Tests\Unit;

use FloCMS\CLI\Commands\HelpCommand;
use FloCMS\CLI\Commands\ListCommand;
use FloCMS\CLI\Console\Application;
use FloCMS\CLI\Console\ClosureCommand;
use FloCMS\CLI\Console\CommandNotFoundException;
use FloCMS\CLI\Console\Input\Argument;
use FloCMS\CLI\Console\Input\Definition;
use FloCMS\CLI\Console\Input\Option;
use FloCMS\CLI\Console\Testing\ApplicationTester;
use PHPUnit\Framework\TestCase;

final class ListAndHelpTest extends TestCase
{
    private Application $app;

    private ApplicationTester $tester;

    protected function setUp(): void
    {
        $this->app = new Application('FloCMS CLI', '2.0.0');
        $this->app->add(new ListCommand());
        $this->app->add(new HelpCommand());
        foreach (['api:install-schema', 'api:key:create', 'api:key:list', 'api:token:create', 'migrate', 'migrate:status', 'migrate:rollback', 'about', 'key:generate'] as $name) {
            $this->app->add(new ClosureCommand($name, 'Does ' . $name, static fn () => 0));
        }
        $this->app->add(new ClosureCommand(
            'make:controller',
            'Create a controller',
            static fn () => 0,
            static fn (Definition $d) => $d
                ->argument('name', Argument::REQUIRED, 'The controller name')
                ->option('force', 'f', Option::VALUE_NONE, 'Overwrite')
                ->option('table', null, Option::VALUE_REQUIRED, 'Table', 'posts'),
            ['create:controller']
        ));
        $this->app->add(new ClosureCommand('secret:thing', '', static fn () => 0, null, [], true));
        $this->tester = new ApplicationTester($this->app);
    }

    public function testListGroupsCommandsByNamespace(): void
    {
        self::assertSame(0, $this->tester->run([]));
        $display = $this->tester->getDisplay();

        self::assertStringContainsString('FloCMS CLI 2.0.0', $display);
        self::assertStringContainsString('Environment:', $display);
        self::assertStringContainsString('-n, --no-interaction', $display);
        self::assertMatchesRegularExpression('/ api\n  api:install-schema +Does api:install-schema/', $display);
        self::assertMatchesRegularExpression('/ make\n  make:controller +Create a controller \[create:controller\]/', $display);
        // Commands without a namespace come first
        self::assertLessThan(strpos($display, ' api' . "\n"), strpos($display, '  about'));
        self::assertStringNotContainsString('secret:thing', $display);

        $this->tester->run(['list']);
        self::assertSame($display, $this->tester->getDisplay());
        $this->tester->run(['--help']);
        self::assertSame($display, $this->tester->getDisplay());
    }

    public function testListOneNamespace(): void
    {
        $this->tester->run(['list', 'api']);

        self::assertStringContainsString('api:key:create', $this->tester->getDisplay());
        self::assertStringNotContainsString('migrate', $this->tester->getDisplay());
        self::assertSame(1, $this->tester->run(['list', 'nope']));
    }

    public function testListAsJson(): void
    {
        self::assertSame(0, $this->tester->run(['list', '--format=json']));
        $data = json_decode($this->tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(['name' => 'FloCMS CLI', 'version' => '2.0.0'], $data['application']);
        $names = array_column($data['commands'], 'name');
        self::assertContains('make:controller', $names);
        self::assertNotContains('secret:thing', $names);
        $make = $data['commands'][array_search('make:controller', $names, true)];
        self::assertSame(['create:controller'], $make['aliases']);
        self::assertSame('name', $make['arguments'][0]['name']);
        self::assertTrue($make['arguments'][0]['required']);
        self::assertContains('--force', array_column($make['options'], 'name'));
        self::assertSame(2, $this->tester->run(['list', '--format=xml']));
    }

    public function testHelpForACommand(): void
    {
        foreach ([['help', 'make:controller'], ['make:controller', '--help'], ['make:controller', '-h'], ['help', 'create:controller']] as $line) {
            self::assertSame(0, $this->tester->run($line));
            $display = $this->tester->getDisplay();
            self::assertStringContainsString('Create a controller', $display);
            self::assertStringContainsString('make:controller [options] [--] <name>', $display);
            self::assertStringContainsString('The controller name', $display);
            self::assertStringContainsString('-f, --force', $display);
            self::assertStringContainsString("Table [default: 'posts']", $display);
            self::assertStringContainsString('--no-interaction', $display);
        }

        $this->tester->run(['help', 'make:controller', '--format=json']);
        self::assertSame('make:controller', json_decode($this->tester->getDisplay(), true)['name']);
        self::assertSame(1, $this->tester->run(['help', 'nope']));
    }

    public function testDidYouMean(): void
    {
        self::assertSame(1, $this->tester->run(['api:create:schema']));
        self::assertStringContainsString('Did you mean one of these?', $this->tester->getErrorOutput());
        self::assertSame('api:install-schema', $this->app->alternatives('api:create:schema')[0]);

        $this->tester->run(['abut']);
        self::assertStringContainsString('Did you mean "about"?', $this->tester->getErrorOutput());

        $this->tester->run(['zzzzzz']);
        self::assertStringContainsString('Run "php flo list" to see all commands.', $this->tester->getErrorOutput());
    }

    public function testAbbreviations(): void
    {
        self::assertSame('migrate', $this->app->find('mig')->getName());
        self::assertSame('migrate:status', $this->app->find('m:st')->getName());
        self::assertSame('key:generate', $this->app->find('key:gen')->getName());
        self::assertSame('make:controller', $this->app->find('create:cont')->getName());

        try {
            $this->app->find('api:key');
            self::fail('Expected an ambiguity error.');
        } catch (CommandNotFoundException $error) {
            self::assertStringContainsString('is ambiguous', $error->getMessage());
            self::assertSame(['api:key:create', 'api:key:list'], $error->alternatives());
        }
    }
}
