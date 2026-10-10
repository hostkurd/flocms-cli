<?php
declare(strict_types=1);

namespace FloCMS\CLI\Console\Input;

use InvalidArgumentException;

/**
 * Invalid command usage: unknown option, missing argument, bad value.
 * The application prints the message and the usage line and exits with 2.
 */
class InputException extends InvalidArgumentException
{
}
