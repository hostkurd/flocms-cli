<?php
declare(strict_types=1);

namespace FloCMS\CLI\Commands\Make;

use FloCMS\CLI\Console\Input\Argument;
use FloCMS\CLI\Console\Input\Definition;
use FloCMS\CLI\Support\Name;

/**
 * A generator that renders one stub into one class file.
 */
abstract class SimpleClassGenerator extends GeneratorCommand
{
    /** The stub name. */
    protected string $stubName = '';

    /** Class name suffix added (and stripped from the input), e.g. "Resource". */
    protected string $suffix = '';

    /** Target directory, e.g. "api/Resources". */
    protected string $directory = '';

    protected function arguments(Definition $definition): void
    {
        $definition->argument('name', Argument::REQUIRED, 'The class name');
    }

    protected function generate(): int
    {
        $name = Name::studly((string) $this->argument('name'), 'class name', $this->suffix);
        $class = $name . $this->suffix;
        $this->create($this->directory . '/' . $class . '.php', $this->stub($this->stubName, $this->replacements($name, $class)));

        return self::SUCCESS;
    }

    /** @return array<string, string> */
    protected function replacements(string $name, string $class): array
    {
        return [
            'class' => $class,
            'singular' => strtolower(self::humanize($name)),
        ];
    }
}
