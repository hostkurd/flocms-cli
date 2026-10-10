<?php
declare(strict_types=1);

namespace FloCMS\CLI\Console\Input;

use InvalidArgumentException;

/**
 * A positional argument: required, optional, or an array that takes the
 * remaining values (must be the last argument).
 */
final class Argument
{
    public const REQUIRED = 1;
    public const OPTIONAL = 2;
    public const IS_ARRAY = 4;

    public function __construct(
        public readonly string $name,
        public readonly int $mode = self::OPTIONAL,
        public readonly string $description = '',
        public readonly mixed $default = null
    ) {
        if (preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $name) !== 1) {
            throw new InvalidArgumentException('Invalid argument name "' . $name . '".');
        }
        if ($this->isRequired() && $default !== null) {
            throw new InvalidArgumentException('A required argument cannot have a default value.');
        }
    }

    public function isRequired(): bool
    {
        return ($this->mode & self::REQUIRED) === self::REQUIRED;
    }

    public function isArray(): bool
    {
        return ($this->mode & self::IS_ARRAY) === self::IS_ARRAY;
    }

    public function defaultValue(): mixed
    {
        return $this->default ?? ($this->isArray() ? [] : null);
    }

    /** Usage form, e.g. "<name>", "[<view>]" or "<files>...". */
    public function synopsis(): string
    {
        $text = '<' . $this->name . '>' . ($this->isArray() ? '...' : '');

        return $this->isRequired() ? $text : '[' . $text . ']';
    }
}
