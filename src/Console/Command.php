<?php
declare(strict_types=1);

namespace FloCMS\CLI\Console;

use FloCMS\CLI\Console\Input\Definition;
use FloCMS\CLI\Console\Input\Input;
use FloCMS\CLI\Console\Input\InputException;
use FloCMS\CLI\Console\Output\Output;
use FloCMS\CLI\Project;
use LogicException;

/**
 * Base class for every flo command: built-in, from a package or from the
 * application's commands/ folder.
 *
 *     final class GreetCommand extends Command
 *     {
 *         protected string $name = 'greet';
 *         protected string $description = 'Say hello';
 *
 *         protected function configure(Definition $definition): void
 *         {
 *             $definition->argument('who', Argument::REQUIRED, 'Who to greet');
 *         }
 *
 *         protected function handle(): int
 *         {
 *             $this->line('Hello ' . $this->argument('who'));
 *
 *             return self::SUCCESS;
 *         }
 *     }
 */
abstract class Command
{
    public const SUCCESS = 0;
    public const FAILURE = 1;
    /** Invalid usage: bad arguments or options. */
    public const INVALID = 2;

    protected string $name = '';

    protected string $description = '';

    /** @var list<string> */
    protected array $aliases = [];

    protected bool $hidden = false;

    /** Longer help text shown by `flo help <command>`. */
    protected string $help = '';

    /** @var list<string> example command lines shown in the help */
    protected array $examples = [];

    protected Input $input;

    protected Output $output;

    private ?Definition $definition = null;

    private ?Application $application = null;

    public function getName(): string
    {
        if ($this->name === '') {
            throw new LogicException(static::class . ' has no command name.');
        }

        return $this->name;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    /** @return list<string> */
    public function getAliases(): array
    {
        return $this->aliases;
    }

    public function isHidden(): bool
    {
        return $this->hidden;
    }

    public function getHelp(): string
    {
        return $this->help;
    }

    /** @return list<string> */
    public function getExamples(): array
    {
        return $this->examples;
    }

    public function getDefinition(): Definition
    {
        if ($this->definition === null) {
            $this->definition = new Definition();
            $this->configure($this->definition);
        }

        return $this->definition;
    }

    public function setApplication(?Application $application): void
    {
        $this->application = $application;
    }

    public function getApplication(): ?Application
    {
        return $this->application;
    }

    /** Run with parsed input. Called by the Application. */
    public function run(Input $input, Output $output): int
    {
        $this->input = $input;
        $this->output = $output;

        return $this->handle();
    }

    /** Declare arguments and options. */
    protected function configure(Definition $definition): void
    {
    }

    /** @return int exit code: self::SUCCESS, self::FAILURE or self::INVALID */
    abstract protected function handle(): int;

    protected function argument(string $name): mixed
    {
        if (!$this->input->hasArgument($name)) {
            throw new LogicException('Argument "' . $name . '" is not defined by ' . $this->getName() . '.');
        }

        return $this->input->argument($name);
    }

    protected function option(string $name): mixed
    {
        if (!$this->input->hasOption($name)) {
            throw new LogicException('Option "--' . $name . '" is not defined by ' . $this->getName() . '.');
        }

        return $this->input->option($name);
    }

    protected function project(): Project
    {
        return $this->application?->project()
            ?? throw new LogicException('The ' . $this->getName() . ' command needs a FloCMS project.');
    }

    /** Throw to stop with "invalid usage" (exit code 2). */
    protected function invalid(string $message): never
    {
        throw new InputException($message);
    }

    protected function line(string $text = ''): void
    {
        $this->output->writeln($text);
    }

    protected function error(string $text): void
    {
        $this->output->error($text);
    }

    /**
     * Run another command, e.g. $this->call('migrate', ['--force']).
     *
     * @param list<string> $arguments
     */
    protected function call(string $command, array $arguments = []): int
    {
        $application = $this->application ?? throw new LogicException('Cannot call commands without an application.');

        return $application->runCommand($application->find($command), $arguments, $this->output);
    }
}
