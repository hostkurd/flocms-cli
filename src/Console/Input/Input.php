<?php
declare(strict_types=1);

namespace FloCMS\CLI\Console\Input;

/**
 * Parsed command line: argument and option values, plus the stream answers
 * to prompts are read from.
 */
final class Input
{
    /** @var resource */
    private $stream;

    /**
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $options
     * @param resource|null $stream
     */
    public function __construct(
        private array $arguments = [],
        private array $options = [],
        private bool $interactive = true,
        $stream = null
    ) {
        $this->stream = $stream ?? (defined('STDIN') ? STDIN : fopen('php://stdin', 'r'));
    }

    public function argument(string $name): mixed
    {
        return $this->arguments[$name] ?? null;
    }

    public function option(string $name): mixed
    {
        return $this->options[$name] ?? null;
    }

    public function hasArgument(string $name): bool
    {
        return array_key_exists($name, $this->arguments);
    }

    public function hasOption(string $name): bool
    {
        return array_key_exists($name, $this->options);
    }

    /** @return array<string, mixed> */
    public function arguments(): array
    {
        return $this->arguments;
    }

    /** @return array<string, mixed> */
    public function options(): array
    {
        return $this->options;
    }

    public function setArgument(string $name, mixed $value): void
    {
        $this->arguments[$name] = $value;
    }

    public function setOption(string $name, mixed $value): void
    {
        $this->options[$name] = $value;
    }

    public function isInteractive(): bool
    {
        return $this->interactive;
    }

    public function setInteractive(bool $interactive): void
    {
        $this->interactive = $interactive;
    }

    /** @return resource */
    public function stream()
    {
        return $this->stream;
    }

    /** @param resource $stream */
    public function setStream($stream): void
    {
        $this->stream = $stream;
    }
}
