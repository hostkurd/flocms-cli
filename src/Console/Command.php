<?php
declare(strict_types=1);

namespace FloCMS\CLI\Console;

use FloCMS\CLI\Console\Input\Definition;
use FloCMS\CLI\Console\Input\Input;
use FloCMS\CLI\Console\Input\InputException;
use FloCMS\CLI\Console\Output\Formatter;
use FloCMS\CLI\Console\Output\Output;
use FloCMS\CLI\Console\Output\ProgressBar;
use FloCMS\CLI\Console\Output\Spinner;
use FloCMS\CLI\Console\Output\Table;
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

    /** Stop with an error message (no stack trace) and exit code 1. */
    protected function fail(string $message, int $exitCode = self::FAILURE): never
    {
        throw new CommandFailedException($message, $exitCode);
    }

    /** A line on STDOUT; style tags allowed (<info>, <comment>, <b>, ...). */
    protected function line(string $text = '', int $verbosity = Output::NORMAL): void
    {
        $this->output->writeln($text, $verbosity);
    }

    protected function newLine(int $count = 1): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->output->writeln();
        }
    }

    protected function info(string $text): void
    {
        $this->output->writeln('<info>' . $text . '</info>');
    }

    protected function comment(string $text): void
    {
        $this->output->writeln('<comment>' . $text . '</comment>');
    }

    /** "[OK] message" in green. */
    protected function success(string $text): void
    {
        $this->output->writeln('<success>[OK]</success> ' . $text);
    }

    /** "[WARNING] message" on STDERR. */
    protected function warn(string $text): void
    {
        $this->output->diagnostic('<warning> WARNING </warning> ' . $text);
    }

    /** "! message" note in yellow. */
    protected function note(string $text): void
    {
        $this->output->writeln('<comment>! ' . $text . '</comment>');
    }

    /** An error line on STDERR (shown even with --quiet). */
    protected function error(string $text): void
    {
        $this->output->error('<error> ERROR </error> ' . $text);
    }

    protected function title(string $text): void
    {
        $this->output->writeln();
        $this->output->writeln('<b>' . $text . '</b>');
        $this->output->writeln('<b>' . str_repeat('=', Formatter::width($text)) . '</b>');
        $this->output->writeln();
    }

    protected function section(string $text): void
    {
        $this->output->writeln();
        $this->output->writeln('<comment>' . $text . '</comment>');
        $this->output->writeln('<comment>' . str_repeat('-', Formatter::width($text)) . '</comment>');
    }

    /**
     * @param list<string> $headers
     * @param list<array<int|string, mixed>> $rows lists, or rows keyed by header
     */
    protected function table(array $headers, array $rows): void
    {
        foreach (Table::render($headers, $rows) as $line) {
            $this->output->writeln($line);
        }
    }

    /**
     * Aligned "label  value" lines.
     *
     * @param array<string, mixed> $items
     */
    protected function definitionList(array $items): void
    {
        $width = 0;
        foreach (array_keys($items) as $label) {
            $width = max($width, Formatter::width((string) $label));
        }
        foreach ($items as $label => $value) {
            $value = match (true) {
                $value === null => '<muted>-</muted>',
                $value === true => 'yes',
                $value === false => 'no',
                is_array($value) => implode(', ', array_map('strval', $value)),
                default => (string) $value,
            };
            $this->output->writeln('  <info>' . $label . '</info>' . str_repeat(' ', $width - Formatter::width((string) $label) + 2) . $value);
        }
    }

    protected function progress(int $max = 0): ProgressBar
    {
        return (new ProgressBar($this->output, $max))->start();
    }

    /**
     * @template T
     * @param callable(Spinner): T $work
     * @return T
     */
    protected function spin(string $message, callable $work): mixed
    {
        return (new Spinner($this->output, $message))->run($work);
    }

    protected function prompt(): Prompt
    {
        return new Prompt($this->input, $this->output);
    }

    /** @param null|callable(string): (string|null) $validate */
    protected function ask(string $question, ?string $default = null, ?callable $validate = null): string
    {
        return $this->prompt()->ask($question, $default, $validate);
    }

    /** Hidden input (passwords). */
    protected function secret(string $question, ?callable $validate = null): string
    {
        return $this->prompt()->secret($question, $validate);
    }

    protected function confirm(string $question, bool $default = false): bool
    {
        return $this->prompt()->confirm($question, $default);
    }

    /** @param array<int|string, string> $choices */
    protected function choice(string $question, array $choices, int|string|null $default = null): int|string
    {
        return $this->prompt()->choice($question, $choices, $default);
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
