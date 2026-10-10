<?php
declare(strict_types=1);

namespace FloCMS\CLI\Console\Input;

/**
 * Parses command line tokens against a Definition.
 *
 * Supported forms:
 * - long options: --force, --scopes=a,b, --scopes a,b (when a value is required)
 * - short options: -f, -fq (combined flags), -p8000, -p 8000
 * - verbosity: -v, -vv, -vvv, --verbose, --verbose=3
 * - repeatable options: --allow=1.2.3.4 --allow=10.0.0.0/8
 * - "--" ends the options; negative numbers are arguments
 */
final class ArgvParser
{
    /**
     * @param list<string> $tokens the tokens after the command name
     * @param resource|null $stream
     */
    public static function parse(array $tokens, Definition $definition, $stream = null): Input
    {
        $positional = [];
        $values = [];
        $onlyArguments = false;

        for ($i = 0, $count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];

            if ($onlyArguments || $token === '' || $token === '-' || $token[0] !== '-' || is_numeric($token)) {
                $positional[] = $token;
                continue;
            }
            if ($token === '--') {
                $onlyArguments = true;
                continue;
            }

            if (str_starts_with($token, '--')) {
                $parts = explode('=', substr($token, 2), 2);
                $option = $definition->getOption($parts[0])
                    ?? throw new InputException('The "--' . $parts[0] . '" option does not exist.' . self::hint($parts[0], $definition));
                $value = $parts[1] ?? null;
                if ($value === null && $option->requiresValue()) {
                    $next = $tokens[$i + 1] ?? null;
                    if ($next === null || ($next !== '' && $next[0] === '-' && !is_numeric($next))) {
                        throw new InputException('The "--' . $option->name . '" option requires a value.');
                    }
                    $value = $next;
                    $i++;
                }
                self::set($values, $option, $value, '--' . $option->name);
                continue;
            }

            // Short options: -f, -fq, -p8000, -p 8000, -vvv
            $letters = substr($token, 1);
            for ($j = 0, $length = strlen($letters); $j < $length; $j++) {
                $letter = $letters[$j];
                $option = $definition->optionForShortcut($letter)
                    ?? throw new InputException('The "-' . $letter . '" option does not exist.');
                if ($option->isFlag()) {
                    self::set($values, $option, null, '-' . $letter);
                    continue;
                }
                $rest = substr($letters, $j + 1);
                $value = $rest !== '' ? ltrim($rest, '=') : null;
                if ($value === null && $option->requiresValue()) {
                    $next = $tokens[$i + 1] ?? null;
                    if ($next === null || ($next !== '' && $next[0] === '-' && !is_numeric($next))) {
                        throw new InputException('The "-' . $letter . '" option requires a value.');
                    }
                    $value = $next;
                    $i++;
                }
                self::set($values, $option, $value, '-' . $letter);
                break;
            }
        }

        $arguments = [];
        $declared = $definition->arguments();
        foreach ($declared as $name => $argument) {
            if ($argument->isArray()) {
                if ($positional === [] && $argument->isRequired()) {
                    throw new InputException('Not enough arguments (missing: "' . $name . '").');
                }
                $arguments[$name] = $positional === [] ? $argument->defaultValue() : $positional;
                $positional = [];
                continue;
            }
            if ($positional !== []) {
                $arguments[$name] = array_shift($positional);
                continue;
            }
            if ($argument->isRequired()) {
                throw new InputException('Not enough arguments (missing: "' . $name . '").');
            }
            $arguments[$name] = $argument->defaultValue();
        }
        if ($positional !== []) {
            throw new InputException($declared === []
                ? 'No arguments expected, got "' . $positional[0] . '".'
                : 'Too many arguments, expected: ' . implode(' ', array_map(static fn (Argument $a): string => $a->synopsis(), $declared)) . '.');
        }

        $options = [];
        foreach ($definition->options() as $name => $option) {
            $options[$name] = array_key_exists($name, $values) ? $values[$name] : $option->defaultValue();
        }

        return new Input($arguments, $options, true, $stream);
    }

    /** @param array<string, mixed> $values */
    private static function set(array &$values, Option $option, ?string $value, string $as): void
    {
        $name = $option->name;

        // -v, -vv, -vvv, --verbose, --verbose=2: a level from 1 to 3
        if ($name === 'verbose') {
            $level = $value !== null && $value !== '' ? (int) $value : (int) ($values[$name] ?? 0) + 1;
            $values[$name] = max(0, min(3, $level));

            return;
        }

        if ($option->isFlag()) {
            if ($value !== null) {
                throw new InputException('The "' . $as . '" option does not accept a value.');
            }
            $values[$name] = true;

            return;
        }

        $value ??= true;
        if ($option->isArray()) {
            $values[$name] ??= [];
            $values[$name][] = $value;

            return;
        }
        $values[$name] = $value;
    }

    private static function hint(string $name, Definition $definition): string
    {
        $best = null;
        $bestDistance = PHP_INT_MAX;
        foreach (array_keys($definition->options()) as $candidate) {
            $distance = levenshtein($name, $candidate);
            if ($distance < $bestDistance && ($distance <= max(1, intdiv(strlen($name), 3)) || str_starts_with($candidate, $name))) {
                $best = $candidate;
                $bestDistance = $distance;
            }
        }

        return $best === null ? '' : ' Did you mean "--' . $best . '"?';
    }
}
