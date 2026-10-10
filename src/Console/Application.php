<?php
declare(strict_types=1);

namespace FloCMS\CLI\Console;

use FloCMS\CLI\Console\Input\ArgvParser;
use FloCMS\CLI\Console\Input\Definition;
use FloCMS\CLI\Console\Input\Input;
use FloCMS\CLI\Console\Input\InputException;
use FloCMS\CLI\Console\Input\Option;
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

    private bool $interactive = true;

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
     * Options every command accepts.
     */
    public static function globalDefinition(): Definition
    {
        return (new Definition())
            ->option('help', 'h', Option::VALUE_NONE, 'Show help for the command')
            ->option('quiet', 'q', Option::VALUE_NONE, 'Only print errors')
            ->option('verbose', 'v', Option::VALUE_NONE, 'More output: -v, -vv, or -vvv for debug output and stack traces')
            ->option('version', 'V', Option::VALUE_NONE, 'Show the CLI and framework versions')
            ->option('ansi', null, Option::VALUE_NONE, 'Force colored output')
            ->option('no-ansi', null, Option::VALUE_NONE, 'Disable colored output')
            ->option('no-interaction', 'n', Option::VALUE_NONE, 'Do not ask questions; use the defaults')
            ->option('env', null, Option::VALUE_REQUIRED, 'The environment to run in (sets APP_ENV)');
    }

    /**
     * Run a command line.
     *
     * @param list<string> $arguments the command line without the script name
     * @return int exit code
     */
    public function run(array $arguments): int
    {
        [$name, $tokens] = self::splitCommandName($arguments);

        if ($name === null) {
            // Only global options: php flo, php flo -V, php flo --help
            try {
                $input = ArgvParser::parse($tokens, self::globalDefinition(), $this->stdin);
            } catch (InputException $error) {
                $this->output->error($error->getMessage());

                return Command::INVALID;
            }
            $this->applyGlobalOptions($input, $this->output);
            if ($input->option('version')) {
                $this->output->writeln($this->longVersion());

                return Command::SUCCESS;
            }
            $name = 'list';
            $tokens = [];
        }

        try {
            $command = $this->find($name);
        } catch (CommandNotFoundException $error) {
            $this->output->error($error->getMessage());

            return Command::FAILURE;
        }

        return $this->runCommand($command, $tokens, $this->output);
    }

    /** @param list<string> $tokens arguments and options after the command name */
    public function runCommand(Command $command, array $tokens, ?Output $output = null): int
    {
        $output ??= $this->output;
        try {
            $input = ArgvParser::parse($tokens, $command->getDefinition()->merge(self::globalDefinition()), $this->stdin);
            $input->setInteractive($this->interactive);
            $this->applyGlobalOptions($input, $output);
            if ($input->option('version')) {
                $output->writeln($this->longVersion());

                return Command::SUCCESS;
            }
            if ($input->option('help')) {
                $this->renderHelp($command, $output);

                return Command::SUCCESS;
            }

            return $command->run($input, $output);
        } catch (InputException $error) {
            $output->error($error->getMessage());
            $output->error('Usage: ' . $this->usage($command));

            return Command::FAILURE;
        } catch (Throwable $error) {
            $output->error(get_class($error) . ': ' . $error->getMessage());

            return Command::FAILURE;
        }
    }

    /** "make:controller [options] [--] <name>" */
    public function usage(Command $command): string
    {
        return trim($command->getName() . ' ' . $command->getDefinition()->merge(self::globalDefinition())->synopsis());
    }

    public function longVersion(): string
    {
        return trim($this->name . ' ' . $this->version);
    }

    /**
     * The command name is the first token that is not an option (or the value
     * of --env given as a separate token).
     *
     * @param list<string> $arguments
     * @return array{0: ?string, 1: list<string>}
     */
    private static function splitCommandName(array $arguments): array
    {
        foreach ($arguments as $index => $token) {
            if ($token === '--') {
                break;
            }
            if ($token === '--env') {
                $skip = $index + 1;
                continue;
            }
            if (isset($skip) && $skip === $index) {
                continue;
            }
            if ($token !== '' && $token[0] !== '-') {
                $rest = $arguments;
                unset($rest[$index]);

                return [$token, array_values($rest)];
            }
        }

        return [null, $arguments];
    }

    private function applyGlobalOptions(Input $input, Output $output): void
    {
        if ($input->option('quiet')) {
            $output->setVerbosity(Output::QUIET);
        } elseif ((int) $input->option('verbose') > 0) {
            $output->setVerbosity(match ((int) $input->option('verbose')) {
                1 => Output::VERBOSE,
                2 => Output::VERY_VERBOSE,
                default => Output::DEBUG,
            });
        }
        if ($input->option('no-interaction')) {
            $input->setInteractive(false);
        }
        $env = $input->option('env');
        if (is_string($env) && $env !== '') {
            putenv('APP_ENV=' . $env);
            $_ENV['APP_ENV'] = $env;
            $_SERVER['APP_ENV'] = $env;
        }
    }

    private function renderHelp(Command $command, Output $output): void
    {
        $output->writeln('Usage:');
        $output->writeln('  ' . $this->usage($command));
        if ($command->getDescription() !== '') {
            $output->writeln();
            $output->writeln($command->getDescription());
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

    public function setOutput(Output $output): void
    {
        $this->output = $output;
    }

    /** @param resource|null $stream where prompts read answers from (default STDIN) */
    public function setInputStream($stream): void
    {
        $this->stdin = $stream;
    }

    public function isInteractive(): bool
    {
        return $this->interactive;
    }

    public function setInteractive(bool $interactive): void
    {
        $this->interactive = $interactive;
    }
}
