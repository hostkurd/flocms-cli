<?php
declare(strict_types=1);

namespace FloCMS\CLI\Console;

use FloCMS\CLI\Console\Input\ArgvParser;
use FloCMS\CLI\Console\Input\InputException;
use FloCMS\CLI\Console\Output\Output;
use FloCMS\CLI\Project;
use Throwable;

/**
 * The command registry and runner behind `php flo`.
 *
 * Commands come from flocms-cli itself, from installed packages
 * (composer.json "extra": {"flocms": {"commands": [...]}}) and from the
 * application's commands/ folder; see Kernel.
 */
class Application
{
    /** @var array<string, Command> */
    private array $commands = [];

    /** @var array<string, string> alias => command name */
    private array $aliases = [];

    private Output $output;

    /** @var resource|null */
    private $stdin;

    /**
     * @param resource|null $stdin
     */
    public function __construct(
        private readonly string $name = 'FloCMS CLI',
        private readonly string $version = '',
        private readonly ?Project $project = null,
        ?Output $output = null,
        $stdin = null
    ) {
        $this->output = $output ?? new Output();
        $this->stdin = $stdin;
    }

    /** Register a command. A later command with the same name replaces the earlier one. */
    public function add(Command $command): self
    {
        $name = $command->getName();
        if (isset($this->commands[$name])) {
            $this->commands[$name]->setApplication(null);
            $this->aliases = array_filter($this->aliases, static fn (string $target): bool => $target !== $name);
        }
        $command->setApplication($this);
        $this->commands[$name] = $command;
        foreach ($command->getAliases() as $alias) {
            $this->aliases[$alias] = $name;
        }

        return $this;
    }

    public function has(string $name): bool
    {
        return isset($this->commands[$name]) || isset($this->aliases[$name]);
    }

    /** @return array<string, Command> sorted by name */
    public function all(): array
    {
        $commands = $this->commands;
        ksort($commands);

        return $commands;
    }

    /** @return array<string, string> alias => command name */
    public function aliases(): array
    {
        return $this->aliases;
    }

    /** Find a command by name or alias. */
    public function find(string $name): Command
    {
        $command = $this->commands[$name] ?? $this->commands[$this->aliases[$name] ?? ''] ?? null;
        if ($command === null) {
            throw new CommandNotFoundException('Command "' . $name . '" is not defined.');
        }

        return $command;
    }

    /**
     * Run a command line.
     *
     * @param list<string> $arguments the command line without the script name
     * @return int exit code
     */
    public function run(array $arguments): int
    {
        $name = $arguments === [] ? 'list' : array_shift($arguments);

        try {
            $command = $this->find($name);
        } catch (CommandNotFoundException $error) {
            $this->output->error($error->getMessage());

            return Command::FAILURE;
        }

        return $this->runCommand($command, $arguments, $this->output);
    }

    /** @param list<string> $tokens arguments and options after the command name */
    public function runCommand(Command $command, array $tokens, ?Output $output = null): int
    {
        $output ??= $this->output;
        try {
            $input = ArgvParser::parse($tokens, $command->getDefinition(), $this->stdin);

            return $command->run($input, $output);
        } catch (InputException $error) {
            $output->error($error->getMessage());
            $output->error('Usage: ' . trim($command->getName() . ' ' . $command->getDefinition()->synopsis()));

            return Command::FAILURE;
        } catch (Throwable $error) {
            $output->error(get_class($error) . ': ' . $error->getMessage());

            return Command::FAILURE;
        }
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function project(): ?Project
    {
        return $this->project;
    }

    public function output(): Output
    {
        return $this->output;
    }
}
