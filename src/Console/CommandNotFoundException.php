<?php
declare(strict_types=1);

namespace FloCMS\CLI\Console;

use RuntimeException;

final class CommandNotFoundException extends RuntimeException
{
    /** @param list<string> $alternatives */
    public function __construct(string $message, private readonly array $alternatives = [])
    {
        parent::__construct($message);
    }

    /** @return list<string> */
    public function alternatives(): array
    {
        return $this->alternatives;
    }
}
