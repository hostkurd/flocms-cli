<?php
declare(strict_types=1);

namespace FloCMS\CLI\Console\Input;

use InvalidArgumentException;

/**
 * A named option: a flag (--force), an option with a required or optional
 * value (--scopes=a,b), optionally repeatable (--allow=a --allow=b), with an
 * optional one-letter shortcut (-f).
 */
final class Option
{
    /** A flag without a value. */
    public const VALUE_NONE = 1;
    public const VALUE_REQUIRED = 2;
    public const VALUE_OPTIONAL = 4;
    /** Repeatable; values are collected in a list. Combine with VALUE_REQUIRED. */
    public const VALUE_IS_ARRAY = 8;

    public readonly ?string $shortcut;

    public function __construct(
        public readonly string $name,
        ?string $shortcut = null,
        public readonly int $mode = self::VALUE_NONE,
        public readonly string $description = '',
        public readonly mixed $default = null
    ) {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*$/', $name) !== 1) {
            throw new InvalidArgumentException('Invalid option name "' . $name . '".');
        }
        $shortcut = $shortcut === null || $shortcut === '' ? null : ltrim($shortcut, '-');
        if ($shortcut !== null && preg_match('/^[A-Za-z]$/', $shortcut) !== 1) {
            throw new InvalidArgumentException('An option shortcut must be one letter, "' . $shortcut . '" given.');
        }
        if ($this->isFlag() && $default !== null) {
            throw new InvalidArgumentException('A flag option (--' . $name . ') cannot have a default value.');
        }
        $this->shortcut = $shortcut;
    }

    public function isFlag(): bool
    {
        return ($this->mode & self::VALUE_NONE) === self::VALUE_NONE;
    }

    public function acceptsValue(): bool
    {
        return !$this->isFlag();
    }

    public function requiresValue(): bool
    {
        return ($this->mode & self::VALUE_REQUIRED) === self::VALUE_REQUIRED;
    }

    public function isArray(): bool
    {
        return ($this->mode & self::VALUE_IS_ARRAY) === self::VALUE_IS_ARRAY;
    }

    public function defaultValue(): mixed
    {
        if ($this->isFlag()) {
            return false;
        }

        return $this->default ?? ($this->isArray() ? [] : null);
    }

    /** Usage form, e.g. "-f, --force" or "    --scopes=SCOPES". */
    public function synopsis(): string
    {
        $text = ($this->shortcut !== null ? '-' . $this->shortcut . ', ' : '    ') . '--' . $this->name;
        if ($this->acceptsValue()) {
            $value = strtoupper(str_replace('-', '_', $this->name));
            $text .= $this->requiresValue() ? '=' . $value : '[=' . $value . ']';
        }

        return $text;
    }
}
