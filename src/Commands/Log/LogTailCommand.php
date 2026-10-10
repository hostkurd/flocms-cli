<?php
declare(strict_types=1);

namespace FloCMS\CLI\Commands\Log;

use FloCMS\CLI\Console\Command;
use FloCMS\CLI\Console\Input\Argument;
use FloCMS\CLI\Console\Input\Definition;
use FloCMS\CLI\Console\Input\Option;
use FloCMS\CLI\Console\Output\Formatter;

final class LogTailCommand extends Command
{
    protected string $name = 'log:tail';

    protected string $description = 'Show the end of a log in storage/logs';

    protected array $examples = [
        'php flo log:tail',
        'php flo log:tail api --lines=100',
        'php flo log:tail app --follow',
    ];

    protected function configure(Definition $definition): void
    {
        $definition
            ->argument('log', Argument::OPTIONAL, 'The log name (app, api, schedule); default: the newest log')
            ->option('lines', 'l', Option::VALUE_REQUIRED, 'How many lines', '50')
            ->option('follow', 'f', Option::VALUE_NONE, 'Keep printing new lines (Ctrl+C to stop)');
    }

    protected function handle(): int
    {
        if (!ctype_digit((string) $this->option('lines')) || (int) $this->option('lines') < 1) {
            $this->invalid('--lines must be a positive number.');
        }
        $files = new LogFiles($this->project());
        $path = $files->path($this->argument('log'));
        $this->output->diagnostic('<comment>==> ' . Formatter::escape($this->project()->relative($path)) . ' <==</comment>');
        foreach (LogFiles::tail($path, (int) $this->option('lines')) as $line) {
            $this->line(self::highlight($line));
        }
        if (!$this->option('follow')) {
            return self::SUCCESS;
        }

        $size = (int) filesize($path);
        while (true) {
            clearstatcache(true, $path);
            $current = (int) @filesize($path);
            if ($current < $size) {
                $size = 0; // rotated or cleared
            }
            if ($current > $size) {
                $handle = fopen($path, 'rb');
                fseek($handle, $size);
                $chunk = (string) stream_get_contents($handle);
                fclose($handle);
                foreach (explode("\n", rtrim($chunk, "\n")) as $line) {
                    $this->line(self::highlight($line));
                }
                $size = $current;
            }
            usleep(500000);
        }
    }

    /** Color the level of "[date] level: message" lines (core's Logger format). */
    private static function highlight(string $line): string
    {
        $line = Formatter::escape($line);

        return (string) preg_replace_callback('/^(\[[^\]]+\]) (\w+):/', static function (array $m): string {
            $style = match (strtolower($m[2])) {
                'emergency', 'alert', 'critical', 'error' => 'error',
                'warning' => 'comment',
                default => 'info',
            };

            return '<muted>' . $m[1] . '</muted> <' . $style . '>' . $m[2] . '</' . $style . '>:';
        }, $line);
    }
}
