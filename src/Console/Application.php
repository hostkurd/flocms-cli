<?php
declare(strict_types=1);

namespace FloCMS\CLI\Console;

use FloCMS\CLI\Console\Input\ArgvParser;
use FloCMS\CLI\Console\Input\Definition;
use FloCMS\CLI\Console\Input\Input;
use FloCMS\CLI\Console\Input\InputException;
use FloCMS\CLI\Console\Input\Option;
use FloCMS\CLI\Console\Output\Formatter;
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

    /** @var null|\Closure(): list<string> */
    private ?\Closure $headerProvider = null;

    /** @var null|\Closure(): string */
    private ?\Closure $versionProvider = null;

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

    /**
     * Find a command by name, alias or unambiguous abbreviation
     * ("mig" for "migrate", "m:st" for "migrate:status").
     *
     * @throws CommandNotFoundException with "Did you mean" alternatives
     */
    public function find(string $name): Command
    {
        $command = $this->commands[$name] ?? $this->commands[$this->aliases[$name] ?? ''] ?? null;
        if ($command !== null) {
            return $command;
        }

        $matches = $this->abbreviationMatches($name);
        if (count($matches) === 1) {
            return $this->commands[$matches[0]];
        }
        if (count($matches) > 1) {
            throw new CommandNotFoundException(
                'Command "' . $name . '" is ambiguous. Did you mean one of these?' . "\n  " . implode("\n  ", $matches),
                $matches
            );
        }

        $alternatives = $this->alternatives($name);
        $message = 'Command "' . $name . '" is not defined.';
        if ($alternatives !== []) {
            $message .= count($alternatives) === 1
                ? "\nDid you mean \"" . $alternatives[0] . '"?'
                : "\nDid you mean one of these?\n  " . implode("\n  ", $alternatives);
        } else {
            $message .= "\nRun \"php flo list\" to see all commands.";
        }

        throw new CommandNotFoundException($message, $alternatives);
    }

    /**
     * Commands whose name segments all start with the given segments. Exact
     * segment-count matches win, so "mig" finds "migrate" even when
     * "migrate:status" exists.
     *
     * @return list<string>
     */
    private function abbreviationMatches(string $name): array
    {
        $parts = explode(':', $name);
        if (in_array('', $parts, true)) {
            return [];
        }
        $same = [];
        $longer = [];
        foreach ($this->visibleNames() as $candidate) {
            $candidateParts = explode(':', $candidate);
            if (count($candidateParts) < count($parts)) {
                continue;
            }
            foreach ($parts as $index => $part) {
                if (!str_starts_with($candidateParts[$index], $part)) {
                    continue 2;
                }
            }
            if (count($candidateParts) === count($parts)) {
                $same[] = $candidate;
            } else {
                $longer[] = $candidate;
            }
        }
        $same = array_values(array_unique(array_map(fn (string $n): string => $this->aliases[$n] ?? $n, $same)));
        if ($same !== []) {
            return $same;
        }

        return array_values(array_unique(array_map(fn (string $n): string => $this->aliases[$n] ?? $n, $longer)));
    }

    /**
     * Similar command names: small edit distance, or sharing a meaningful
     * word ("api:create:schema" suggests "api:install-schema").
     *
     * @return list<string>
     */
    public function alternatives(string $name, int $limit = 5): array
    {
        $name = strtolower($name);
        $words = array_filter(preg_split('/[:\-_]/', $name) ?: [], static fn (string $w): bool => strlen($w) >= 3);
        $scores = [];
        foreach ($this->visibleNames() as $candidate) {
            $distance = levenshtein($name, strtolower($candidate));
            $threshold = max(2, intdiv(strlen($name), 3));
            $candidateWords = preg_split('/[:\-_]/', strtolower($candidate)) ?: [];
            $shared = 0;
            foreach ($words as $word) {
                foreach ($candidateWords as $candidateWord) {
                    if ($candidateWord === $word || (strlen($word) >= 4 && str_starts_with($candidateWord, $word))) {
                        $shared++;
                        break;
                    }
                }
            }
            $namespaceWord = $words === [] ? false : in_array(reset($words), $candidateWords, true);
            if ($distance <= $threshold || str_contains(strtolower($candidate), $name)) {
                $scores[$candidate] = $distance;
            } elseif ($shared > 0 && ($shared >= 2 || !$namespaceWord || count($words) === 1)) {
                $scores[$candidate] = $distance + 100 - $shared * 50;
            }
        }
        asort($scores);
        $names = array_map(fn (string $n): string => $this->aliases[$n] ?? $n, array_keys($scores));

        return array_slice(array_values(array_unique($names)), 0, $limit);
    }

    /** @return list<string> names and aliases of commands that are not hidden */
    private function visibleNames(): array
    {
        $names = [];
        foreach ($this->commands as $name => $command) {
            if (!$command->isHidden()) {
                $names[] = $name;
            }
        }
        foreach ($this->aliases as $alias => $target) {
            if (isset($this->commands[$target]) && !$this->commands[$target]->isHidden()) {
                $names[] = $alias;
            }
        }

        return $names;
    }

    /**
     * Lines shown at the top of `flo list` (name, versions, environment).
     *
     * @return list<string>
     */
    public function header(): array
    {
        if ($this->headerProvider !== null) {
            return ($this->headerProvider)();
        }
        $env = $_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? getenv('APP_ENV');

        return [
            '<info>' . $this->name . '</info>' . ($this->version !== '' ? ' <comment>' . $this->version . '</comment>' : ''),
            'Environment: <comment>' . (is_string($env) && $env !== '' ? $env : 'unknown') . '</comment>',
        ];
    }

    /** @param callable(): list<string> $provider */
    public function setHeaderProvider(callable $provider): void
    {
        $this->headerProvider = \Closure::fromCallable($provider);
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
                ErrorRenderer::render($error, $this->output, true);
                $this->output->error('Usage: flo <command> [options] [arguments]');

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
            ErrorRenderer::render($error, $this->output, true);

            return Command::FAILURE;
        }

        return $this->runCommand($command, $tokens, $this->output);
    }

    /** @param list<string> $tokens arguments and options after the command name */
    public function runCommand(Command $command, array $tokens, ?Output $output = null): int
    {
        $output ??= $this->output;
        if (self::asksForHelp($tokens) && !$command->getDefinition()->hasOption('help')) {
            (new Descriptor($this))->describeCommand($output, $command);

            return Command::SUCCESS;
        }
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

            return self::exitCode($command->run($input, $output));
        } catch (InputException $error) {
            ErrorRenderer::render($error, $output, true);
            $output->error('Usage: ' . Formatter::escape($this->usage($command)));
            $output->error('Run "flo ' . $command->getName() . ' --help" for the arguments and options.');

            return Command::INVALID;
        } catch (CommandFailedException $error) {
            ErrorRenderer::render($error, $output, true);

            return $error->exitCode();
        } catch (Throwable $error) {
            ErrorRenderer::render($error, $output);

            return Command::FAILURE;
        }
    }

    /**
     * --help or -h before "--" shows the help, even when required arguments
     * are missing.
     *
     * @param list<string> $tokens
     */
    private static function asksForHelp(array $tokens): bool
    {
        foreach ($tokens as $token) {
            if ($token === '--') {
                return false;
            }
            if ($token === '--help' || $token === '-h') {
                return true;
            }
        }

        return false;
    }

    /** Exit codes are 0-255; anything else becomes 1. */
    private static function exitCode(int $code): int
    {
        return $code >= 0 && $code <= 255 ? $code : Command::FAILURE;
    }

    /** "make:controller [options] [--] <name>" */
    public function usage(Command $command): string
    {
        return trim($command->getName() . ' ' . $command->getDefinition()->merge(self::globalDefinition())->synopsis());
    }

    public function longVersion(): string
    {
        return $this->versionProvider !== null ? ($this->versionProvider)() : trim($this->name . ' ' . $this->version);
    }

    /** @param callable(): string $provider the -V / --version line */
    public function setVersionProvider(callable $provider): void
    {
        $this->versionProvider = \Closure::fromCallable($provider);
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
        if ($input->option('no-ansi')) {
            $output->setDecorated(false);
        } elseif ($input->option('ansi')) {
            $output->setDecorated(true);
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
        (new Descriptor($this))->describeCommand($output, $command);
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
