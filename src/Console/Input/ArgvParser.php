<?php
declare(strict_types=1);

namespace FloCMS\CLI\Console\Input;

/**
 * Parses command line tokens against a Definition.
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
        $options = [];

        foreach ($tokens as $token) {
            if (preg_match('/^--([A-Za-z0-9][A-Za-z0-9_-]*)(?:=(.*))?$/s', $token, $matches) === 1) {
                $name = $matches[1];
                $option = $definition->getOption($name)
                    ?? throw new InputException('The "--' . $name . '" option does not exist.');
                $options[$name] = array_key_exists(2, $matches) ? $matches[2] : true;
                if ($option->isFlag()) {
                    $options[$name] = true;
                }
                continue;
            }
            $positional[] = $token;
        }

        $arguments = [];
        foreach ($definition->arguments() as $name => $argument) {
            if ($argument->isArray()) {
                $arguments[$name] = $positional;
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

        $values = [];
        foreach ($definition->options() as $name => $option) {
            $values[$name] = $options[$name] ?? $option->defaultValue();
        }

        return new Input($arguments, $values, true, $stream);
    }
}
