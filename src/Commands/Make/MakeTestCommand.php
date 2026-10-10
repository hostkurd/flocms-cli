<?php
declare(strict_types=1);

namespace FloCMS\CLI\Commands\Make;

use FloCMS\CLI\Console\Input\Argument;
use FloCMS\CLI\Console\Input\Definition;
use FloCMS\CLI\Console\Input\Option;
use FloCMS\CLI\Support\Name;

final class MakeTestCommand extends GeneratorCommand
{
    protected string $name = 'make:test';

    protected string $description = 'Create a PHPUnit test in tests/Unit (or tests/Feature)';

    protected array $examples = [
        'php flo make:test Slug',
        'php flo make:test Checkout --feature',
    ];

    protected function arguments(Definition $definition): void
    {
        $definition
            ->argument('name', Argument::REQUIRED, 'The test name')
            ->option('feature', null, Option::VALUE_NONE, 'A feature test in tests/Feature');
    }

    protected function generate(): int
    {
        $class = Name::studly((string) $this->argument('name'), 'test name', 'Test') . 'Test';
        $suite = $this->option('feature') ? 'Feature' : 'Unit';
        $this->create('tests/' . $suite . '/' . $class . '.php', $this->stub($this->option('feature') ? 'test.feature' : 'test', [
            'class' => $class,
            'namespace' => $this->testNamespace() . $suite,
        ]));

        return self::SUCCESS;
    }

    /** The namespace mapped to tests/ in the project's autoload-dev (FloCMS: HostKurd\Flocms\Tests\). */
    private function testNamespace(): string
    {
        $composer = json_decode((string) @file_get_contents($this->project()->path('composer.json')), true);
        foreach (['autoload-dev', 'autoload'] as $section) {
            foreach ((array) ($composer[$section]['psr-4'] ?? []) as $namespace => $paths) {
                foreach ((array) $paths as $path) {
                    if (trim(str_replace('\\', '/', (string) $path), '/') === 'tests') {
                        return (string) $namespace;
                    }
                }
            }
        }

        return 'Tests\\';
    }
}
