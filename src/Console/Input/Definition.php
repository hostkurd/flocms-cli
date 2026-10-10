<?php
declare(strict_types=1);

namespace FloCMS\CLI\Console\Input;

use InvalidArgumentException;

/**
 * The arguments and options a command accepts.
 *
 *     $definition
 *         ->argument('name', Argument::REQUIRED, 'The controller name')
 *         ->option('force', 'f', Option::VALUE_NONE, 'Overwrite existing files')
 *         ->option('scopes', null, Option::VALUE_REQUIRED, 'Comma separated scopes');
 */
final class Definition
{
    /** @var array<string, Argument> */
    private array $arguments = [];

    /** @var array<string, Option> */
    private array $options = [];

    /** @var array<string, string> shortcut => option name */
    private array $shortcuts = [];

    public function argument(string $name, int $mode = Argument::OPTIONAL, string $description = '', mixed $default = null): self
    {
        return $this->addArgument(new Argument($name, $mode, $description, $default));
    }

    public function option(string $name, ?string $shortcut = null, int $mode = Option::VALUE_NONE, string $description = '', mixed $default = null): self
    {
        return $this->addOption(new Option($name, $shortcut, $mode, $description, $default));
    }

    public function addArgument(Argument $argument): self
    {
        if (isset($this->arguments[$argument->name])) {
            throw new InvalidArgumentException('Argument "' . $argument->name . '" is already defined.');
        }
        $last = $this->arguments === [] ? null : end($this->arguments);
        if ($last !== null && $last->isArray()) {
            throw new InvalidArgumentException('No argument can follow the array argument "' . $last->name . '".');
        }
        if ($last !== null && !$last->isRequired() && $argument->isRequired()) {
            throw new InvalidArgumentException('Required argument "' . $argument->name . '" cannot follow an optional one.');
        }
        $this->arguments[$argument->name] = $argument;

        return $this;
    }

    public function addOption(Option $option): self
    {
        if (isset($this->options[$option->name])) {
            throw new InvalidArgumentException('Option "--' . $option->name . '" is already defined.');
        }
        if ($option->shortcut !== null) {
            if (isset($this->shortcuts[$option->shortcut])) {
                throw new InvalidArgumentException('Shortcut "-' . $option->shortcut . '" is already used by --' . $this->shortcuts[$option->shortcut] . '.');
            }
            $this->shortcuts[$option->shortcut] = $option->name;
        }
        $this->options[$option->name] = $option;

        return $this;
    }

    /** @return array<string, Argument> */
    public function arguments(): array
    {
        return $this->arguments;
    }

    /** @return array<string, Option> */
    public function options(): array
    {
        return $this->options;
    }

    public function hasArgument(string $name): bool
    {
        return isset($this->arguments[$name]);
    }

    public function hasOption(string $name): bool
    {
        return isset($this->options[$name]);
    }

    public function getOption(string $name): ?Option
    {
        return $this->options[$name] ?? null;
    }

    public function optionForShortcut(string $shortcut): ?Option
    {
        $name = $this->shortcuts[$shortcut] ?? null;

        return $name === null ? null : $this->options[$name];
    }

    /** A copy with $other's arguments and options added (used for the global options). */
    public function merge(Definition $other): self
    {
        $merged = clone $this;
        foreach ($other->options as $option) {
            if (!isset($merged->options[$option->name])) {
                $shortcut = $option->shortcut !== null && isset($merged->shortcuts[$option->shortcut]) ? null : $option->shortcut;
                $merged->addOption(new Option($option->name, $shortcut, $option->mode, $option->description, $option->default));
            }
        }
        foreach ($other->arguments as $argument) {
            if (!isset($merged->arguments[$argument->name])) {
                $merged->addArgument($argument);
            }
        }

        return $merged;
    }

    /** e.g. "[options] [--] <name> [<view>]" */
    public function synopsis(): string
    {
        $parts = [];
        if ($this->options !== []) {
            $parts[] = '[options]';
        }
        if ($this->arguments !== []) {
            $parts[] = '[--]';
            foreach ($this->arguments as $argument) {
                $parts[] = $argument->synopsis();
            }
        }

        return implode(' ', $parts);
    }
}
