<?php
declare(strict_types=1);

namespace FloCMS\CLI\Console;

use RuntimeException;

/**
 * An expected failure: the application prints the message (no class name or
 * stack trace) and exits with the given code, 1 by default.
 *
 *     throw new CommandFailedException('No .env file. Copy .env.example to .env first.');
 */
class CommandFailedException extends RuntimeException
{
    public function __construct(string $message, int $exitCode = Command::FAILURE, ?\Throwable $previous = null)
    {
        parent::__construct($message, $exitCode, $previous);
    }

    public function exitCode(): int
    {
        return $this->getCode() > 0 ? (int) $this->getCode() : Command::FAILURE;
    }
}
