<?php
declare(strict_types=1);

namespace FloCMS\CLI\Console;

use FloCMS\CLI\Console\Input\Argument;
use FloCMS\CLI\Console\Input\Option;
use FloCMS\CLI\Console\Output\Formatter;
use FloCMS\CLI\Console\Output\Output;

/**
 * Renders `flo list` and `flo help <command>` as text or JSON.
 */
final class Descriptor
{
    public function __construct(private readonly Application $application)
    {
    }

    /**
     * Commands grouped by namespace ("make", "db", ...); commands without a
     * namespace come first.
     *
     * @return array<string, array<string, Command>>
     */
    public function groups(?string $namespace = null): array
    {
        $groups = [];
        foreach ($this->application->all() as $name => $command) {
            if ($command->isHidden()) {
                continue;
            }
            $group = str_contains($name, ':') ? substr($name, 0, (int) strpos($name, ':')) : '';
            if ($namespace !== null && $group !== $namespace) {
                continue;
            }
            $groups[$group][$name] = $command;
        }
        uksort($groups, static fn (string $a, string $b): int => [$a !== '', $a] <=> [$b !== '', $b]);

        return $groups;
    }

    /** @param list<string> $header lines shown above the list (versions, environment) */
    public function describeList(Output $output, array $header = [], ?string $namespace = null): void
    {
        foreach ($header as $line) {
            $output->writeln($line);
        }
        if ($header !== []) {
            $output->writeln();
        }
        $output->writeln('<comment>Usage:</comment>');
        $output->writeln('  php flo <command> [options] [arguments]');
        $output->writeln();
        $output->writeln('<comment>Options:</comment>');
        $this->describeOptions($output, Application::globalDefinition()->options());
        $output->writeln();

        $groups = $this->groups($namespace);
        $width = 0;
        foreach ($groups as $commands) {
            foreach (array_keys($commands) as $name) {
                $width = max($width, Formatter::width($name));
            }
        }

        $output->writeln($namespace === null ? '<comment>Available commands:</comment>' : '<comment>Available commands for the "' . $namespace . '" namespace:</comment>');
        foreach ($groups as $group => $commands) {
            if ($group !== '' && $namespace === null) {
                $output->writeln(' <comment>' . $group . '</comment>');
            }
            foreach ($commands as $name => $command) {
                $aliases = $command->getAliases() === [] ? '' : ' <muted>[' . implode('|', $command->getAliases()) . ']</muted>';
                $output->writeln('  <info>' . $name . '</info>' . str_repeat(' ', $width - Formatter::width($name) + 2) . Formatter::escape($command->getDescription()) . $aliases);
            }
        }
    }

    public function describeCommand(Output $output, Command $command): void
    {
        $definition = $command->getDefinition();
        if ($command->getDescription() !== '') {
            $output->writeln('<comment>Description:</comment>');
            $output->writeln('  ' . Formatter::escape($command->getDescription()));
            $output->writeln();
        }
        $output->writeln('<comment>Usage:</comment>');
        $output->writeln('  ' . Formatter::escape($this->application->usage($command)));
        foreach ($command->getAliases() as $alias) {
            $output->writeln('  ' . Formatter::escape($alias . ' ' . $definition->merge(Application::globalDefinition())->synopsis()));
        }

        if ($definition->arguments() !== []) {
            $output->writeln();
            $output->writeln('<comment>Arguments:</comment>');
            $width = max(array_map(static fn (string $name): int => strlen($name), array_keys($definition->arguments())));
            foreach ($definition->arguments() as $name => $argument) {
                $default = self::defaultText($argument->isRequired() ? null : $argument->default);
                $output->writeln('  <info>' . $name . '</info>' . str_repeat(' ', $width - strlen($name) + 2) . Formatter::escape($argument->description) . $default);
            }
        }

        $output->writeln();
        $output->writeln('<comment>Options:</comment>');
        $this->describeOptions($output, $definition->merge(Application::globalDefinition())->options());

        if ($command->getHelp() !== '') {
            $output->writeln();
            $output->writeln('<comment>Help:</comment>');
            foreach (explode("\n", trim($command->getHelp())) as $line) {
                $output->writeln('  ' . $line);
            }
        }
        if ($command->getExamples() !== []) {
            $output->writeln();
            $output->writeln('<comment>Examples:</comment>');
            foreach ($command->getExamples() as $example) {
                $output->writeln('  ' . Formatter::escape($example));
            }
        }
    }

    /** @param array<string, Option> $options */
    private function describeOptions(Output $output, array $options): void
    {
        $width = 0;
        foreach ($options as $option) {
            $width = max($width, strlen($option->synopsis()));
        }
        foreach ($options as $option) {
            $synopsis = $option->synopsis();
            $default = $option->acceptsValue() ? self::defaultText($option->default) : '';
            $repeat = $option->isArray() ? ' <comment>(multiple values allowed)</comment>' : '';
            $output->writeln('  <info>' . Formatter::escape($synopsis) . '</info>' . str_repeat(' ', $width - strlen($synopsis) + 2) . Formatter::escape($option->description) . $default . $repeat);
        }
    }

    private static function defaultText(mixed $default): string
    {
        if ($default === null || $default === [] || $default === false) {
            return '';
        }

        return ' <comment>[default: ' . Formatter::escape(is_array($default) ? implode(',', $default) : var_export($default, true)) . ']</comment>';
    }

    /** @return array<string, mixed> */
    public function listAsArray(?string $namespace = null): array
    {
        $commands = [];
        foreach ($this->groups($namespace) as $group) {
            foreach ($group as $command) {
                $commands[] = $this->commandAsArray($command);
            }
        }

        return [
            'application' => ['name' => $this->application->getName(), 'version' => $this->application->getVersion()],
            'namespaces' => array_map(
                static fn (string $id, array $commands): array => ['id' => $id === '' ? '_global' : $id, 'commands' => array_keys($commands)],
                array_keys($this->groups($namespace)),
                $this->groups($namespace)
            ),
            'commands' => $commands,
        ];
    }

    /** @return array<string, mixed> */
    public function commandAsArray(Command $command): array
    {
        $definition = $command->getDefinition();

        return [
            'name' => $command->getName(),
            'description' => $command->getDescription(),
            'aliases' => $command->getAliases(),
            'usage' => $this->application->usage($command),
            'help' => $command->getHelp(),
            'examples' => $command->getExamples(),
            'hidden' => $command->isHidden(),
            'arguments' => array_values(array_map(static fn (Argument $a): array => [
                'name' => $a->name,
                'required' => $a->isRequired(),
                'array' => $a->isArray(),
                'description' => $a->description,
                'default' => $a->default,
            ], $definition->arguments())),
            'options' => array_values(array_map(static fn (Option $o): array => [
                'name' => '--' . $o->name,
                'shortcut' => $o->shortcut !== null ? '-' . $o->shortcut : null,
                'accept_value' => $o->acceptsValue(),
                'value_required' => $o->requiresValue(),
                'multiple' => $o->isArray(),
                'description' => $o->description,
                'default' => $o->isFlag() ? false : $o->default,
            ], $definition->merge(Application::globalDefinition())->options())),
        ];
    }
}
