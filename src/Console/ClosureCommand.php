<?php
declare(strict_types=1);

namespace FloCMS\CLI\Console;

use Closure;
use FloCMS\CLI\Console\Input\Definition;
use FloCMS\CLI\Console\Input\Input;
use FloCMS\CLI\Console\Output\Output;

/**
 * A command defined by closures, for small commands and tests:
 *
 *     $app->add(new ClosureCommand('hello', 'Say hello',
 *         fn (Input $in, Output $out): int => $out->writeln('Hello') ?? 0));
 */
final class ClosureCommand extends Command
{
    /** @var Closure(Input, Output, ClosureCommand): (int|null) */
    private Closure $handler;

    /** @var null|Closure(Definition): void */
    private ?Closure $configurator;

    /**
     * @param callable(Input, Output, ClosureCommand): (int|null) $handler
     * @param null|callable(Definition): void $configure
     * @param list<string> $aliases
     */
    public function __construct(
        string $name,
        string $description,
        callable $handler,
        ?callable $configure = null,
        array $aliases = [],
        bool $hidden = false
    ) {
        $this->name = $name;
        $this->description = $description;
        $this->handler = Closure::fromCallable($handler);
        $this->configurator = $configure !== null ? Closure::fromCallable($configure) : null;
        $this->aliases = $aliases;
        $this->hidden = $hidden;
    }

    protected function configure(Definition $definition): void
    {
        if ($this->configurator !== null) {
            ($this->configurator)($definition);
        }
    }

    protected function handle(): int
    {
        return (int) (($this->handler)($this->input, $this->output, $this) ?? self::SUCCESS);
    }
}
