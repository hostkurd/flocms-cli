<?php
declare(strict_types=1);

namespace FloCMS\CLI\Console;

use FloCMS\CLI\Console\Output\Formatter;
use FloCMS\CLI\Console\Output\Output;
use Throwable;

/**
 * Prints errors on STDERR as a readable block. The exception class, file and
 * line are shown with -v; the stack trace with -vvv or APP_DEBUG=true.
 */
final class ErrorRenderer
{
    public static function render(Throwable $error, Output $output, bool $expected = false): void
    {
        $lines = preg_split('/\R/', trim($error->getMessage())) ?: [''];
        if ($lines === [''] || $lines === []) {
            $lines = [get_class($error)];
        }

        $output->error('');
        $output->error(self::block('ERROR', array_map([Formatter::class, 'escape'], $lines), $output->isErrorDecorated()));

        $debug = $output->isDebug() || self::appDebug();
        if (!$expected && ($output->isVerbose() || $debug)) {
            $output->error('  ' . get_class($error) . ' in ' . $error->getFile() . ':' . $error->getLine());
        }
        if ($debug) {
            $output->error('');
            foreach (explode("\n", $error->getTraceAsString()) as $frame) {
                $output->error('  ' . $frame);
            }
            $previous = $error->getPrevious();
            while ($previous !== null) {
                $output->error('');
                $output->error('  Caused by ' . get_class($previous) . ': ' . $previous->getMessage());
                $output->error('  in ' . $previous->getFile() . ':' . $previous->getLine());
                $previous = $previous->getPrevious();
            }
        } elseif (!$expected && !$output->isVerbose()) {
            $output->error('  Run with -v for details, or -vvv for the stack trace.');
        }
        $output->error('');
    }

    /**
     * " [ERROR] message" lines; in color a full-width red block.
     *
     * @param list<string> $lines
     */
    public static function block(string $label, array $lines, bool $decorated = false): string
    {
        $prefix = ' [' . $label . '] ';
        $indent = str_repeat(' ', strlen($prefix));
        $text = [];
        foreach ($lines as $index => $line) {
            $text[] = ($index === 0 ? $prefix : $indent) . $line;
        }
        if (!$decorated) {
            return implode("\n", $text);
        }
        $width = max(array_map([Formatter::class, 'width'], $text)) + 1;

        return implode("\n", array_map(
            static fn (string $line): string => '<error>' . $line . str_repeat(' ', $width - Formatter::width($line)) . '</error>',
            $text
        ));
    }

    private static function appDebug(): bool
    {
        $value = $_ENV['APP_DEBUG'] ?? $_SERVER['APP_DEBUG'] ?? getenv('APP_DEBUG');

        return is_string($value) ? in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true) : $value === true;
    }
}
