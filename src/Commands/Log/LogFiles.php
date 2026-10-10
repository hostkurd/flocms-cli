<?php
declare(strict_types=1);

namespace FloCMS\CLI\Commands\Log;

use FloCMS\CLI\Console\CommandFailedException;
use FloCMS\CLI\Console\Input\InputException;
use FloCMS\CLI\Project;

/**
 * storage/logs/*.log (app.log from core, api.log, schedule.log, ...).
 */
final class LogFiles
{
    public function __construct(private readonly Project $project)
    {
    }

    public function directory(): string
    {
        return $this->project->path('storage/logs');
    }

    /** @return list<string> log file names, newest first */
    public function names(): array
    {
        $files = glob($this->directory() . DIRECTORY_SEPARATOR . '*.log') ?: [];
        usort($files, static fn (string $a, string $b): int => (int) filemtime($b) <=> (int) filemtime($a));

        return array_map('basename', $files);
    }

    /** The path of a log by name ("app", "app.log"); the newest log when null. */
    public function path(?string $name): string
    {
        if ($name === null) {
            $names = $this->names();
            if ($names === []) {
                throw new CommandFailedException('No log files in storage/logs.');
            }

            return $this->directory() . DIRECTORY_SEPARATOR . $names[0];
        }
        $name = str_ends_with($name, '.log') ? $name : $name . '.log';
        if (preg_match('/^[A-Za-z0-9._-]+\.log$/D', $name) !== 1 || str_contains($name, '..')) {
            throw new InputException('Invalid log name "' . $name . '".');
        }
        $path = $this->directory() . DIRECTORY_SEPARATOR . $name;
        if (!is_file($path)) {
            throw new CommandFailedException('storage/logs/' . $name . ' does not exist. Logs: ' . (implode(', ', $this->names()) ?: 'none') . '.');
        }

        return $path;
    }

    /**
     * The last $lines lines, read from the end (large logs are fine).
     *
     * @return list<string>
     */
    public static function tail(string $file, int $lines): array
    {
        $handle = @fopen($file, 'rb');
        if ($handle === false) {
            throw new CommandFailedException('Cannot read ' . $file . '.');
        }
        fseek($handle, 0, SEEK_END);
        $position = ftell($handle);
        $buffer = '';
        while ($position > 0 && substr_count($buffer, "\n") <= $lines) {
            $read = min(8192, $position);
            $position -= $read;
            fseek($handle, $position);
            $buffer = fread($handle, $read) . $buffer;
        }
        fclose($handle);
        $all = explode("\n", rtrim(str_replace("\r\n", "\n", $buffer), "\n"));

        return $buffer === '' ? [] : array_slice($all, -$lines);
    }
}
